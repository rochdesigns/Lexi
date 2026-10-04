<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Projection;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListDebts extends MoneyTool
{
    private const PAYMENT_CAP = 50;

    protected string $name = 'list_debts';

    protected string $description = 'List the user\'s debts (loans, credit cards, buy-now-pay-later, informal) with balance, interest, due day, what is due and paid this month, payment plans, credit card statement details, and the 50 most recent payments per debt. Use this for questions about what the user owes and how payoff is going.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $today = Carbon::today();
        $debts = $this->userFor($request)->debts()
            ->with(['cardDetail', 'paymentPlans.debt', 'payments', 'transactions'])
            ->orderBy('name')
            ->get();

        $rows = $debts->map(function ($debt) use ($projection, $today): array {
            $card = $debt->cardDetail;
            $payments = $debt->payments
                ->sortByDesc(fn ($payment): string => $payment->date->toDateString().str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT))
                ->values();

            return [
                'id' => $debt->id,
                'name' => $debt->name,
                'type' => $debt->type->value,
                'lender' => $debt->lender,
                'interestType' => $debt->interest_type->value,
                'monthlyInterestRate' => $debt->monthly_interest_rate,
                'dueDay' => $debt->due_day,
                'status' => $debt->status->value,
                'outstandingBalance' => $debt->outstanding_balance,
                'targetPayoffDate' => $debt->target_payoff_date?->toDateString(),
                'totalRemaining' => $projection->totalRemaining($debt, $today),
                'dueThisMonth' => $projection->dueThisMonth($debt, $today),
                'paidThisMonth' => $projection->paidInMonth($debt, $today),
                'nextDueDate' => $projection->nextDueDate($debt, $today)?->toDateString(),
                'card' => $card === null ? null : [
                    'creditLimit' => $card->credit_limit,
                    'statementDay' => $card->statement_day,
                    'dueDaysAfterStatement' => $card->due_days_after_statement,
                    'minPaymentPercent' => $card->min_payment_percent,
                    'minPaymentFloor' => $card->min_payment_floor,
                    'revolvingBalance' => $card->revolving_balance,
                    'closesOn' => $projection->nextStatement($debt, $today)['closes_on']->toDateString(),
                    'projectedStatement' => $projection->projectedNextStatement($debt, $today),
                    'minimumDue' => $projection->minimumDue($debt, $today),
                    'chargesDueInFull' => $card->charges_due_in_full,
                    'utilization' => $projection->utilization($debt, $today),
                ],
                'plans' => $debt->paymentPlans->map(fn ($plan): array => [
                    'id' => $plan->id,
                    'description' => $plan->description,
                    'principal' => $plan->principal,
                    'monthlyAmount' => $plan->monthly_amount,
                    'totalMonths' => $plan->total_months,
                    'startMonth' => $plan->start_month->format('Y-m'),
                    'endMonth' => $projection->endMonth($plan)->format('Y-m'),
                    'monthsLeft' => $projection->monthsLeft($plan, $today),
                    'remainingBalance' => $projection->remainingBalance($plan, $today),
                    'inMinimum' => $plan->in_minimum,
                    'status' => $plan->status->value,
                ])->values()->all(),
                'payments' => $payments->take(self::PAYMENT_CAP)->map(fn ($payment): array => [
                    'date' => $payment->date->toDateString(),
                    'amount' => $payment->amount,
                    'isExtra' => $payment->is_extra,
                ])->values()->all(),
                'paymentsTruncated' => $payments->count() > self::PAYMENT_CAP,
            ];
        })->values()->all();

        return $this->respond(count($rows).' debt(s).', [
            'debts' => $rows,
            'monthlyTotal' => $projection->totalMonthlyPayment($debts, $today),
        ]);
    }
}
