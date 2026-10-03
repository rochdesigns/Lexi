<?php

namespace App\Services\Money;

use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Every money projection lives here. All amounts are integer centavos.
 *
 * A payment plan bills on the statement or due date of each month from its
 * start month through its end month, inclusive. "Months left" counts the month
 * being asked about as still to be paid. A plan's interest type decides how a
 * payment splits between principal and interest:
 *
 *  - none: no interest, remaining balance is monthly amount x months left.
 *  - amortized: interest accrues on the outstanding principal each month.
 *  - add_on: interest is fixed upfront (monthly x months - principal) and
 *    spread evenly, so paying early does not shrink it.
 *
 * Debts without a plan (informal) carry an outstanding balance, spread over
 * the months up to their target payoff date when one is set.
 */
class Projection
{
    public const ROLL_OFF_MONTHS = 24;

    // ── Payment plans ────────────────────────────────────────────────────

    /**
     * A plan on a card that carries over interest is an installment, which charges none.
     */
    public function interestTypeOf(PaymentPlan $plan): InterestType
    {
        $type = $plan->interest_type ?? $plan->debt->interest_type;

        return $type === InterestType::Revolving ? InterestType::None : $type;
    }

    public function endMonth(PaymentPlan $plan): CarbonImmutable
    {
        return $this->firstOfMonth($plan->start_month)->addMonths($plan->total_months - 1);
    }

    public function monthsLeft(PaymentPlan $plan, CarbonInterface $asOf): int
    {
        if ($plan->status === DebtStatus::PaidOff) {
            return 0;
        }

        $month = $this->firstOfMonth($asOf);

        if ($month->lessThan($this->firstOfMonth($plan->start_month))) {
            return $plan->total_months;
        }

        if ($month->greaterThan($this->endMonth($plan))) {
            return 0;
        }

        return (int) $month->diffInMonths($this->endMonth($plan)) + 1;
    }

    public function isBilledIn(PaymentPlan $plan, CarbonInterface $month): bool
    {
        if ($plan->status === DebtStatus::PaidOff) {
            return false;
        }

        $month = $this->firstOfMonth($month);

        return $month->greaterThanOrEqualTo($this->firstOfMonth($plan->start_month))
            && $month->lessThanOrEqualTo($this->endMonth($plan));
    }

    /** What the plan asks for in the given month, or 0 outside its run. */
    public function paymentFor(PaymentPlan $plan, CarbonInterface $month): int
    {
        return $this->scheduleRow($plan, $month)['payment'] ?? 0;
    }

    /**
     * How the given month's payment splits.
     *
     * @return array{payment: int, interest: int, principal: int, balance: int}|null
     */
    public function scheduleRow(PaymentPlan $plan, CarbonInterface $month): ?array
    {
        if (! $this->isBilledIn($plan, $month)) {
            return null;
        }

        $index = (int) $this->firstOfMonth($plan->start_month)->diffInMonths($this->firstOfMonth($month));

        return $this->schedule($plan)[$index];
    }

    /** What it would take to clear the plan today. */
    public function remainingBalance(PaymentPlan $plan, CarbonInterface $asOf): int
    {
        $left = $this->monthsLeft($plan, $asOf);

        if ($left === 0) {
            return 0;
        }

        $paid = $plan->total_months - $left;

        if ($paid === 0) {
            return $this->interestTypeOf($plan) === InterestType::Amortized
                ? $plan->principal
                : $plan->monthly_amount * $plan->total_months;
        }

        return $this->schedule($plan)[$paid - 1]['balance'] ?? 0;
    }

    /** Interest over the life of the plan. */
    public function totalInterest(PaymentPlan $plan): int
    {
        return array_sum(array_column($this->schedule($plan), 'interest'));
    }

    /** Only amortized interest shrinks when you pay ahead. Add-on interest is already fixed. */
    public function earlyPayoffSavesInterest(PaymentPlan $plan): bool
    {
        return $this->interestTypeOf($plan) === InterestType::Amortized;
    }

    /**
     * What a plan would cost over its life, for showing before it is saved.
     *
     * @return array{
     *     interestType: string,
     *     monthlyAmount: int,
     *     finalPayment: int,
     *     totalRepayment: int,
     *     totalInterest: int,
     *     earlyPayoffSavesInterest: bool,
     *     repaysLessThanBorrowed: bool,
     * }
     */
    public function planSummary(PaymentPlan $plan): array
    {
        $schedule = $this->schedule($plan);
        $totalRepayment = array_sum(array_column($schedule, 'payment'));

        return [
            'interestType' => $this->interestTypeOf($plan)->value,
            'monthlyAmount' => $plan->monthly_amount,
            'finalPayment' => (int) (collect($schedule)->last()['payment'] ?? 0),
            'totalRepayment' => $totalRepayment,
            'totalInterest' => $this->totalInterest($plan),
            'earlyPayoffSavesInterest' => $this->earlyPayoffSavesInterest($plan),
            'repaysLessThanBorrowed' => $this->interestTypeOf($plan) === InterestType::AddOn && $totalRepayment < $plan->principal,
        ];
    }

    /** The level monthly payment that clears a principal in the given months. */
    public function amortizedPayment(int $principal, float $monthlyRatePercent, int $months): int
    {
        $rate = $monthlyRatePercent / 100;

        if ($rate <= 0) {
            return (int) ceil($principal / $months);
        }

        return (int) round($principal * $rate / (1 - (1 + $rate) ** -$months));
    }

    /**
     * One row per month of the plan's life. "balance" is what is left to clear
     * after that month's payment.
     *
     * @return list<array{payment: int, interest: int, principal: int, balance: int}>
     */
    public function schedule(PaymentPlan $plan): array
    {
        $months = $plan->total_months;
        $rows = [];

        if ($this->interestTypeOf($plan) === InterestType::Amortized) {
            $rate = ($plan->debt->monthly_interest_rate ?? 0) / 100;
            $balance = $plan->principal;

            for ($i = 1; $i <= $months; $i++) {
                $interest = (int) round($balance * $rate);
                $payment = $i === $months ? $balance + $interest : min($plan->monthly_amount, $balance + $interest);
                $principal = $payment - $interest;
                $balance -= $principal;

                $rows[] = ['payment' => $payment, 'interest' => $interest, 'principal' => $principal, 'balance' => $balance];
            }

            return $rows;
        }

        $totalInterest = $this->interestTypeOf($plan) === InterestType::AddOn
            ? max(0, $plan->monthly_amount * $months - $plan->principal)
            : 0;

        for ($i = 1; $i <= $months; $i++) {
            $interest = (int) (round($totalInterest * $i / $months) - round($totalInterest * ($i - 1) / $months));

            $rows[] = [
                'payment' => $plan->monthly_amount,
                'interest' => $interest,
                'principal' => $plan->monthly_amount - $interest,
                'balance' => $plan->monthly_amount * ($months - $i),
            ];
        }

        return $rows;
    }

    // ── Debts ────────────────────────────────────────────────────────────

    /** Payments the debt asks for in a month, from its plans or its target payoff date. */
    public function billedIn(Debt $debt, CarbonInterface $month, CarbonInterface $asOf): int
    {
        if ($debt->status === DebtStatus::PaidOff) {
            return 0;
        }

        $planned = $debt->paymentPlans->sum(fn (PaymentPlan $plan): int => $this->paymentFor($plan, $month));

        return $planned + $this->targetInstallment($debt, $month, $asOf);
    }

    /** What a debt with only a balance and a target date needs each month to land on time. */
    private function targetInstallment(Debt $debt, CarbonInterface $month, CarbonInterface $asOf): int
    {
        $balance = $debt->outstanding_balance ?? 0;

        if ($debt->type !== DebtType::Informal || $balance <= 0 || $debt->target_payoff_date === null) {
            return 0;
        }

        $first = $this->firstOfMonth($asOf);
        $last = $this->firstOfMonth($debt->target_payoff_date)->max($first);
        $month = $this->firstOfMonth($month);

        if ($month->lessThan($first) || $month->greaterThan($last)) {
            return 0;
        }

        $months = (int) $first->diffInMonths($last) + 1;
        $each = (int) ceil($balance / $months);

        return $month->equalTo($last) ? max(0, $balance - $each * ($months - 1)) : $each;
    }

    public function dueThisMonth(Debt $debt, CarbonInterface $today): int
    {
        if ($debt->cardDetail !== null && $debt->status !== DebtStatus::PaidOff) {
            return $this->minimumDue($debt, $today) ?? $this->billedIn($debt, $today, $today);
        }

        return $this->billedIn($debt, $today, $today);
    }

    public function paidInMonth(Debt $debt, CarbonInterface $month): int
    {
        $month = $this->firstOfMonth($month);

        return $debt->payments
            ->filter(fn (DebtPayment $payment): bool => $this->firstOfMonth($payment->date)->equalTo($month))
            ->sum('amount');
    }

    /** Plan balances plus any outstanding or revolving balance. */
    public function totalRemaining(Debt $debt, CarbonInterface $asOf): int
    {
        if ($debt->status === DebtStatus::PaidOff) {
            return 0;
        }

        return $this->plansRemaining($debt, $asOf)
            + $this->billedInstallments($debt, $asOf)
            + ($debt->outstanding_balance ?? 0)
            + ($debt->cardDetail->revolving_balance ?? 0);
    }

    public function plansRemaining(Debt $debt, CarbonInterface $asOf): int
    {
        return $debt->paymentPlans->sum(fn (PaymentPlan $plan): int => $this->remainingBalance($plan, $asOf));
    }

    public function nextDueDate(Debt $debt, CarbonInterface $today): ?CarbonImmutable
    {
        if ($this->totalRemaining($debt, $today) === 0) {
            return null;
        }

        if ($debt->cardDetail !== null) {
            return $this->nextCardDueDate($debt, $today);
        }

        if ($debt->due_day === null) {
            return null;
        }

        $today = CarbonImmutable::instance($today)->startOfDay();
        $due = $this->dayInMonth($today, $debt->due_day);

        return $due->lessThan($today) ? $this->dayInMonth($today->addMonthNoOverflow(), $debt->due_day) : $due;
    }

    /**
     * Total of every debt's payments this month. Feeds the Overview's debt number.
     *
     * @param  Collection<int, Debt>  $debts
     */
    public function totalMonthlyPayment(Collection $debts, CarbonInterface $today): int
    {
        return $debts->sum(fn (Debt $debt): int => $this->dueThisMonth($debt, $today));
    }

    /**
     * Monthly debt obligations across the given debts.
     *
     * @param  Collection<int, Debt>  $debts
     * @return list<array{month: string, total: int}>
     */
    public function rollOff(Collection $debts, CarbonInterface $from, int $months = self::ROLL_OFF_MONTHS): array
    {
        $start = $this->firstOfMonth($from);
        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);

            $series[] = [
                'month' => $month->format('Y-m'),
                'total' => $debts->sum(fn (Debt $debt): int => $this->billedIn($debt, $month, $from)),
            ];
        }

        return $series;
    }

    // ── Cards ────────────────────────────────────────────────────────────

    /**
     * Share of the credit limit in use: the revolving balance plus what is
     * still owed on installments. Null when the debt is not a card or has no limit.
     */
    public function utilization(Debt $debt, CarbonInterface $asOf): ?float
    {
        $card = $debt->cardDetail;

        if ($card === null || $card->credit_limit <= 0) {
            return null;
        }

        return ($card->revolving_balance + $this->plansRemaining($debt, $asOf) + $this->billedInstallments($debt, $asOf)) / $card->credit_limit;
    }

    /**
     * The next statement that has not closed yet and the due date that goes with it.
     *
     * @return array{closes_on: CarbonImmutable, due_on: CarbonImmutable, month: CarbonImmutable}
     */
    public function nextStatement(Debt $debt, CarbonInterface $today): array
    {
        return $this->statementClosingOn($debt, $this->nextClosingDate($debt->cardDetail->statement_day, $today));
    }

    /** The day the latest statement closed, before today. */
    public function latestClosingDate(int $statementDay, CarbonInterface $today): CarbonImmutable
    {
        return $this->dayInMonth(
            $this->nextClosingDate($statementDay, $today)->subMonthNoOverflow(),
            $statementDay,
        );
    }

    /** The next day a statement closes, today included. */
    public function nextClosingDate(int $statementDay, CarbonInterface $today): CarbonImmutable
    {
        $today = CarbonImmutable::instance($today)->startOfDay();
        $closesOn = $this->dayInMonth($today, $statementDay);

        return $today->greaterThan($closesOn)
            ? $this->dayInMonth($today->addMonthNoOverflow(), $statementDay)
            : $closesOn;
    }

    /**
     * The statement that closes on the given day and the due date that goes with it.
     *
     * @return array{closes_on: CarbonImmutable, due_on: CarbonImmutable, month: CarbonImmutable}
     */
    private function statementClosingOn(Debt $debt, CarbonImmutable $closesOn): array
    {
        $statementDay = $debt->cardDetail->statement_day;
        $dueDay = $debt->due_day ?? $statementDay;
        $daysAfter = $debt->cardDetail->due_days_after_statement;

        $dueMonth = $dueDay > $statementDay ? $closesOn : $closesOn->addMonthNoOverflow();

        return [
            'closes_on' => $closesOn,
            'due_on' => $daysAfter !== null ? $closesOn->addDays($daysAfter) : $this->dayInMonth($dueMonth, $dueDay),
            'month' => $this->firstOfMonth($closesOn),
        ];
    }

    /**
     * The next payment deadline on a card. A statement that has already closed
     * stays the one to pay until its due date passes, so that comes before the
     * next statement's.
     */
    private function nextCardDueDate(Debt $debt, CarbonInterface $today): CarbonImmutable
    {
        $today = CarbonImmutable::instance($today)->startOfDay();
        $upcoming = $this->nextStatement($debt, $today);
        $pending = $this->pendingStatement($debt, $today);

        return $pending['due_on'] ?? $upcoming['due_on'];
    }

    /**
     * The statement that has closed but whose due date has not passed yet.
     *
     * @return array{closes_on: CarbonImmutable, due_on: CarbonImmutable, month: CarbonImmutable}|null
     */
    private function pendingStatement(Debt $debt, CarbonImmutable $today): ?array
    {
        $pending = $this->statementClosingOn($debt, $this->latestClosingDate($debt->cardDetail->statement_day, $today));

        return $pending['due_on']->lessThan($today) ? null : $pending;
    }

    /**
     * Installment payments already billed on a statement that is still to be
     * paid. They are on the card's total due, but the plans' remaining balance
     * only counts payments from this month on, so they would otherwise be
     * owed without showing anywhere. A statement billed this month is already
     * in that remaining balance.
     */
    public function billedInstallments(Debt $debt, CarbonInterface $asOf): int
    {
        if ($debt->cardDetail === null) {
            return 0;
        }

        $today = CarbonImmutable::instance($asOf)->startOfDay();
        $pending = $this->pendingStatement($debt, $today);

        if ($pending === null || ! $pending['month']->lessThan($this->firstOfMonth($today))) {
            return 0;
        }

        return $debt->paymentPlans->sum(fn (PaymentPlan $plan): int => $this->paymentFor($plan, $pending['month']));
    }

    /**
     * Plans billed on the next statement plus the revolving balance.
     * Transactions join this in a later phase.
     */
    public function projectedNextStatement(Debt $debt, CarbonInterface $today): int
    {
        $statement = $this->nextStatement($debt, $today);

        return $debt->paymentPlans->sum(fn (PaymentPlan $plan): int => $this->paymentFor($plan, $statement['month']))
            + $debt->cardDetail->revolving_balance;
    }

    /**
     * The least the bank will accept on the statement to pay now: the one that
     * has closed and is not yet due, otherwise the next one. Follows the
     * usual Philippine rule: the percent of the balance, plus the finance and
     * late charges and every installment flagged as part of the minimum, each
     * in full, and never below the card's lowest minimum. The percent applies
     * to the revolving balance without the charges. Null when the card has no
     * minimum set up.
     */
    public function minimumDue(Debt $debt, CarbonInterface $today): ?int
    {
        $card = $debt->cardDetail;
        $statement = $this->pendingStatement($debt, CarbonImmutable::instance($today)->startOfDay()) ?? $this->nextStatement($debt, $today);
        $month = $statement['month'];

        $installments = $debt->paymentPlans
            ->filter(fn (PaymentPlan $plan): bool => $plan->in_minimum)
            ->sum(fn (PaymentPlan $plan): int => $this->paymentFor($plan, $month));
        $fixed = $installments + $card->charges_due_in_full;

        if ($card->min_payment_percent === null && $card->min_payment_floor === null) {
            return $fixed > 0 ? $fixed : null;
        }

        $share = (int) ceil(max(0, $card->revolving_balance - $card->charges_due_in_full) * ($card->min_payment_percent ?? 0) / 100);
        $sum = $share + $fixed;

        return $card->revolving_balance === 0 && $fixed === 0 ? 0 : max($card->min_payment_floor ?? 0, $sum);
    }

    private function firstOfMonth(CarbonInterface $date): CarbonImmutable
    {
        return CarbonImmutable::instance($date)->startOfMonth();
    }

    /** Clamps to the last day of short months, so day 31 lands on the 30th or 28th. */
    private function dayInMonth(CarbonInterface $month, int $day): CarbonImmutable
    {
        $first = $this->firstOfMonth($month);

        return $first->addDays(min($day, $first->daysInMonth) - 1);
    }
}
