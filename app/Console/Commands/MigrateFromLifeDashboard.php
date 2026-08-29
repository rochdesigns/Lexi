<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Console\Command;
use PDO;

class MigrateFromLifeDashboard extends Command
{
    protected $signature = 'app:migrate-from-life-dashboard';

    protected $description = 'One-time import of accounts, categories, and expense transactions from the old life-dashboard app';

    private const OLD_DB = '/Users/elle/Herd/life-dashboard/data/budget.db';

    public function handle(): int
    {
        if (! file_exists(self::OLD_DB)) {
            $this->error('Old database not found at '.self::OLD_DB);

            return self::FAILURE;
        }

        $user = User::findOrFail(1);
        $oldDb = new PDO('sqlite:'.self::OLD_DB);
        $oldDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $this->info("Migrating to user: {$user->name} ({$user->email})");

        // ── Clear existing data ──────────────────────────────────────────────
        $this->warn('Clearing existing accounts, categories, and transactions...');
        Transaction::where('user_id', $user->id)->delete();
        BudgetCategory::where('user_id', $user->id)->delete();
        Account::where('user_id', $user->id)->delete();

        // ── Accounts ─────────────────────────────────────────────────────────
        $this->info('Migrating accounts...');
        $oldAccounts = $oldDb->query('SELECT * FROM accounts WHERE archived=0')->fetchAll();
        $accountMap = [];

        foreach ($oldAccounts as $acc) {
            $balance = $this->computeBalance($oldDb, $acc);
            $type = $acc['is_reserve'] ? 'investment'
                : (str_contains(strtolower($acc['name']), 'credit') ? 'credit' : 'cash');

            $newAccount = $user->accounts()->create([
                'name' => $acc['name'],
                'institution' => null,
                'last4' => null,
                'balance' => round($balance, 2),
                'type' => $type,
            ]);

            $accountMap[$acc['id']] = $newAccount->id;
            $this->line("  [{$type}] {$acc['name']}: ₱".number_format($balance, 2));
        }

        // ── Budget categories ─────────────────────────────────────────────────
        $this->info('Migrating categories...');
        $leafCats = $oldDb->query('
            SELECT c.id, c.name, p.name AS group_name
            FROM categories c
            JOIN categories p ON p.id = c.parent_id
            WHERE c.archived = 0 AND c.parent_id IS NOT NULL
            ORDER BY p.name, c.sort_order
        ')->fetchAll();

        $limits = [];
        foreach ($oldDb->query('
            SELECT bp.category_id, bp.planned_amount
            FROM budget_plan bp
            WHERE bp.month = (SELECT MAX(month) FROM budget_plan WHERE category_id = bp.category_id)
            GROUP BY bp.category_id
        ')->fetchAll() as $row) {
            $limits[$row['category_id']] = (float) $row['planned_amount'];
        }

        $catMap = [];
        foreach ($leafCats as $cat) {
            $groupLabel = $this->stripEmoji($cat['group_name']);
            $newCat = $user->budgetCategories()->create([
                'name' => $cat['name'],
                'group' => $groupLabel,
                'icon' => $this->resolveIcon($cat['name'], $groupLabel),
                'color' => $this->resolveColor($groupLabel),
                'limit_amount' => $limits[$cat['id']] ?? 0,
            ]);
            $catMap[$cat['id']] = $newCat->id;
            $this->line("  [{$groupLabel}] {$cat['name']}");
        }

        // ── Expense transactions ──────────────────────────────────────────────
        $this->info('Migrating expense transactions...');
        $expenses = $oldDb->query("
            SELECT id, date, amount, account_id, category_id, note
            FROM transactions
            WHERE type = 'expense'
            ORDER BY date
        ")->fetchAll();

        $skipped = 0;
        $imported = 0;
        foreach ($expenses as $tx) {
            if (! isset($accountMap[$tx['account_id']]) || ! isset($catMap[$tx['category_id']])) {
                $skipped++;

                continue;
            }

            // Insert directly — balances are already set to the final computed value
            // so we must not adjust them here.
            $user->transactions()->create([
                'account_id' => $accountMap[$tx['account_id']],
                'budget_category_id' => $catMap[$tx['category_id']],
                'amount' => $tx['amount'],
                'merchant' => $tx['note'] ?: null,
                'transacted_at' => $tx['date'],
            ]);
            $imported++;
        }

        $this->info("  Imported {$imported} transactions".($skipped ? ", skipped {$skipped}" : ''));

        $this->newLine();
        $this->info('Migration complete.');
        $this->warn('Note: income, transfer, and contribution transactions were not migrated (no equivalent in Lexi).');

        return self::SUCCESS;
    }

    private function computeBalance(PDO $db, array $acc): float
    {
        $stmt = $db->prepare("
            SELECT
              SUM(CASE WHEN type = 'income' AND account_id = :a1 THEN amount ELSE 0 END) +
              SUM(CASE WHEN type IN ('expense', 'transfer', 'contribution') AND account_id = :a2 THEN -amount ELSE 0 END) +
              SUM(CASE WHEN type = 'transfer' AND to_account_id = :a3 THEN amount ELSE 0 END)
            FROM transactions
        ");
        $stmt->execute([':a1' => $acc['id'], ':a2' => $acc['id'], ':a3' => $acc['id']]);
        $delta = (float) ($stmt->fetchColumn() ?? 0);

        return $acc['opening_balance'] + $delta;
    }

    private function stripEmoji(string $text): string
    {
        return trim(preg_replace('/^[\x{1F300}-\x{1FAFF}\x{2600}-\x{27BF}\x{FE00}-\x{FEFF}]+\s*/u', '', $text));
    }

    private function resolveIcon(string $name, string $group): string
    {
        $n = strtolower($name);
        $g = strtolower($group);

        return match (true) {
            str_contains($g, 'bebe') || str_contains($g, 'pet') => match (true) {
                str_contains($n, 'vet') => 'local_pharmacy',
                str_contains($n, 'groom') => 'spa',
                default => 'pets',
            },
            str_contains($g, 'food') => match (true) {
                str_contains($n, 'grocer') => 'shopping_cart',
                str_contains($n, 'take out') || str_contains($n, 'takeout') => 'coffee',
                default => 'restaurant',
            },
            str_contains($g, 'household') => match (true) {
                str_contains($n, 'electric') => 'bolt',
                str_contains($n, 'transport') => 'directions_car',
                str_contains($n, 'internet') || str_contains($n, 'load') => 'phone_iphone',
                default => 'home',
            },
            str_contains($g, 'personal') => match (true) {
                str_contains($n, 'cloth') => 'checkroom',
                str_contains($n, 'medicine') || str_contains($n, 'medic') => 'local_pharmacy',
                str_contains($n, 'gift') || str_contains($n, 'ninang') || str_contains($n, 'nanay') => 'card_giftcard',
                str_contains($n, 'manicure') || str_contains($n, 'wax') || str_contains($n, 'massage') => 'spa',
                str_contains($n, 'hobbie') || str_contains($n, 'hobby') => 'sports_esports',
                default => 'favorite',
            },
            str_contains($g, 'subscription') => match (true) {
                str_contains($n, 'insurance') => 'favorite',
                default => 'subscriptions',
            },
            default => 'shopping_cart',
        };
    }

    private function resolveColor(string $group): string
    {
        $g = strtolower($group);

        return match (true) {
            str_contains($g, 'bebe') || str_contains($g, 'pet') => '#d98a2b',
            str_contains($g, 'food') => '#c0512c',
            str_contains($g, 'household') => '#2f7d5b',
            str_contains($g, 'personal') => '#a85fc0',
            str_contains($g, 'subscription') => '#3f7fb0',
            default => '#6b6355',
        };
    }
}
