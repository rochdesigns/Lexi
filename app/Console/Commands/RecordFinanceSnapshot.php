<?php

namespace App\Console\Commands;

use App\Models\AccountBalanceSnapshot;
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

        User::query()->each(function (User $user) use ($date): void {
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
}
