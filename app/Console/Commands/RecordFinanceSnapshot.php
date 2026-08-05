<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\DebtPayment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RecordFinanceSnapshot extends Command
{
    protected $signature = 'finance:snapshot
                            {--date= : The date to snapshot (Y-m-d). Defaults to today}
                            {--backfill= : Reconstruct and write the previous N days of history instead of a single day}';

    protected $description = 'Record a daily balance snapshot for every account and debt';

    public function handle(): int
    {
        $date = $this->option('date') !== null
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today();

        $backfill = $this->option('backfill') !== null ? max(1, (int) $this->option('backfill')) : null;

        User::query()->each(function (User $user) use ($date, $backfill): void {
            if ($backfill !== null) {
                $this->backfillAccounts($user, $backfill, $date);
                $this->backfillDebts($user, $backfill, $date);

                return;
            }

            $this->recordDay($user, $date);
        });

        return self::SUCCESS;
    }

    private function recordDay(User $user, Carbon $date): void
    {
        foreach ($user->accounts()->get() as $account) {
            AccountBalanceSnapshot::updateOrCreate(
                ['account_id' => $account->id, 'snapshot_date' => $date],
                ['user_id' => $user->id, 'balance' => round((float) $account->balance, 2)],
            );
        }

        foreach ($user->debts()->get() as $debt) {
            DebtBalanceSnapshot::updateOrCreate(
                ['debt_id' => $debt->id, 'snapshot_date' => $date],
                ['user_id' => $user->id, 'balance' => round((float) $debt->balance, 2)],
            );
        }
    }

    private function backfillAccounts(User $user, int $days, Carbon $today): void
    {
        $earliest = $today->copy()->subDays($days - 1);

        foreach ($user->accounts()->get() as $account) {
            $effects = $this->dailyAccountEffects($user, $account);
            $running = round((float) $account->balance, 2);

            for ($cursor = $today->copy(); $cursor->gte($earliest); $cursor->subDay()) {
                AccountBalanceSnapshot::firstOrCreate(
                    ['account_id' => $account->id, 'snapshot_date' => $cursor->copy()],
                    ['user_id' => $user->id, 'balance' => $running],
                );

                $running = round($running - ($effects[$cursor->toDateString()] ?? 0.0), 2);
            }
        }
    }

    /**
     * Signed effect each day had on this account's balance.
     *
     * Mirrors TransactionController::applyEffect() exactly, including its credit-account
     * convention: a credit balance is money owed, so an expense increases it and income
     * (a payment toward the card) decreases it.
     *
     * @return array<string, float>
     */
    private function dailyAccountEffects(User $user, Account $account): array
    {
        $effects = [];

        $transactions = $user->transactions()
            ->where(fn ($query) => $query->where('account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->get(['type', 'amount', 'account_id', 'to_account_id', 'transacted_at']);

        foreach ($transactions as $transaction) {
            $date = $transaction->transacted_at->toDateString();
            $amount = (float) $transaction->amount;
            $effect = 0.0;

            if ($transaction->account_id === $account->id) {
                $effect += match ($transaction->type) {
                    'income' => $account->type === 'credit' ? -$amount : $amount,
                    'transfer' => -$amount,
                    default => $account->type === 'credit' ? $amount : -$amount,
                };
            }

            if ($transaction->to_account_id === $account->id) {
                $effect += $amount;
            }

            $effects[$date] = round(($effects[$date] ?? 0.0) + $effect, 2);
        }

        // A debt payment against a card-linked debt reduces the card's balance directly
        // (DebtController::makePayment) without a transaction row pointing at the card,
        // so it has to be folded in separately.
        $linkedDebtId = $account->linkedDebt()->value('id');

        if ($linkedDebtId !== null) {
            $payments = DebtPayment::where('debt_id', $linkedDebtId)->get(['amount', 'paid_at']);

            foreach ($payments as $payment) {
                $date = $payment->paid_at->toDateString();
                $effects[$date] = round(($effects[$date] ?? 0.0) - (float) $payment->amount, 2);
            }
        }

        return $effects;
    }

    private function backfillDebts(User $user, int $days, Carbon $today): void
    {
        $earliest = $today->copy()->subDays($days - 1);

        foreach ($user->debts()->get() as $debt) {
            $effects = $this->dailyDebtEffects($user, $debt);
            $running = round((float) $debt->balance, 2);

            for ($cursor = $today->copy(); $cursor->gte($earliest); $cursor->subDay()) {
                DebtBalanceSnapshot::firstOrCreate(
                    ['debt_id' => $debt->id, 'snapshot_date' => $cursor->copy()],
                    ['user_id' => $user->id, 'balance' => $running],
                );

                $running = round($running - ($effects[$cursor->toDateString()] ?? 0.0), 2);
            }
        }
    }

    /**
     * Signed effect each day had on this debt's outstanding balance.
     *
     * Anchored on the live balance and walked backward, exactly like backfillAccounts():
     * the current balance is known-true, so every earlier day is derived from it by
     * reversing that day's recorded movement. Nothing is inferred from `original` or
     * `created_at`, and there is no reconciliation gate to fail.
     *
     * Two shapes of debt, matching how DebtController::makePayment() moves the balance:
     *  - Card-linked: `debts.balance` mirrors the credit account (Account::syncLinkedDebt),
     *    so the account's own daily effects are the debt's daily effects.
     *  - Standalone: only the principal portion of a payment reduces what is owed. The
     *    interest portion is already carved out of `principal_amount` when the payment is
     *    recorded, so reversing principal alone is exact at every interest rate.
     *
     * @return array<string, float>
     */
    private function dailyDebtEffects(User $user, Debt $debt): array
    {
        if ($debt->account_id !== null) {
            $account = $debt->account;

            return $account !== null ? $this->dailyAccountEffects($user, $account) : [];
        }

        $effects = [];

        foreach ($debt->debtPayments()->get(['principal_amount', 'paid_at']) as $payment) {
            $date = $payment->paid_at->toDateString();
            $effects[$date] = round(($effects[$date] ?? 0.0) - (float) $payment->principal_amount, 2);
        }

        return $effects;
    }
}
