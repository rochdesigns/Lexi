<?php

namespace App\Services;

use App\Contracts\Envelopable;
use App\Contracts\Forecastable;
use App\Models\User;
use App\Support\CyclePeriod;
use Illuminate\Support\Collection;

/**
 * Builds a 12-period-ahead financial projection for the /forecast page, reusing
 * BudgetCycle for period boundaries and TrajectoryReportService's debt payoff
 * simulation for when a debt's row should drop to zero.
 */
class MonthlyForecastService
{
    private const PERIOD_COUNT = 12;

    private const AVERAGE_WINDOW = 3;

    /**
     * Temporary fallback for projected income while a user has no completed-cycle history
     * to average yet (the average would otherwise read as ₱0, understating every future
     * period). Self-reverts: once real history accumulates, the average takes over.
     */
    private const DEFAULT_PROJECTED_INCOME = 225000.0;

    public function __construct(private TrajectoryReportService $trajectory) {}

    /** @return array<string, mixed> */
    public function build(User $user): array
    {
        $budgetCycle = new BudgetCycle($user->setting);
        $current = $budgetCycle->current();

        $periods = [$current];
        for ($i = 1; $i < self::PERIOD_COUNT; $i++) {
            $periods[] = $budgetCycle->next($periods[$i - 1]);
        }

        $avgIncome = $this->averagedIncome($user, $budgetCycle, $current);

        $incomeAmounts = array_fill(0, self::PERIOD_COUNT, $avgIncome);
        $incomeAmounts[0] = round($this->sumTransactions($user, $current, 'income'), 2);

        // Future periods use planned category budgets rather than a spending average, since a
        // budget limit is a forward-looking commitment while an average is backward-looking.
        // Categories already linked to a bill or a debt are excluded so they aren't double
        // counted against the Bills and Debt rows.
        $everydayAmounts = array_fill(0, self::PERIOD_COUNT, $this->nonBillAndDebtCategoryLimitTotal($user));
        $everydayAmounts[0] = round($this->sumEverydayExpenses($user, $current), 2);

        $debtGroup = $this->debtGroup($user, $periods);
        $setAsideGroup = $this->setAsideGroup($user, $periods);
        $billsGroup = $this->billsGroup($user, $periods);

        $netLeftover = [];
        for ($i = 0; $i < self::PERIOD_COUNT; $i++) {
            $netLeftover[$i] = round(
                $incomeAmounts[$i] - $everydayAmounts[$i]
                    - $debtGroup['subtotal'][$i] - $setAsideGroup['subtotal'][$i] - $billsGroup['subtotal'][$i],
                2
            );
        }

        // Sinking funds have no account of their own -- their "saved" total already sits inside
        // the account balances summed here, earmarked for a goal, so it's excluded to reflect
        // truly uncommitted cash rather than total cash on hand.
        $startingBalance = round(
            (float) $user->accounts()->sum('balance') - (float) $user->sinkingFunds()->sum('saved'),
            2
        );
        $runningBalance = [];
        $running = $startingBalance;
        for ($i = 0; $i < self::PERIOD_COUNT; $i++) {
            $running = round($running + $netLeftover[$i], 2);
            $runningBalance[$i] = $running;
        }

        return [
            'startingBalance' => $startingBalance,
            'periods' => array_map(fn (CyclePeriod $period, int $i) => [
                'key' => $period->start->toDateString(),
                'label' => $period->label(),
                'isCurrent' => $i === 0,
            ], $periods, array_keys($periods)),
            'incomeRow' => ['label' => 'Projected income', 'amounts' => $incomeAmounts],
            'everydayExpensesRow' => ['label' => 'Everyday expenses', 'amounts' => $everydayAmounts],
            'expenseGroups' => [
                ['key' => 'debt', 'label' => 'Debt', 'items' => $debtGroup['items'], 'subtotal' => $debtGroup['subtotal']],
                ['key' => 'set_asides', 'label' => 'Set-asides', 'items' => $setAsideGroup['items'], 'subtotal' => $setAsideGroup['subtotal']],
                ['key' => 'bills', 'label' => 'Bills', 'subgroups' => $billsGroup['subgroups'], 'subtotal' => $billsGroup['subtotal']],
            ],
            'netLeftover' => $netLeftover,
            'runningBalance' => $runningBalance,
        ];
    }

    /**
     * Averages income actuals over the last AVERAGE_WINDOW *completed* cycles (never the
     * in-progress current one).
     */
    private function averagedIncome(User $user, BudgetCycle $budgetCycle, CyclePeriod $current): float
    {
        $period = $current;
        $incomeSum = 0.0;

        for ($i = 0; $i < self::AVERAGE_WINDOW; $i++) {
            $period = $budgetCycle->previous($period);
            $incomeSum += $this->sumTransactions($user, $period, 'income');
        }

        $avgIncome = round($incomeSum / self::AVERAGE_WINDOW, 2);

        return $avgIncome > 0 ? $avgIncome : self::DEFAULT_PROJECTED_INCOME;
    }

    /**
     * Sum of every budget category's limit_amount, excluding categories already linked to a
     * RecurringBill or a Debt — those are already counted by the Bills and Debt rows.
     * Link-based rather than group-name-based, since a bill or debt's category isn't always
     * in a "Subscriptions"/"Debt"-named group (e.g. this user's Personal-grouped gift bills).
     */
    private function nonBillAndDebtCategoryLimitTotal(User $user): float
    {
        $excludedCategoryIds = $user->recurringBills()->whereNotNull('budget_category_id')->pluck('budget_category_id')
            ->merge($user->debts()->whereNotNull('budget_category_id')->pluck('budget_category_id'))
            ->unique();

        return round((float) $user->budgetCategories()
            ->whereNotIn('id', $excludedCategoryIds)
            ->sum('limit_amount'), 2);
    }

    private function sumTransactions(User $user, CyclePeriod $period, string $type): float
    {
        return (float) $user->transactions()
            ->whereDate('transacted_at', '>=', $period->start)
            ->whereDate('transacted_at', '<=', $period->end)
            ->where('type', $type)
            ->sum('amount');
    }

    private function sumEverydayExpenses(User $user, CyclePeriod $period): float
    {
        return (float) $user->transactions()
            ->whereDate('transacted_at', '>=', $period->start)
            ->whereDate('transacted_at', '<=', $period->end)
            ->where('type', 'expense')
            ->whereNull('debt_id')
            ->sum('amount');
    }

    /**
     * @param  Collection<int, Envelopable&Forecastable>  $items
     * @param  list<CyclePeriod>  $periods
     * @return array{items: list<array<string, mixed>>, subtotal: list<float>}
     */
    private function buildGroup(Collection $items, array $periods): array
    {
        $result = [];
        $subtotal = array_fill(0, self::PERIOD_COUNT, 0.0);

        foreach ($items as $item) {
            $amounts = [];
            for ($i = 0; $i < self::PERIOD_COUNT; $i++) {
                $amounts[$i] = round($item->forecastAmount($periods[$i], $i), 2);
                $subtotal[$i] += $amounts[$i];
            }

            $result[] = [
                'id' => $item->id, 'name' => $item->envelopeName(),
                'icon' => $item->envelopeIcon(), 'color' => $item->envelopeColor(),
                'amounts' => $amounts,
            ];
        }

        return ['items' => $result, 'subtotal' => array_map(fn ($v) => round($v, 2), $subtotal)];
    }

    /**
     * @param  list<CyclePeriod>  $periods
     * @return array{items: list<array<string, mixed>>, subtotal: list<float>}
     */
    private function debtGroup(User $user, array $periods): array
    {
        $debts = $user->debts()->where('balance', '>', 0)->orderBy('position')->get();
        $perDebtMonths = $this->trajectory->simulatePayoff($this->trajectory->payoffInputs($debts))['perDebtMonths'];

        foreach ($debts->values() as $index => $debt) {
            $debt->forecastPayoffPeriodIndex = $perDebtMonths[$index] ?? null;
        }

        return $this->buildGroup($debts, $periods);
    }

    /**
     * @param  list<CyclePeriod>  $periods
     * @return array{items: list<array<string, mixed>>, subtotal: list<float>}
     */
    private function setAsideGroup(User $user, array $periods): array
    {
        return $this->buildGroup($user->sinkingFunds()->orderBy('position')->get(), $periods);
    }

    /**
     * @param  list<CyclePeriod>  $periods
     * @return array{subgroups: list<array<string, mixed>>, subtotal: list<float>}
     */
    private function billsGroup(User $user, array $periods): array
    {
        $bills = $user->recurringBills()->with('budgetCategory')->orderBy('day_of_month')->get();
        $grouped = $bills->groupBy(fn ($bill) => $bill->budgetCategory?->group ?? 'Other');

        $subgroups = [];
        $subtotal = array_fill(0, self::PERIOD_COUNT, 0.0);

        foreach ($grouped as $groupName => $groupBills) {
            $group = $this->buildGroup($groupBills, $periods);

            for ($i = 0; $i < self::PERIOD_COUNT; $i++) {
                $subtotal[$i] += $group['subtotal'][$i];
            }

            $subgroups[] = ['groupName' => $groupName, 'items' => $group['items'], 'subtotal' => $group['subtotal']];
        }

        return ['subgroups' => $subgroups, 'subtotal' => array_map(fn ($v) => round($v, 2), $subtotal)];
    }
}
