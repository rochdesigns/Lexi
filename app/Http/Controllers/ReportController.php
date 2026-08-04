<?php

namespace App\Http\Controllers;

use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $periodType = in_array($request->query('period'), ['month', 'quarter', 'year'], true)
            ? $request->query('period')
            : 'month';

        $anchor = $request->query('date')
            ? Carbon::parse($request->query('date'))
            : Carbon::now();

        $period = $this->resolvePeriod($periodType, $anchor);

        $overview = $this->computeOverview($user, $period['start'], $period['end'], $period['monthsInPeriod'], $period['isCurrent']);
        $spending = $this->computeSpending($user, $period['start'], $period['end']);
        $budgetVsActual = $this->computeBudgetVsActual($user, $period['start'], $period['end'], $period['monthsInPeriod']);

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
        ]);
    }

    /**
     * @return array{start: Carbon, end: Carbon, label: string, prevDate: string, nextDate: ?string, isCurrent: bool, monthsInPeriod: int}
     */
    private function resolvePeriod(string $type, Carbon $anchor): array
    {
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
}
