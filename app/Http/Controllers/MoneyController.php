<?php

namespace App\Http\Controllers;

use App\Models\DebtPayment;
use App\Services\BudgetCycle;
use App\Services\TrajectoryReportService;
use App\Services\UpcomingBills;
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
            'upcomingBills' => (new UpcomingBills)->within($user, $period),
            'categoriesOverBudget' => $this->categoriesOverBudget($user, $cycleStart, $cycleEnd),
            ...$this->debtPayoff($user, $today),
        ]);
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
