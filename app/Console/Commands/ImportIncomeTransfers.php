<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\User;
use Illuminate\Console\Command;
use PDO;

class ImportIncomeTransfers extends Command
{
    protected $signature = 'app:import-income-transfers';

    protected $description = 'One-time import of income and transfer transactions from the old life-dashboard app';

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

        // Build account name → Habitat id map
        $accountMap = Account::where('user_id', $user->id)
            ->pluck('id', 'name')
            ->all();

        $this->info("User: {$user->name} ({$user->email})");
        $this->line('Mapped accounts: '.implode(', ', array_keys($accountMap)));
        $this->newLine();

        // ── Income ────────────────────────────────────────────────────────────
        $this->info('Importing income transactions...');
        $incomeRows = $oldDb->query("
            SELECT t.id, t.date, t.amount, t.note, t.account_id, is_src.name AS source_name
            FROM transactions t
            JOIN income_sources is_src ON t.income_source_id = is_src.id
            WHERE t.type = 'income'
            ORDER BY t.date
        ")->fetchAll();

        $imported = 0;
        $skipped = 0;
        foreach ($incomeRows as $row) {
            $oldAccountName = $this->resolveOldAccountName($oldDb, $row['account_id']);

            if (! isset($accountMap[$oldAccountName])) {
                $this->warn("  SKIP income id={$row['id']} — unknown account '{$oldAccountName}'");
                $skipped++;

                continue;
            }

            // merchant = note if set, else income source name
            $merchant = $row['note'] ?: $row['source_name'];

            $user->transactions()->create([
                'type' => 'income',
                'account_id' => $accountMap[$oldAccountName],
                'to_account_id' => null,
                'budget_category_id' => null,
                'amount' => $row['amount'],
                'merchant' => $merchant,
                'transacted_at' => $row['date'],
            ]);

            $this->line('  ₱'.number_format($row['amount'], 2)." from {$merchant} → {$oldAccountName} ({$row['date']})");
            $imported++;
        }

        $this->info("  Imported {$imported} income".($skipped ? ", skipped {$skipped}" : ''));
        $this->newLine();

        // ── Transfers ─────────────────────────────────────────────────────────
        $this->info('Importing transfer transactions...');
        $transferRows = $oldDb->query("
            SELECT t.id, t.date, t.amount, t.note, t.account_id, t.to_account_id
            FROM transactions t
            WHERE t.type = 'transfer'
            ORDER BY t.date
        ")->fetchAll();

        $importedT = 0;
        $skippedT = 0;
        foreach ($transferRows as $row) {
            $fromName = $this->resolveOldAccountName($oldDb, $row['account_id']);
            $toName = $this->resolveOldAccountName($oldDb, $row['to_account_id']);

            if (! isset($accountMap[$fromName]) || ! isset($accountMap[$toName])) {
                $this->warn("  SKIP transfer id={$row['id']} — unknown account(s) '{$fromName}' / '{$toName}'");
                $skippedT++;

                continue;
            }

            $user->transactions()->create([
                'type' => 'transfer',
                'account_id' => $accountMap[$fromName],
                'to_account_id' => $accountMap[$toName],
                'budget_category_id' => null,
                'amount' => $row['amount'],
                'merchant' => $row['note'] ?: null,
                'transacted_at' => $row['date'],
            ]);

            $this->line('  ₱'.number_format($row['amount'], 2)." {$fromName} → {$toName} ({$row['date']})");
            $importedT++;
        }

        $this->info("  Imported {$importedT} transfers".($skippedT ? ", skipped {$skippedT}" : ''));
        $this->newLine();
        $this->info('Done. Balances were NOT adjusted — they were already set to the final computed value.');

        return self::SUCCESS;
    }

    private function resolveOldAccountName(PDO $db, int $accountId): string
    {
        $stmt = $db->prepare('SELECT name FROM accounts WHERE id = ?');
        $stmt->execute([$accountId]);

        return (string) ($stmt->fetchColumn() ?? '');
    }
}
