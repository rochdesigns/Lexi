<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\User;
use App\Services\BudgetCycle;
use App\Services\TrajectoryReportService;
use App\Support\CyclePeriod;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function __construct(private TrajectoryReportService $trajectory) {}

    public function index(Request $request): Response
    {
        $user = $request->user();

        $periodType = in_array($request->query('period'), ['cycle', 'quarter', 'year'], true)
            ? $request->query('period')
            : 'cycle';

        $anchor = Carbon::now();
        if ($request->query('date')) {
            try {
                $parsed = Carbon::parse($request->query('date'));
                $anchor = $parsed->greaterThan(Carbon::now()) ? Carbon::now() : $parsed;
            } catch (\Exception $e) {
                $anchor = Carbon::now();
            }
        }

        $period = $this->resolvePeriod($periodType, $anchor, $user);

        $overview = $this->computeOverview($user, $period['start'], $period['end'], $period['monthsInPeriod'], $period['isCurrent']);
        $spending = $this->computeSpending($user, $period['start'], $period['end']);
        $budgetVsActual = $this->computeBudgetVsActual($user, $period['start'], $period['end'], $period['monthsInPeriod']);
        $cashFlow = $this->computeCashFlow($user, $periodType, $period['start'], $period['end'], $period['isCurrent'], $overview['budgetTotal']);
        $payoff = $this->computePayoff($user);

        return Inertia::render('Reports', [
            'period' => [
                'type' => $periodType,
                'label' => $period['label'],
                'prevDate' => $period['prevDate'],
                'nextDate' => $period['nextDate'],
                'isCurrent' => $period['isCurrent'],
            ],
            'overview' => $overview,
            'spending' => $spending,
            'budgetVsActual' => $budgetVsActual,
            'cashFlow' => $cashFlow,
            'payoff' => $payoff,
        ]);
    }

    /**
     * @return array{start: Carbon, end: Carbon, label: string, prevDate: string, nextDate: ?string, isCurrent: bool, monthsInPeriod: int}
     */
    private function resolvePeriod(string $type, Carbon $anchor, User $user): array
    {
        if ($type === 'cycle') {
            $budgetCycle = new BudgetCycle($user->setting);
            $period = $budgetCycle->containing($anchor);
            $previous = $budgetCycle->previous($period);
            $next = $budgetCycle->next($period);

            $isCurrent = $period->containsToday();

            return [
                'start' => $period->start,
                'end' => $period->end,
                'label' => $period->label(),
                'prevDate' => $previous->start->toDateString(),
                'nextDate' => $isCurrent ? null : $next->start->toDateString(),
                'isCurrent' => $isCurrent,
                'monthsInPeriod' => 1,
            ];
        }

        [$start, $end, $label, $prevAnchor, $nextAnchor, $monthsInPeriod] = match ($type) {
            'quarter' => [
                $anchor->copy()->startOfQuarter(),
                $anchor->copy()->endOfQuarter(),
                'Q'.$anchor->quarter.' '.$anchor->year,
                $anchor->copy()->subQuarterNoOverflow(),
                $anchor->copy()->addQuarterNoOverflow(),
                3,
            ],
            'year' => [
                $anchor->copy()->startOfYear(),
                $anchor->copy()->endOfYear(),
                (string) $anchor->year,
                $anchor->copy()->subYear(),
                $anchor->copy()->addYear(),
                12,
            ],
            default => [
                $anchor->copy()->startOfMonth(),
                $anchor->copy()->endOfMonth(),
                $anchor->format('F Y'),
                $anchor->copy()->subMonthNoOverflow(),
                $anchor->copy()->addMonthNoOverflow(),
                1,
            ],
        };

        $now = Carbon::now();
        $isCurrent = $now->between($start, $end);

        return [
            'start' => $start,
            'end' => $end,
            'label' => $label,
            'prevDate' => $prevAnchor->toDateString(),
            'nextDate' => $isCurrent ? null : $nextAnchor->toDateString(),
            'isCurrent' => $isCurrent,
            'monthsInPeriod' => $monthsInPeriod,
        ];
    }

    private function computeOverview(User $user, Carbon $start, Carbon $end, int $monthsInPeriod, bool $isCurrent): array
    {
        $income = (float) $user->transactions()
            ->where('type', 'income')
            ->whereDate('transacted_at', '>=', $start)
            ->whereDate('transacted_at', '<=', $end)
            ->sum('amount');

        $expenses = (float) $user->transactions()
            ->where('type', 'expense')
            ->whereDate('transacted_at', '>=', $start)
            ->whereDate('transacted_at', '<=', $end)
            ->sum('amount');

        $budgetTotal = (float) $user->budgetCategories()->sum('limit_amount') * $monthsInPeriod;
        $budgetUsedPct = $budgetTotal > 0 ? $expenses / $budgetTotal : 0.0;

        $projectedTotal = null;
        if ($isCurrent) {
            $today = Carbon::now()->startOfDay();
            $elapsedDays = max(1, $start->copy()->startOfDay()->diffInDays($today) + 1);
            $totalDays = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
            $projectedTotal = round($expenses / $elapsedDays * $totalDays, 2);
        }

        return [
            'income' => $income,
            'expenses' => $expenses,
            'net' => $income - $expenses,
            'budgetTotal' => $budgetTotal,
            'budgetUsedPct' => $budgetUsedPct,
            'projectedTotal' => $projectedTotal,
        ];
    }

    private function computeSpending(User $user, Carbon $start, Carbon $end): array
    {
        $expenses = $user->transactions()
            ->with('budgetCategory')
            ->where('type', 'expense')
            ->whereDate('transacted_at', '>=', $start)
            ->whereDate('transacted_at', '<=', $end)
            ->get();

        $total = (float) $expenses->sum('amount');

        return $expenses
            ->groupBy('budget_category_id')
            ->map(function ($group) use ($total) {
                $amount = (float) $group->sum('amount');
                $category = $group->first()->budgetCategory;

                return [
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'icon' => $category->icon,
                        'color' => $category->color,
                    ] : [
                        'id' => null,
                        'name' => 'Uncategorized',
                        'icon' => 'help',
                        'color' => '#8a8172',
                    ],
                    'amount' => $amount,
                    'pct' => $total > 0 ? $amount / $total : 0.0,
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }

    private function computeBudgetVsActual(User $user, Carbon $start, Carbon $end, int $monthsInPeriod): array
    {
        return $user->budgetCategories()
            ->orderBy('name')
            ->get()
            ->map(function ($cat) use ($start, $end, $monthsInPeriod) {
                $actual = (float) $cat->transactions()
                    ->where('type', 'expense')
                    ->whereDate('transacted_at', '>=', $start)
                    ->whereDate('transacted_at', '<=', $end)
                    ->sum('amount');

                $budgeted = (float) $cat->limit_amount * $monthsInPeriod;

                return [
                    'category' => [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'icon' => $cat->icon,
                        'color' => $cat->color,
                    ],
                    'budgeted' => $budgeted,
                    'actual' => $actual,
                    'status' => $actual > $budgeted ? 'over' : 'under',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{start: Carbon, end: Carbon, label: string}>
     */
    private function makeCashFlowBuckets(string $periodType, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $buckets = [];

        if (! in_array($periodType, ['quarter', 'year'], true)) {
            $cursor = $rangeStart->copy();
            while ($cursor->lte($rangeEnd)) {
                $bucketEnd = $cursor->copy()->addDays(6);
                if ($bucketEnd->gt($rangeEnd)) {
                    $bucketEnd = $rangeEnd->copy();
                }
                $buckets[] = ['start' => $cursor->copy(), 'end' => $bucketEnd->copy(), 'label' => $cursor->format('M j')];
                $cursor = $bucketEnd->copy()->addDay();
            }
        } else {
            $cursor = $rangeStart->copy()->startOfMonth();
            while ($cursor->lte($rangeEnd)) {
                $bucketEnd = $cursor->copy()->endOfMonth();
                if ($bucketEnd->gt($rangeEnd)) {
                    $bucketEnd = $rangeEnd->copy();
                }
                $buckets[] = ['start' => $cursor->copy(), 'end' => $bucketEnd->copy(), 'label' => $cursor->format('M')];
                $cursor = $cursor->copy()->addMonthNoOverflow()->startOfMonth();
            }
        }

        return $buckets;
    }

    private function computeCashFlow(User $user, string $periodType, Carbon $start, Carbon $end, bool $isCurrent, float $budgetTotal): array
    {
        $buckets = $this->makeCashFlowBuckets($periodType, $start, $end);

        // When viewing the current, in-progress period, extend the chart one
        // more period of the same type forward (next month/quarter/year) so
        // the trend/budget projection lines keep going past the period end
        // instead of stopping cold. Only ever applied to the current period —
        // browsing to a past period never previews further into the future.
        if ($isCurrent) {
            $extensionStart = $end->copy()->addDay();
            $extensionEnd = match ($periodType) {
                'quarter' => $end->copy()->addQuarterNoOverflow()->endOfQuarter(),
                'year' => $end->copy()->addYear()->endOfYear(),
                'cycle' => (new BudgetCycle($user->setting))->next(new CyclePeriod($start, $end))->end,
                default => $end->copy()->addMonthNoOverflow()->endOfMonth(),
            };

            $buckets = array_merge($buckets, $this->makeCashFlowBuckets($periodType, $extensionStart, $extensionEnd));
        }

        $today = Carbon::now()->startOfDay();

        $series = [];
        foreach ($buckets as $bucket) {
            $isFuture = $isCurrent && $bucket['start']->copy()->startOfDay()->gt($today);

            if ($isFuture) {
                $series[] = ['label' => $bucket['label'], 'income' => null, 'expenses' => null, 'net' => null];

                continue;
            }

            $income = (float) $user->transactions()
                ->where('type', 'income')
                ->whereDate('transacted_at', '>=', $bucket['start'])
                ->whereDate('transacted_at', '<=', $bucket['end'])
                ->sum('amount');

            $expenses = (float) $user->transactions()
                ->where('type', 'expense')
                ->whereDate('transacted_at', '>=', $bucket['start'])
                ->whereDate('transacted_at', '<=', $bucket['end'])
                ->sum('amount');

            $series[] = ['label' => $bucket['label'], 'income' => $income, 'expenses' => $expenses, 'net' => $income - $expenses];
        }

        $trendLine = null;
        $budgetLine = null;

        if ($isCurrent) {
            $elapsedDays = max(1, $start->copy()->startOfDay()->diffInDays($today) + 1);
            $totalDays = max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);

            $expensesSoFar = (float) $user->transactions()
                ->where('type', 'expense')
                ->whereDate('transacted_at', '>=', $start)
                ->whereDate('transacted_at', '<=', $today)
                ->sum('amount');

            $incomeSoFar = (float) $user->transactions()
                ->where('type', 'income')
                ->whereDate('transacted_at', '>=', $start)
                ->whereDate('transacted_at', '<=', $today)
                ->sum('amount');

            $dailyExpenseTrendRate = $expensesSoFar / $elapsedDays;
            $dailyIncomeTrendRate = $incomeSoFar / $elapsedDays;
            $dailyBudgetRate = $budgetTotal / $totalDays;

            $netSoFar = $incomeSoFar - $expensesSoFar;

            $runningTrend = $netSoFar;
            $runningBudget = $netSoFar;
            $trendLine = [];
            $budgetLine = [];

            foreach ($buckets as $bucket) {
                $bucketStart = $bucket['start']->copy()->startOfDay();
                $bucketEnd = $bucket['end']->copy()->startOfDay();

                // Days within this bucket that fall strictly after today — the only
                // days that haven't happened yet and thus need projecting, whether
                // the bucket is fully future or straddles today.
                $remainingStart = $bucketStart->gt($today) ? $bucketStart : $today->copy()->addDay();
                $remainingDays = $remainingStart->lte($bucketEnd) ? $remainingStart->diffInDays($bucketEnd) + 1 : 0;

                if ($remainingDays <= 0) {
                    $trendLine[] = null;
                    $budgetLine[] = null;

                    continue;
                }

                // Both lines assume income keeps arriving at its observed daily pace —
                // otherwise a long projection window (e.g. a full extra year) reads as
                // spending draining to a huge negative number with no offsetting income.
                $runningTrend += ($dailyIncomeTrendRate - $dailyExpenseTrendRate) * $remainingDays;
                $runningBudget += ($dailyIncomeTrendRate - $dailyBudgetRate) * $remainingDays;
                $trendLine[] = round($runningTrend, 2);
                $budgetLine[] = round($runningBudget, 2);
            }
        }

        return [
            'series' => $series,
            'trendLine' => $trendLine,
            'budgetLine' => $budgetLine,
        ];
    }

    private function computePayoff(User $user): array
    {
        $today = Carbon::now()->startOfDay();

        $debtModels = $user->debts()
            ->where('balance', '>', 0)
            ->orderBy('position')
            ->get();

        // No extra monthly payment here — this page has no what-if input, so every debt
        // simply pays its own perMonth every month, independent of the others. With
        // extraMonthly at its default of 0.0, simulatePayoff()'s avalanche target-selection
        // never fires (there is nothing to redirect), so this is equivalent to running each
        // debt through its own amortization in isolation.
        $result = $this->trajectory->simulatePayoff($this->trajectory->payoffInputs($debtModels));

        $debts = $debtModels->values()->map(function (Debt $debt, int $index) use ($result, $today) {
            $months = $result['perDebtMonths'][$index] ?? null;

            return [
                'id' => $debt->id,
                'name' => $debt->name,
                'icon' => $debt->icon,
                'color' => $debt->color,
                'months' => $months,
                'payoffDate' => $months !== null ? $today->copy()->addMonthsNoOverflow($months)->format('M Y') : null,
                'onTrack' => $months !== null,
            ];
        })->all();

        $goals = $user->goals()
            ->whereColumn('saved', '<', 'target')
            ->orderBy('position')
            ->get()
            ->map(function ($goal) use ($today) {
                $perMonth = (float) $goal->per_month;
                $months = $perMonth > 0
                    ? (int) ceil(((float) $goal->target - (float) $goal->saved) / $perMonth)
                    : null;

                return [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'icon' => $goal->icon,
                    'color' => $goal->color,
                    'months' => $months,
                    'targetDate' => $months !== null ? $today->copy()->addMonthsNoOverflow($months)->format('M Y') : null,
                ];
            })
            ->values()
            ->all();

        return ['debts' => $debts, 'goals' => $goals];
    }
}
