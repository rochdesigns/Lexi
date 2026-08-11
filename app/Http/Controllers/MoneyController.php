<?php

namespace App\Http\Controllers;

use App\Models\DebtPayment;
use App\Services\BudgetCycle;
use App\Services\TrajectoryReportService;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class MoneyController extends Controller
{
    public function __construct(private TrajectoryReportService $trajectory) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $period = (new BudgetCycle($user->setting))->current();
        $cycleStart = $period->start;
        $cycleEnd = $period->end;
        $today = Carbon::now()->startOfDay();

        $incomeReceived = (float) $user->transactions()
            ->whereDate('transacted_at', '>=', $cycleStart)
            ->whereDate('transacted_at', '<=', $cycleEnd)
            ->where('type', 'income')
            ->sum('amount');

        // Excludes debt-linked expense transactions so "Debt paid" (below) isn't double-counted.
        $everydayExpenses = (float) $user->transactions()
            ->whereDate('transacted_at', '>=', $cycleStart)
            ->whereDate('transacted_at', '<=', $cycleEnd)
            ->where('type', 'expense')
            ->whereNull('debt_id')
            ->sum('amount');

        $setAsidesHeld = (float) $user->sinkingFunds()->sum('per_month');

        $debtPaid = (float) DebtPayment::where('user_id', $user->id)
            ->whereBetween('paid_at', [$cycleStart->toDateString(), $cycleEnd->toDateString()])
            ->sum('amount');

        $leftForDailyExpenses = $incomeReceived - $everydayExpenses - $setAsidesHeld - $debtPaid;

        return Inertia::render('Money', [
            'cycle' => $period->toArray(),
            'incomeReceived' => $incomeReceived,
            'everydayExpenses' => $everydayExpenses,
            'setAsidesHeld' => $setAsidesHeld,
            'debtPaid' => $debtPaid,
            'leftForDailyExpenses' => $leftForDailyExpenses,
            'upcomingBills' => $this->upcomingBills($user, $cycleStart, $cycleEnd, $today),
            'categoriesOverBudget' => $this->categoriesOverBudget($user, $cycleStart, $cycleEnd),
            ...$this->debtPayoff($user, $today),
        ]);
    }

    private function upcomingBills($user, CarbonInterface $cycleStart, CarbonInterface $cycleEnd, CarbonInterface $today)
    {
        $makeDueLabel = function (CarbonInterface $dueDate) use ($today): array {
            $daysUntil = (int) $today->diffInDays($dueDate, false);
            $label = match (true) {
                $daysUntil < 0 => 'Past due',
                $daysUntil === 0 => 'Due today',
                $daysUntil === 1 => 'Due tomorrow',
                default => "Due in {$daysUntil} days",
            };

            return ['label' => $label, 'daysUntil' => $daysUntil];
        };

        $sinkingFundBills = $user->sinkingFunds()
            ->whereNotNull('due_date')
            ->where('due_date', '>=', $today->toDateString())
            ->get()
            ->map(function ($sf) use ($makeDueLabel) {
                $due = $makeDueLabel(Carbon::parse($sf->due_date)->startOfDay());

                return [
                    'id' => 'sf-'.$sf->id, 'name' => $sf->name, 'icon' => $sf->icon,
                    'color' => $sf->color, 'bg' => $sf->bg, 'amount' => (float) $sf->target,
                    'dueLabel' => $due['label'], 'daysUntil' => $due['daysUntil'], 'type' => 'set_aside',
                ];
            })
            ->filter(fn ($b) => $b['daysUntil'] <= 7);

        $recurringBillItems = $user->recurringBills()
            ->with('budgetCategory')
            ->orderBy('day_of_month')
            ->get()
            ->map(function ($bill) use ($cycleStart, $cycleEnd, $makeDueLabel) {
                $day = $bill->day_of_month;
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

                if (! $dueDate || ($bill->last_paid_at && $bill->last_paid_at->greaterThanOrEqualTo($cycleStart))) {
                    return null;
                }

                $due = $makeDueLabel($dueDate);

                return [
                    'id' => 'rb-'.$bill->id, 'name' => $bill->name, 'icon' => $bill->icon,
                    'color' => $bill->color, 'bg' => $bill->bg, 'amount' => (float) $bill->amount,
                    'dueLabel' => $due['label'], 'daysUntil' => $due['daysUntil'], 'type' => 'recurring',
                    'budget_category_id' => $bill->budget_category_id,
                    'category' => $bill->budgetCategory ? [
                        'id' => $bill->budgetCategory->id,
                        'name' => $bill->budgetCategory->name,
                        'color' => $bill->budgetCategory->color,
                    ] : null,
                ];
            })
            ->filter()
            ->filter(fn ($b) => $b['daysUntil'] <= 7)
            ->values();

        return $sinkingFundBills->concat($recurringBillItems)->sortBy('daysUntil')->values();
    }

    private function categoriesOverBudget($user, CarbonInterface $cycleStart, CarbonInterface $cycleEnd)
    {
        $spentByCategory = $user->transactions()
            ->whereDate('transacted_at', '>=', $cycleStart)
            ->whereDate('transacted_at', '<=', $cycleEnd)
            ->where('type', 'expense')
            ->whereNotNull('budget_category_id')
            ->selectRaw('budget_category_id, SUM(amount) as spent')
            ->groupBy('budget_category_id')
            ->pluck('spent', 'budget_category_id');

        return $user->budgetCategories()
            ->where('limit_amount', '>', 0)
            ->orderBy('name')
            ->get()
            ->map(fn ($cat) => [
                'id' => $cat->id, 'name' => $cat->name, 'icon' => $cat->icon, 'color' => $cat->color,
                'limit_amount' => (float) $cat->limit_amount,
                'spent' => (float) ($spentByCategory[$cat->id] ?? 0),
            ])
            ->filter(fn ($cat) => $cat['spent'] > $cat['limit_amount'])
            ->values();
    }

    /** @return array{debtFreeMonths: int|null, debtFreeDate: string|null, debtOnTrack: bool, totalDebtRemaining: float} */
    private function debtPayoff($user, CarbonInterface $today): array
    {
        $debts = $user->debts()->where('balance', '>', 0)->orderBy('position')->get();

        if ($debts->isEmpty()) {
            return ['debtFreeMonths' => null, 'debtFreeDate' => null, 'debtOnTrack' => true, 'totalDebtRemaining' => 0.0];
        }

        $result = $this->trajectory->simulatePayoff($this->trajectory->payoffInputs($debts));

        return [
            'debtFreeMonths' => $result['months'],
            'debtFreeDate' => $result['months'] !== null ? $today->copy()->addMonthsNoOverflow($result['months'])->format('M Y') : null,
            'debtOnTrack' => $result['onTrack'],
            'totalDebtRemaining' => (float) $debts->sum('balance'),
        ];
    }
}
