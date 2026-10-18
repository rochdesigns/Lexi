<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\DebtRequest;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\PaymentPlan;
use App\Models\Money\Transaction;
use App\Services\Money\Centavos;
use App\Services\Money\Ledger;
use App\Services\Money\Projection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class DebtController extends Controller
{
    public function __construct(private Projection $projection, private Ledger $ledger) {}

    public function index(Request $request): Response
    {
        $today = Carbon::today();
        $debts = $request->user()->debts()
            ->with(['cardDetail', 'paymentPlans.debt', 'payments', 'transactions'])
            ->orderBy('name')
            ->get();

        return Inertia::render('Money/Debts', [
            'debts' => $debts->map(fn (Debt $debt): array => $this->present($debt, $today))->values(),
            'monthlyTotal' => $this->projection->totalMonthlyPayment($debts, $today),
            'rollOff' => $this->projection->rollOff($debts, $today),
        ]);
    }

    public function show(Request $request, Debt $debt): Response
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $today = Carbon::today();
        $debt->load(['cardDetail', 'paymentPlans.debt', 'payments', 'transactions.customCategory', 'transactions.bill']);

        $plans = $debt->paymentPlans
            ->sortBy([
                fn (PaymentPlan $a, PaymentPlan $b): int => $this->projection->endMonth($a)->timestamp <=> $this->projection->endMonth($b)->timestamp,
                ['id', 'asc'],
            ])
            ->map(fn (PaymentPlan $plan): array => $this->presentPlan($plan, $today))
            ->values();

        $payments = $debt->payments
            ->sortByDesc(fn (DebtPayment $payment): string => $payment->date->toDateString().str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT))
            ->map(fn (DebtPayment $payment): array => [
                'id' => $payment->id,
                'paymentPlanId' => $payment->payment_plan_id,
                'date' => $payment->date->toDateString(),
                'amount' => $payment->amount,
                'isExtra' => $payment->is_extra,
                'accountId' => $payment->account_id,
            ])
            ->values();

        return Inertia::render('Money/Debt', [
            'accounts' => $request->user()->accounts()->orderBy('name')->get()->map(fn ($account): array => ['id' => $account->id, 'name' => $account->name, 'balance' => $account->balance])->values(),
            'debt' => $this->present($debt, $today),
            'plans' => $plans,
            'payments' => $payments,
            'rollOff' => $this->projection->rollOff(collect([$debt]), $today),
            'clearingDefault' => $debt->cardDetail ? $this->projection->minimumTowardsBalance($debt, $today) : null,
            'statements' => $debt->cardDetail ? $this->statements($debt) : [],
        ]);
    }

    /**
     * When a card's revolving balance clears at a fixed monthly payment. Saves nothing.
     */
    public function clearing(Request $request, Debt $debt): JsonResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);
        abort_if($debt->cardDetail === null, 404);

        $data = $request->validate([
            'amount' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
        ]);

        return response()->json($this->projection->clearingSchedule($debt, Centavos::fromPesos($data['amount'] ?? 0), Carbon::today()));
    }

    public function store(DebtRequest $request): RedirectResponse
    {
        $this->ledger->createDebt($request->user(), $request);

        return redirect()->route('money.debts.index');
    }

    public function update(DebtRequest $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        DB::transaction(function () use ($request, $debt): void {
            $debt->update($request->debtAttributes());

            if ($request->isCard()) {
                $debt->cardDetail()->updateOrCreate([], $request->cardAttributes());
            } else {
                $debt->cardDetail()->delete();
            }
        });

        return back();
    }

    public function destroy(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $this->ledger->removeDebt($debt);

        return redirect()->route('money.debts.index');
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Debt $debt, Carbon $today): array
    {
        $card = $debt->cardDetail;
        $statement = $card ? $this->projection->nextStatement($debt, $today) : null;

        return [
            'id' => $debt->id,
            'name' => $debt->name,
            'type' => $debt->type->value,
            'lender' => $debt->lender,
            'interestType' => $debt->interest_type->value,
            'monthlyInterestRate' => $debt->monthly_interest_rate,
            'dueDay' => $debt->due_day,
            'status' => $debt->status->value,
            'notes' => $debt->notes,
            'outstandingBalance' => $debt->outstanding_balance,
            'targetPayoffDate' => $debt->target_payoff_date?->toDateString(),
            'totalRemaining' => $this->projection->totalRemaining($debt, $today),
            'dueThisMonth' => $this->projection->dueThisMonth($debt, $today),
            'paidThisMonth' => $this->projection->paidInMonth($debt, $today),
            'nextDueDate' => $this->projection->nextDueDate($debt, $today)?->toDateString(),
            'card' => $card ? [
                'creditLimit' => $card->credit_limit,
                'statementDay' => $card->statement_day,
                'dueDaysAfterStatement' => $card->due_days_after_statement,
                'minPaymentPercent' => $card->min_payment_percent,
                'minPaymentFloor' => $card->min_payment_floor,
                'revolvingBalance' => $card->revolving_balance,
                'closesOn' => $statement['closes_on']->toDateString(),
                'projectedStatement' => $this->projection->projectedNextStatement($debt, $today),
                'minimumDue' => $this->projection->minimumDue($debt, $today),
                'chargesDueInFull' => $card->charges_due_in_full,
                'utilization' => $this->projection->utilization($debt, $today),
            ] : null,
        ];
    }

    /**
     * A card's charges and payments grouped by the statement they fall on,
     * newest statement first, oldest entry first within each. A charge on the
     * closing day is on that statement.
     *
     * @return list<array{closesOn: string, opensOn: string, charged: int, paid: int, entries: list<array{kind: string, id: int, date: string, amount: int, label: ?string, category: ?string}>}>
     */
    private function statements(Debt $debt): array
    {
        $statementDay = $debt->cardDetail->statement_day;

        $charges = $debt->transactions
            ->whereNull('matched_transaction_id')
            ->map(fn (Transaction $transaction): array => [
                'kind' => 'charge',
                'id' => $transaction->id,
                'date' => $transaction->date->toDateString(),
                'amount' => $transaction->amount,
                'label' => $transaction->note ?: ($transaction->bill?->name ?? $transaction->customCategory?->name),
                'category' => $transaction->category->value,
            ]);

        $payments = $debt->payments->map(fn (DebtPayment $payment): array => [
            'kind' => 'payment',
            'id' => $payment->id,
            'date' => $payment->date->toDateString(),
            'amount' => $payment->amount,
            'label' => null,
            'category' => null,
        ]);

        return $charges->concat($payments)
            ->sortBy([['date', 'asc'], ['kind', 'asc'], ['id', 'asc']])
            ->groupBy(fn (array $entry): string => $this->projection->nextClosingDate($statementDay, Carbon::parse($entry['date']))->toDateString())
            ->sortKeysDesc()
            ->map(function (Collection $entries, string $closesOn) use ($statementDay): array {
                $closes = Carbon::parse($closesOn);

                return [
                    'closesOn' => $closesOn,
                    'opensOn' => $this->projection->latestClosingDate($statementDay, $closes)->addDay()->toDateString(),
                    'charged' => $entries->where('kind', 'charge')->sum('amount'),
                    'paid' => $entries->where('kind', 'payment')->sum('amount'),
                    'entries' => $entries->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPlan(PaymentPlan $plan, Carbon $today): array
    {
        $row = $this->projection->scheduleRow($plan, $today);

        return [
            'id' => $plan->id,
            'description' => $plan->description,
            'principal' => $plan->principal,
            'monthlyAmount' => $plan->monthly_amount,
            'totalMonths' => $plan->total_months,
            'startMonth' => $plan->start_month->format('Y-m'),
            'endMonth' => $this->projection->endMonth($plan)->format('Y-m'),
            'monthsLeft' => $this->projection->monthsLeft($plan, $today),
            'remainingBalance' => $this->projection->remainingBalance($plan, $today),
            'interestType' => $this->projection->interestTypeOf($plan)->value,
            'ownInterestType' => $plan->interest_type?->value,
            'totalInterest' => $this->projection->totalInterest($plan),
            'interestThisMonth' => $row['interest'] ?? null,
            'principalThisMonth' => $row['principal'] ?? null,
            'earlyPayoffSavesInterest' => $this->projection->earlyPayoffSavesInterest($plan),
            'inMinimum' => $plan->in_minimum,
            'status' => $plan->status->value,
        ];
    }
}
