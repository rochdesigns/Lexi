<?php

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
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
     * Signed effect each dated transaction had on this account's balance.
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
                $effect += $transaction->type === 'income' ? $amount : -$amount;
            }

            if ($transaction->to_account_id === $account->id) {
                $effect += $amount;
            }

            $effects[$date] = round(($effects[$date] ?? 0.0) + $effect, 2);
        }

        return $effects;
    }

    private function backfillDebts(User $user, int $days, Carbon $today): void
    {
        $earliest = $today->copy()->subDays($days - 1);

        foreach ($user->debts()->get() as $debt) {
            $history = $this->reconstructDebtHistory($debt, $today);
            $final = $history[$today->toDateString()] ?? null;

            if ($final === null || abs($final - (float) $debt->balance) > 1.00) {
                $this->warn("Skipping debt #{$debt->id} ({$debt->name}): reconstructed balance does not reconcile with the current balance.");

                continue;
            }

            for ($cursor = $earliest->copy(); $cursor->lte($today); $cursor->addDay()) {
                $date = $cursor->toDateString();

                if (! array_key_exists($date, $history)) {
                    continue;
                }

                DebtBalanceSnapshot::firstOrCreate(
                    ['debt_id' => $debt->id, 'snapshot_date' => $cursor->copy()],
                    ['user_id' => $user->id, 'balance' => $history[$date]],
                );
            }
        }
    }

    /**
     * Walk forward from the debt's original amount, compounding interest on each monthly
     * anniversary of its creation date and subtracting recorded payments on their paid_at.
     *
     * @return array<string, float>
     */
    private function reconstructDebtHistory(Debt $debt, Carbon $today): array
    {
        $monthlyRate = $debt->interest_rate === null ? 0.0
            : ($debt->interest_rate_period === 'monthly'
                ? (float) $debt->interest_rate / 100
                : (float) $debt->interest_rate / 100 / 12);

        $start = Carbon::parse($debt->created_at)->startOfDay();

        if ($start->gt($today)) {
            return [];
        }

        $paymentsByDate = $debt->debtPayments()
            ->get(['amount', 'paid_at'])
            ->groupBy(fn ($payment) => $payment->paid_at->toDateString())
            ->map(fn ($group) => (float) $group->sum('amount'));

        $balance = (float) $debt->original;
        $anniversaryDay = $start->day;
        $history = [];

        for ($cursor = $start->copy(); $cursor->lte($today); $cursor->addDay()) {
            if ($monthlyRate > 0 && ! $cursor->isSameDay($start) && $cursor->day === $anniversaryDay) {
                $balance *= (1 + $monthlyRate);
            }

            $balance = max(0, $balance - ($paymentsByDate[$cursor->toDateString()] ?? 0.0));

            $history[$cursor->toDateString()] = round($balance, 2);
        }

        return $history;
    }
}
