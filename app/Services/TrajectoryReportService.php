<?php

namespace App\Services;

use App\Models\AccountBalanceSnapshot;
use App\Models\DebtBalanceSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes every figure shown on the financial trajectory report.
 *
 * Each public method takes the user explicitly — the service holds no per-user state.
 * When the underlying data is missing a method returns ['available' => false, 'reason' => '…']
 * rather than zeros that would read as a real (and alarming) result.
 */
class TrajectoryReportService
{
    /**
     * @return array<string, mixed>
     */
    public function netWorth(User $user): array
    {
        $linkedCreditIds = $user->accounts()
            ->where('type', 'credit')
            ->whereHas('linkedDebt')
            ->pluck('id');

        $accountTotal = round((float) $user->accounts()->whereNotIn('id', $linkedCreditIds)->sum('balance'), 2);
        $debtTotal = round((float) $user->debts()->sum('balance'), 2);
        $current = round($accountTotal - $debtTotal, 2);

        $windowStart = Carbon::now()->startOfMonth()->subMonths(11);

        $accountByMonth = $this->lastBalancePerMonth(
            AccountBalanceSnapshot::where('user_id', $user->id)
                ->whereNotIn('account_id', $linkedCreditIds)
                ->where('snapshot_date', '>=', $windowStart->toDateString())
                ->orderBy('snapshot_date')
                ->get(),
            'account_id',
        );

        $debtByMonth = $this->lastBalancePerMonth(
            DebtBalanceSnapshot::where('user_id', $user->id)
                ->where('snapshot_date', '>=', $windowStart->toDateString())
                ->orderBy('snapshot_date')
                ->get(),
            'debt_id',
        );

        $trend = [];

        foreach ($this->monthWindow(12) as $month) {
            $key = $month->format('Y-m');

            if (! array_key_exists($key, $accountByMonth)) {
                continue;
            }

            $trend[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'value' => round($accountByMonth[$key] - ($debtByMonth[$key] ?? 0.0), 2),
            ];
        }

        $headline = [
            'current' => $current,
            'accountTotal' => $accountTotal,
            'debtTotal' => $debtTotal,
        ];

        if (count($trend) < 2) {
            return [
                'available' => false,
                'reason' => 'Not enough balance history yet — net worth trend needs snapshots from at least two months.',
                ...$headline,
                'trend' => [],
            ];
        }

        $first = $trend[0]['value'];
        $last = $trend[count($trend) - 1]['value'];

        return [
            'available' => true,
            ...$headline,
            'trend' => $trend,
            'changeAmount' => round($last - $first, 2),
            'changePct' => $first != 0.0 ? round(($last - $first) / abs($first) * 100, 1) : null,
        ];
    }

    /**
     * The last `$count` calendar months, oldest first, including the current month.
     *
     * @return list<Carbon>
     */
    protected function monthWindow(int $count): array
    {
        $months = [];

        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $months[] = Carbon::now()->startOfMonth()->subMonths($offset);
        }

        return $months;
    }

    /**
     * Sum of each owner's latest snapshot within each calendar month.
     *
     * @param  Collection<int, AccountBalanceSnapshot|DebtBalanceSnapshot>  $snapshots
     * @param  'account_id'|'debt_id'  $ownerKey
     * @return array<string, float>
     */
    private function lastBalancePerMonth(Collection $snapshots, string $ownerKey): array
    {
        $latest = [];

        foreach ($snapshots as $snapshot) {
            $month = $snapshot->snapshot_date->format('Y-m');
            $owner = $snapshot->{$ownerKey};
            $date = $snapshot->snapshot_date->toDateString();

            if (! isset($latest[$month][$owner]) || $latest[$month][$owner]['date'] < $date) {
                $latest[$month][$owner] = ['date' => $date, 'balance' => (float) $snapshot->balance];
            }
        }

        $totals = [];

        foreach ($latest as $month => $owners) {
            $totals[$month] = round(array_sum(array_column($owners, 'balance')), 2);
        }

        return $totals;
    }
}
