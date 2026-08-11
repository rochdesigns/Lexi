<?php

namespace App\Http\Controllers;

use App\Services\BudgetCycle;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $setting = $user->setting;
        $budgetCycle = new BudgetCycle($setting);

        $currentPeriod = $request->query('cycle_start')
            ? $budgetCycle->containing(Carbon::parse($request->query('cycle_start')))
            : $budgetCycle->current();

        $cycleStart = $currentPeriod->start;
        $cycleEnd = $currentPeriod->end;

        $cycleBudget = (float) $user->budgetCategories()->sum('limit_amount');
        $saPerMonth = (float) $user->sinkingFunds()->sum('per_month');
        $saCount = $user->sinkingFunds()->count();
        $totalAccountBalance = (float) $user->accounts()->sum('balance');
        $totalSaSaved = (float) $user->sinkingFunds()->sum('saved');

        $previousPeriod = $budgetCycle->previous($currentPeriod);
        $nextPeriod = $budgetCycle->next($currentPeriod);

        $prevCycleStartDate = $previousPeriod->start;
        $prevCycleSpent = (float) $user->transactions()
            ->where('transacted_at', '>=', $prevCycleStartDate->toDateString())
            ->where('transacted_at', '<', $cycleStart->toDateString())
            ->where('type', 'expense')
            ->sum('amount');
        $prevCycleLeftover = $prevCycleSpent > 0
            ? max(0.0, $cycleBudget - $prevCycleSpent - $saPerMonth)
            : 0.0;

        $categories = $user->budgetCategories()
            ->orderBy('name')
            ->get()
            ->map(function ($cat) use ($cycleStart, $cycleEnd) {
                $spent = (float) $cat->transactions()
                    ->whereDate('transacted_at', '>=', $cycleStart)
                    ->whereDate('transacted_at', '<=', $cycleEnd)
                    ->where('type', 'expense')
                    ->sum('amount');

                return [
                    'id' => $cat->id,
                    'name' => $cat->name,
                    'group' => $cat->group,
                    'icon' => $cat->icon,
                    'color' => $cat->color,
                    'limit_amount' => (float) $cat->limit_amount,
                    'spent' => $spent,
                ];
            });

        // Add back spending within each category's limit so that paying a budgeted expense
        // doesn't reduce RTA twice (once when the category limit was set, once when paid).
        $categorySpentOffset = $categories->sum(fn ($cat) => min($cat['spent'], $cat['limit_amount']));
        $readyToAssign = $totalAccountBalance - $totalSaSaved - $cycleBudget + $categorySpentOffset + $prevCycleLeftover;

        $transactions = $user->transactions()
            ->with(['budgetCategory', 'account'])
            ->whereDate('transacted_at', '>=', $cycleStart)
            ->whereDate('transacted_at', '<=', $cycleEnd)
            ->orderByDesc('transacted_at')
            ->get();

        $allTransactions = $user->transactions()
            ->with(['budgetCategory', 'account'])
            ->orderByDesc('transacted_at')
            ->get();

        $totalSpent = (float) $transactions->where('type', 'expense')->sum('amount');
        $safeToSpend = $cycleBudget - $totalSpent - $saPerMonth;

        $today = Carbon::now()->startOfDay();
        $daysLeft = max(0, (int) $today->diffInDays($cycleEnd->copy()->startOfDay(), false) + 1);
        $dailyBudget = $daysLeft > 0 ? max(0, round($safeToSpend / $daysLeft)) : 0;

        $accounts = $user->accounts()->orderBy('name')->get()->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'institution' => $a->institution,
            'balance' => (float) $a->balance,
            'type' => $a->type,
        ]);

        $makeDueLabel = function (CarbonInterface $dueDate) use ($today): array {
            $daysUntil = (int) $today->diffInDays($dueDate, false);
            if ($daysUntil < 0) {
                $label = 'Past due';
            } elseif ($daysUntil === 0) {
                $label = 'Due today';
            } elseif ($daysUntil === 1) {
                $label = 'Due tomorrow';
            } elseif ($daysUntil <= 7) {
                $label = "Due in {$daysUntil} days";
            } else {
                $label = 'Due '.$dueDate->format('M j');
            }

            return ['label' => $label, 'soon' => $daysUntil <= 7, 'daysUntil' => $daysUntil];
        };

        $sinkingFundBills = $user->sinkingFunds()
            ->whereNotNull('due_date')
            ->where('due_date', '>=', $today->toDateString())
            ->orderBy('due_date')
            ->get()
            ->map(function ($sf) use ($makeDueLabel) {
                $dueDate = Carbon::parse($sf->due_date)->startOfDay();
                $due = $makeDueLabel($dueDate);

                return [
                    'id' => 'sf-'.$sf->id,
                    'name' => $sf->name,
                    'icon' => $sf->icon,
                    'color' => $sf->color,
                    'bg' => $sf->bg,
                    'amount' => (float) $sf->target,
                    'dueLabel' => $due['label'],
                    'soon' => $due['soon'],
                    'daysUntil' => $due['daysUntil'],
                    'category' => null,
                    'type' => 'set_aside',
                ];
            });

        $recurringBillItems = $user->recurringBills()
            ->with('budgetCategory')
            ->orderBy('day_of_month')
            ->get()
            ->map(function ($bill) use ($cycleStart, $cycleEnd, $makeDueLabel) {
                $day = $bill->day_of_month;

                // Find this bill's occurrence within the current cycle (spans at most 2 calendar months)
                $dueDate = null;
                $checkMonth = $cycleStart->copy()->startOfMonth();
                for ($i = 0; $i < 2; $i++) {
                    $candidate = $checkMonth->copy()->setDay(min($day, (int) $checkMonth->copy()->endOfMonth()->day));
                    if ($candidate->between($cycleStart, $cycleEnd)) {
                        $dueDate = $candidate;
                        break;
                    }
                    $checkMonth = $checkMonth->addMonthNoOverflow();
                }

                if (! $dueDate) {
                    return null;
                }

                // Skip if already paid this cycle
                if ($bill->last_paid_at && $bill->last_paid_at->greaterThanOrEqualTo($cycleStart)) {
                    return null;
                }

                $due = $makeDueLabel($dueDate);

                return [
                    'id' => 'rb-'.$bill->id,
                    'name' => $bill->name,
                    'icon' => $bill->icon,
                    'color' => $bill->color,
                    'bg' => $bill->bg,
                    'amount' => (float) $bill->amount,
                    'day_of_month' => $bill->day_of_month,
                    'budget_category_id' => $bill->budget_category_id,
                    'dueLabel' => $due['label'],
                    'soon' => $due['soon'],
                    'daysUntil' => $due['daysUntil'],
                    'category' => $bill->budgetCategory ? [
                        'id' => $bill->budgetCategory->id,
                        'name' => $bill->budgetCategory->name,
                        'color' => $bill->budgetCategory->color,
                    ] : null,
                    'type' => 'recurring',
                ];
            })
            ->filter()
            ->values();

        $upcomingBills = $sinkingFundBills
            ->concat($recurringBillItems)
            ->sortBy('daysUntil')
            ->take(5)
            ->values();

        $allRecurringBills = $user->recurringBills()
            ->with('budgetCategory')
            ->orderBy('day_of_month')
            ->get()
            ->map(fn ($bill) => [
                'id' => $bill->id,
                'name' => $bill->name,
                'icon' => $bill->icon,
                'color' => $bill->color,
                'bg' => $bill->bg,
                'amount' => (float) $bill->amount,
                'day_of_month' => $bill->day_of_month,
                'budget_category_id' => $bill->budget_category_id,
                'category' => $bill->budgetCategory ? [
                    'id' => $bill->budgetCategory->id,
                    'name' => $bill->budgetCategory->name,
                    'color' => $bill->budgetCategory->color,
                ] : null,
            ]);

        $prevCycleStart = $previousPeriod->start->toDateString();
        $nextCycleStart = $nextPeriod->start->toDateString();
        $isCurrentCycle = $currentPeriod->containsToday();
        $cycleLabel = $currentPeriod->label();

        $mapTransaction = fn ($t) => [
            'id' => $t->id,
            'type' => $t->type,
            'amount' => (float) $t->amount,
            'merchant' => $t->merchant,
            'transacted_at' => $t->transacted_at->toDateString(),
            'account' => ['id' => $t->account_id, 'name' => $t->account->name],
            'to_account_id' => $t->to_account_id,
            'category' => $t->budgetCategory ? [
                'id' => $t->budgetCategory->id,
                'name' => $t->budgetCategory->name,
                'icon' => $t->budgetCategory->icon,
                'color' => $t->budgetCategory->color,
            ] : null,
        ];

        $transactionData = $transactions->map($mapTransaction);
        $allTransactionData = $allTransactions->map($mapTransaction);

        $todayContext = [
            'date' => Carbon::now()->format('l, M j'),
            'tasksLeft' => $user->tasks()->where('done', false)->count(),
            'eventsCount' => $user->events()->whereDate('date', Carbon::now())->count(),
        ];

        return Inertia::render('Budgets', [
            'todayContext' => $todayContext,
            'saPerMonth' => $saPerMonth,
            'saCount' => $saCount,
            'categories' => $categories,
            'transactions' => $transactionData,
            'allTransactions' => $allTransactionData,
            'accounts' => $accounts,
            'cycle' => [
                'start' => $cycleStart->toDateString(),
                'end' => $cycleEnd->toDateString(),
                'label' => $cycleLabel,
                'budget' => $cycleBudget,
                'daysLeft' => $daysLeft,
            ],
            'totalSpent' => $totalSpent,
            'safeToSpend' => $safeToSpend,
            'readyToAssign' => $readyToAssign,
            'prevCycleLeftover' => $prevCycleLeftover,
            'dailyBudget' => $dailyBudget,
            'prevCycleStart' => $prevCycleStart,
            'nextCycleStart' => $nextCycleStart,
            'isCurrentCycle' => $isCurrentCycle,
            'upcomingBills' => $upcomingBills,
            'recurringBills' => $allRecurringBills,
        ]);
    }
}
