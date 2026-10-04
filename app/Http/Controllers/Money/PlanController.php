<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\GoalRequest;
use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\BillFrequency;
use App\Models\Money\Goal;
use App\Models\Money\GoalKind;
use App\Services\Money\Projection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function __construct(private Projection $projection) {}

    /**
     * The emergency buffer and the sinking funds, with where each one stands.
     * Yearly bills are listed too, since they are already set aside each month.
     */
    public function __invoke(Request $request): Response
    {
        $today = Carbon::today();
        $user = $request->user();
        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
        $bills = $user->bills()->orderBy('name')->get();
        $goals = $user->goals()->with('account')->orderBy('name')->get();
        $obligations = $this->projection->monthlyObligations($debts, $bills, $today);

        $present = fn (Goal $goal): array => [
            'id' => $goal->id,
            'kind' => $goal->kind->value,
            'name' => $goal->name,
            'monthlyContribution' => $goal->monthly_contribution,
            'saved' => $goal->saved,
            'accountId' => $goal->account_id,
            'accountName' => $goal->account?->name,
            'bufferMonths' => $goal->buffer_months,
            'targetAmount' => $goal->target_amount,
            'targetDate' => $goal->target_date?->toDateString(),
            ...$this->projection->goalProgress($goal, $this->projection->goalTarget($goal, $obligations), $today),
        ];

        $buffer = $goals->first(fn (Goal $goal): bool => $goal->kind === GoalKind::Buffer);

        return Inertia::render('Money/Plan', [
            'obligations' => $obligations,
            'buffer' => $buffer === null ? null : $present($buffer),
            'goals' => $goals->filter(fn (Goal $goal): bool => $goal->kind === GoalKind::Sinking)->map($present)->values(),
            'yearlyBills' => $bills->filter(fn (Bill $bill): bool => $bill->frequency === BillFrequency::Annual)->map(fn (Bill $bill): array => [
                'id' => $bill->id,
                'name' => $bill->name,
                'amount' => $bill->amount,
                'dueMonth' => $bill->due_month,
                'monthlySetAside' => (int) ceil($bill->amount / 12),
            ])->values(),
            'accounts' => $user->accounts()->orderBy('name')->get()->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'isSpendable' => $account->is_spendable,
            ])->values(),
            'bufferMonthOptions' => GoalRequest::BUFFER_MONTHS,
        ]);
    }
}
