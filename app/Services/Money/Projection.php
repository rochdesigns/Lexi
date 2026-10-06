<?php

namespace App\Services\Money;

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\BillFrequency;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\Goal;
use App\Models\Money\GoalKind;
use App\Models\Money\IncomeSource;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Models\Money\Transaction;
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

    // ── Overview ─────────────────────────────────────────────────────────

    /**
     * A semi-monthly source pays its amount on both pay days.
     *
     * @param  Collection<int, IncomeSource>  $sources
     */
    public function monthlyIncome(Collection $sources): int
    {
        return $sources->sum(fn (IncomeSource $source): int => $source->amount * count($source->payDays()));
    }

    /**
     * @param  Collection<int, Bill>  $bills
     */
    public function monthlyBills(Collection $bills): int
    {
        return $bills
            ->filter(fn (Bill $bill): bool => $bill->frequency === BillFrequency::Monthly)
            ->sum('amount');
    }

    /**
     * Annual bills are saved at one twelfth a month, rounded up per bill so
     * twelve months of saving never fall short of the bill.
     *
     * @param  Collection<int, Bill>  $bills
     */
    public function annualSetAside(Collection $bills): int
    {
        return $bills
            ->filter(fn (Bill $bill): bool => $bill->frequency === BillFrequency::Annual)
            ->sum(fn (Bill $bill): int => (int) ceil($bill->amount / 12));
    }

    /**
     * The five numbers for the month. The set-aside is the yearly bills at a twelfth a month plus the contributions to sinking funds. Safe to spend is what is left of income
     * once bills, the annual set-aside, debt payments, the buffer contribution
     * and any recovery payment are covered, and can be negative. The buffer is zero until
     * goals exist.
     *
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     * @param  Collection<int, IncomeSource>  $sources
     * @return array{income: int, expenses: int, setAside: int, debtPayment: int, bufferContribution: int, recoveryContribution: int, safeToSpend: int, perDay: int}
     */
    public function overview(Collection $debts, Collection $bills, Collection $sources, CarbonInterface $today, int $bufferContribution = 0, ?int $recoveryMonths = null, int $sinkingContribution = 0): array
    {
        $income = $this->monthlyIncome($sources);
        $expenses = $this->monthlyBills($bills);
        $setAside = $this->annualSetAside($bills) + $sinkingContribution;
        $debtPayment = $this->totalMonthlyPayment($debts, $today);
        $recoveryContribution = $recoveryMonths === null ? 0 : $this->recoveryPlan($debts, $recoveryMonths, $today)['extra'];
        $safeToSpend = $income - $expenses - $setAside - $debtPayment - $bufferContribution - $recoveryContribution;

        return [
            'income' => $income,
            'expenses' => $expenses,
            'setAside' => $setAside,
            'debtPayment' => $debtPayment,
            'bufferContribution' => $bufferContribution,
            'recoveryContribution' => $recoveryContribution,
            'safeToSpend' => $safeToSpend,
            'perDay' => $this->dailyAllowance($safeToSpend, $today),
        ];
    }

    /**
     * What is on hand: the balances of spendable accounts only. Savings kept
     * aside are left out.
     *
     * @param  Collection<int, Account>  $accounts
     */
    public function cashAvailable(Collection $accounts): int
    {
        return $accounts
            ->filter(fn (Account $account): bool => $account->is_spendable)
            ->sum('balance');
    }

    /** Safe to spend spread over the days left in the month, today included. Never negative. */
    public function dailyAllowance(int $safeToSpend, CarbonInterface $today): int
    {
        $today = CarbonImmutable::instance($today)->startOfDay();
        $daysLeft = (int) $today->diffInDays($today->endOfMonth()->startOfDay()) + 1;

        return intdiv(max(0, $safeToSpend), $daysLeft);
    }

    public function nextBillDate(Bill $bill, CarbonInterface $today): CarbonImmutable
    {
        $today = CarbonImmutable::instance($today)->startOfDay();

        if ($bill->frequency === BillFrequency::Monthly) {
            $due = $this->dayInMonth($today, $bill->due_day);

            return $due->lessThan($today) ? $this->dayInMonth($today->addMonthNoOverflow(), $bill->due_day) : $due;
        }

        $due = $this->dayInMonth($today->setDate($today->year, $bill->due_month, 1), $bill->due_day);

        return $due->lessThan($today) ? $this->dayInMonth($due->addYearNoOverflow(), $bill->due_day) : $due;
    }

    /**
     * Debt dues, bills and pay days falling from today through the next
     * $days days, soonest first.
     *
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     * @param  Collection<int, IncomeSource>  $sources
     * @return list<array{kind: string, name: string, amount: int, date: string, autopay: bool, sourceId: int|null, billId: int|null, received: bool}>
     */
    public function upcoming(Collection $debts, Collection $bills, Collection $sources, CarbonInterface $today, int $days = 14): array
    {
        $items = $this->events($debts, $bills, $sources, $today, $days);

        usort($items, fn (array $a, array $b): int => [$a['date'], $a['kind'], $a['name']] <=> [$b['date'], $b['kind'], $b['name']]);

        return $items;
    }

    /**
     * Where the money runs out. Starts from cash on hand and walks the next
     * $days days: pay days add money, bills and debt dues take it away, and pay
     * days count before dues on the same day. Each due that leaves the balance
     * below zero is flagged with how much of it cannot be covered. Without any
     * account there is no balance to check, so nothing is flagged.
     *
     * A debt is checked on every due date in the window.
     *
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     * @param  Collection<int, IncomeSource>  $sources
     * @param  Collection<int, Account>  $accounts
     * @return list<array{kind: string, name: string, amount: int, date: string, short: int}>
     */
    public function shortfalls(Collection $debts, Collection $bills, Collection $sources, Collection $accounts, CarbonInterface $today, int $days = 30): array
    {
        if ($accounts->isEmpty()) {
            return [];
        }

        $flagged = array_filter(
            $this->settle($this->events($debts, $bills, $sources, $today, $days), $this->cashAvailable($accounts)),
            fn (array $event): bool => $event['short'] > 0,
        );

        return array_values(array_map(fn (array $event): array => [
            'kind' => $event['kind'],
            'name' => $event['name'],
            'amount' => $event['amount'],
            'date' => $event['date'],
            'short' => $event['short'],
        ], $flagged));
    }

    /**
     * One month of the cash flow calendar, from the balance on hand today. The
     * walk starts today and carries on through every month up to the one asked
     * for, so a gap in an earlier month shows in the later ones too. Days
     * before today have nothing, as they are already past. Each due that the
     * cash cannot cover is flagged short, with the next payday after it.
     *
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     * @param  Collection<int, IncomeSource>  $sources
     * @param  Collection<int, Account>  $accounts
     * @return array{days: list<array{date: string, items: list<array{kind: string, name: string, amount: int, autopay: bool, sourceId: int|null, received: bool, short: int}>}>, gaps: list<array{kind: string, name: string, amount: int, date: string, short: int, nextPayday: string|null}>}
     */
    public function calendar(Collection $debts, Collection $bills, Collection $sources, Collection $accounts, CarbonInterface $today, string $month): array
    {
        $start = CarbonImmutable::instance($today)->startOfDay();
        $first = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfDay();
        $last = $first->endOfMonth()->startOfDay();
        $events = $this->events($debts, $bills, $sources, $start, max(0, (int) $start->diffInDays($last)));
        $settled = $this->settle($events, $accounts->isEmpty() ? null : $this->cashAvailable($accounts));
        $inMonth = array_filter($settled, fn (array $event): bool => $event['date'] >= $first->toDateString() && $event['date'] <= $last->toDateString());

        $days = [];
        $gaps = [];

        foreach ($inMonth as $event) {
            $days[$event['date']][] = [
                'kind' => $event['kind'],
                'name' => $event['name'],
                'amount' => $event['amount'],
                'autopay' => $event['autopay'],
                'sourceId' => $event['sourceId'],
                'received' => $event['received'],
                'short' => $event['short'],
            ];

            if ($event['short'] > 0) {
                $gaps[] = [
                    'kind' => $event['kind'],
                    'name' => $event['name'],
                    'amount' => $event['amount'],
                    'date' => $event['date'],
                    'short' => $event['short'],
                    'nextPayday' => $this->nextPayday($sources, CarbonImmutable::parse($event['date']))?->toDateString(),
                ];
            }
        }

        ksort($days);

        return [
            'days' => array_map(fn (string $date): array => ['date' => $date, 'items' => $days[$date]], array_keys($days)),
            'gaps' => $gaps,
        ];
    }

    /**
     * The first pay day after the given date, or null without any income.
     *
     * @param  Collection<int, IncomeSource>  $sources
     */
    public function nextPayday(Collection $sources, CarbonImmutable $after): ?CarbonImmutable
    {
        $dates = [];

        foreach ($sources as $source) {
            foreach ($source->payDays() as $payDay) {
                foreach ([0, 1] as $offset) {
                    $date = $this->dayInMonth($after->startOfMonth()->addMonths($offset), $payDay);

                    if ($date->greaterThan($after)) {
                        $dates[] = $date;
                    }
                }
            }
        }

        return $dates === [] ? null : collect($dates)->sort()->first();
    }

    /**
     * Walks the events in date order, pay days before dues on the same day, and
     * works out how much of each due the running balance cannot cover. With a
     * null starting balance there is nothing to check, so nothing is short.
     *
     * @param  list<array{kind: string, name: string, amount: int, date: string, autopay: bool, sourceId: int|null, billId: int|null, received: bool}>  $events
     * @return list<array{kind: string, name: string, amount: int, date: string, autopay: bool, sourceId: int|null, billId: int|null, received: bool, short: int}>
     */
    private function settle(array $events, ?int $cash): array
    {
        usort($events, fn (array $a, array $b): int => [$a['date'], $a['kind'] === 'income' ? 0 : 1, $a['name']] <=> [$b['date'], $b['kind'] === 'income' ? 0 : 1, $b['name']]);

        $balance = $cash ?? 0;
        $settled = [];

        foreach ($events as $event) {
            $short = 0;

            if ($event['kind'] === 'income') {
                $balance += $event['received'] ? 0 : $event['amount'];
            } else {
                $balance -= $event['amount'];
                $short = $cash === null ? 0 : max(0, min($event['amount'], -$balance));
            }

            $settled[] = [...$event, 'short' => $short];
        }

        return $settled;
    }

    /**
     * Whether a pay day has already landed. Income logged for that pay day
     * counts, and so does income logged without one within three days of it,
     * as pay often arrives a little early or late. A received pay day is not
     * added to the forecast again, since the money is already in the balance.
     */
    private function isReceived(IncomeSource $source, CarbonImmutable $payday): bool
    {
        return $source->receipts->contains(function (Transaction $receipt) use ($payday): bool {
            if ($receipt->pay_date !== null) {
                return $receipt->pay_date->toDateString() === $payday->toDateString();
            }

            return abs($receipt->date->diffInDays($payday, false)) <= 3;
        });
    }

    /**
     * Every due date of a debt from today through $end, with what is due.
     * The first is the next due date: a card's real minimum, a loan's payment
     * for that month. Later ones use only what the plans bill that month, since
     * a card's minimum on a statement that has not closed yet is not known.
     *
     * @return list<array{date: string, amount: int}>
     */
    private function debtDues(Debt $debt, CarbonImmutable $start, CarbonImmutable $end): array
    {
        $date = $this->nextDueDate($debt, $start);

        if ($date === null) {
            return [];
        }

        $isCard = $debt->cardDetail !== null;
        $amount = $isCard ? $this->dueThisMonth($debt, $start) : $this->billedIn($debt, $date, $start);
        $closes = $isCard ? ($this->pendingStatement($debt, $start) ?? $this->nextStatement($debt, $start))['closes_on'] : null;
        $dues = [];

        for ($i = 0; $i < 60 && $date->lessThanOrEqualTo($end); $i++) {
            if ($amount > 0) {
                $dues[] = ['date' => $date->toDateString(), 'amount' => $amount];
            }

            if ($isCard) {
                $closes = $this->dayInMonth($closes->addMonthNoOverflow(), $debt->cardDetail->statement_day);
                $statement = $this->statementClosingOn($debt, $closes);
                $date = $statement['due_on'];
                $amount = $this->billedIn($debt, $statement['month'], $start);
            } else {
                $date = $this->dayInMonth($date->startOfMonth()->addMonthNoOverflow(), $debt->due_day);
                $amount = $this->billedIn($debt, $date, $start);
            }
        }

        return $dues;
    }

    /**
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     * @param  Collection<int, IncomeSource>  $sources
     * @return list<array{kind: string, name: string, amount: int, date: string, autopay: bool, sourceId: int|null, billId: int|null, received: bool}>
     */
    private function events(Collection $debts, Collection $bills, Collection $sources, CarbonInterface $today, int $days): array
    {
        $start = CarbonImmutable::instance($today)->startOfDay();
        $end = $start->addDays($days);
        $months = (int) $start->startOfMonth()->diffInMonths($end->startOfMonth());
        $items = [];

        foreach ($debts as $debt) {
            foreach ($this->debtDues($debt, $start, $end) as $due) {
                $items[] = ['kind' => 'debt', 'name' => $debt->name, 'amount' => $due['amount'], 'date' => $due['date'], 'autopay' => false, 'sourceId' => null, 'billId' => null, 'received' => false];
            }
        }

        foreach ($bills as $bill) {
            $bill->loadMissing('payments');
            $dates = [$this->nextBillDate($bill, $start)];

            if ($bill->frequency === BillFrequency::Monthly) {
                $dates = array_map(fn (int $offset): CarbonImmutable => $this->dayInMonth($start->startOfMonth()->addMonths($offset), $bill->due_day), range(0, $months));
            }

            foreach ($dates as $date) {
                if ($date->greaterThanOrEqualTo($start) && $date->lessThanOrEqualTo($end)) {
                    $items[] = ['kind' => 'bill', 'name' => $bill->name, 'amount' => $bill->amount, 'date' => $date->toDateString(), 'autopay' => $bill->autopay, 'sourceId' => null, 'billId' => $bill->id, 'received' => $bill->isPaidInMonthOf($date)];
                }
            }
        }

        foreach ($sources as $source) {
            foreach ($source->payDays() as $payDay) {
                foreach (range(0, $months) as $offset) {
                    $date = $this->dayInMonth($start->startOfMonth()->addMonths($offset), $payDay);

                    if ($date->greaterThanOrEqualTo($start) && $date->lessThanOrEqualTo($end)) {
                        $items[] = ['kind' => 'income', 'name' => $source->name, 'amount' => $source->amount, 'date' => $date->toDateString(), 'autopay' => false, 'sourceId' => $source->id, 'billId' => null, 'received' => $this->isReceived($source, $date)];
                    }
                }
            }
        }

        return $items;
    }

    // ── Goals ────────────────────────────────────────────────────────────

    /**
     * What has to be paid every month: the monthly bills, the yearly bills set
     * aside at a twelfth, and the debt payments due this month. A buffer is
     * sized in months of this, so it shrinks as installments roll off.
     *
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     */
    public function monthlyObligations(Collection $debts, Collection $bills, CarbonInterface $today): int
    {
        return $this->monthlyBills($bills) + $this->annualSetAside($bills) + $this->totalMonthlyPayment($debts, $today);
    }

    public function goalTarget(Goal $goal, int $obligations): int
    {
        return $goal->kind === GoalKind::Buffer ? ($goal->buffer_months ?? 0) * $obligations : ($goal->target_amount ?? 0);
    }

    /** What has been saved: the linked account's balance, otherwise the typed amount. */
    public function goalSaved(Goal $goal): int
    {
        return max(0, data_get($goal, 'account.balance') ?? $goal->saved);
    }

    /**
     * Where a goal stands against its target. Months to go counts the
     * contributions still needed from this month, and is null when nothing is
     * being put in. A sinking fund with a date suggests the monthly amount that
     * finishes it on time, from this month up to the month before the date.
     *
     * @return array{saved: int, target: int, remaining: int, percent: int, monthsToGo: int|null, finishMonth: string|null, suggestedContribution: int|null}
     */
    public function goalProgress(Goal $goal, int $target, CarbonInterface $today): array
    {
        $saved = $this->goalSaved($goal);
        $remaining = max(0, $target - $saved);
        $monthsToGo = match (true) {
            $remaining === 0 => 0,
            $goal->monthly_contribution <= 0 => null,
            default => (int) ceil($remaining / $goal->monthly_contribution),
        };
        $suggested = null;

        if ($goal->kind === GoalKind::Sinking && $goal->target_date !== null && $remaining > 0) {
            $months = max(1, (int) $this->firstOfMonth($today)->diffInMonths($this->firstOfMonth($goal->target_date), false));
            $suggested = (int) ceil($remaining / $months);
        }

        return [
            'saved' => $saved,
            'target' => $target,
            'remaining' => $remaining,
            'percent' => $target > 0 ? min(100, intdiv($saved * 100, $target)) : 100,
            'monthsToGo' => $monthsToGo,
            'finishMonth' => $monthsToGo !== null && $monthsToGo > 0 ? $this->firstOfMonth($today)->addMonths($monthsToGo - 1)->format('Y-m') : null,
            'suggestedContribution' => $suggested,
        ];
    }

    /**
     * What goes into goals each month, split into the buffer and the sinking
     * funds. A goal that has reached its target stops contributing.
     *
     * @param  Collection<int, Goal>  $goals
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     * @return array{buffer: int, sinking: int}
     */
    public function goalContributions(Collection $goals, Collection $debts, Collection $bills, CarbonInterface $today): array
    {
        $totals = ['buffer' => 0, 'sinking' => 0];

        if ($goals->isEmpty()) {
            return $totals;
        }

        $obligations = $this->monthlyObligations($debts, $bills, $today);

        foreach ($goals as $goal) {
            if ($this->goalSaved($goal) >= $this->goalTarget($goal, $obligations)) {
                continue;
            }

            $totals[$goal->kind === GoalKind::Buffer ? 'buffer' : 'sinking'] += $goal->monthly_contribution;
        }

        return $totals;
    }

    // ── What if ──────────────────────────────────────────────────────────

    /**
     * Safe to spend for each of the next months, the way the Overview works it
     * out for this one. This month uses the real debt payments, with a card's
     * minimum. Later months only know what plans bill, so a card's minimum on
     * a balance carried over is not in them. $extraByMonth ("Y-m" => centavos)
     * takes more out of a month, for trying a change without saving it.
     *
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     * @param  Collection<int, IncomeSource>  $sources
     * @param  array{buffer: int, sinking: int}  $contributions
     * @param  array<string, int>  $extraByMonth
     * @return list<array{month: string, safeToSpend: int}>
     */
    public function safeToSpendByMonth(Collection $debts, Collection $bills, Collection $sources, CarbonInterface $today, array $contributions, ?int $recoveryMonths, array $extraByMonth = [], int $months = 12): array
    {
        $fixed = $this->monthlyIncome($sources)
            - $this->monthlyBills($bills)
            - $this->annualSetAside($bills)
            - $contributions['sinking']
            - $contributions['buffer']
            - ($recoveryMonths === null ? 0 : $this->recoveryPlan($debts, $recoveryMonths, $today)['extra']);
        $start = $this->firstOfMonth($today);
        $series = [];

        for ($i = 0; $i < $months; $i++) {
            $month = $start->addMonths($i);
            $debtPayment = $i === 0 ? $this->totalMonthlyPayment($debts, $today) : $debts->sum(fn (Debt $debt): int => $this->billedIn($debt, $month, $today));

            $series[] = ['month' => $month->format('Y-m'), 'safeToSpend' => $fixed - $debtPayment - ($extraByMonth[$month->format('Y-m')] ?? 0)];
        }

        return $series;
    }

    /**
     * What a new 0% installment would do to safe to spend in each of the next
     * months. On a card it starts with the next statement, so the first payment
     * falls in the month that statement is due. Without a card it starts in the
     * month given. Any month that ends up below zero is flagged short.
     *
     * @param  Collection<int, Debt>  $debts
     * @param  Collection<int, Bill>  $bills
     * @param  Collection<int, IncomeSource>  $sources
     * @param  array{buffer: int, sinking: int}  $contributions
     * @return array{monthlyAmount: int, firstMonth: string, lastMonth: string, rows: list<array{month: string, before: int, after: int, short: bool, newlyShort: bool}>, shortMonths: list<string>}
     */
    public function affordability(Collection $debts, Collection $bills, Collection $sources, CarbonInterface $today, array $contributions, ?int $recoveryMonths, int $price, int $months, ?Debt $card, ?CarbonInterface $startMonth): array
    {
        $first = $card !== null && $card->cardDetail !== null
            ? $this->firstOfMonth($this->nextStatement($card, $today)['due_on'])
            : $this->firstOfMonth($startMonth ?? $today);
        $monthly = (int) ceil($price / $months);
        $extra = [];

        for ($i = 0; $i < $months; $i++) {
            $extra[$first->addMonths($i)->format('Y-m')] = $i === $months - 1 ? $price - $monthly * ($months - 1) : $monthly;
        }

        $before = $this->safeToSpendByMonth($debts, $bills, $sources, $today, $contributions, $recoveryMonths);
        $after = $this->safeToSpendByMonth($debts, $bills, $sources, $today, $contributions, $recoveryMonths, $extra);

        $rows = array_map(fn (array $was, array $now): array => [
            'month' => $now['month'],
            'before' => $was['safeToSpend'],
            'after' => $now['safeToSpend'],
            'short' => $now['safeToSpend'] < 0,
            'newlyShort' => $now['safeToSpend'] < 0 && $was['safeToSpend'] >= 0,
        ], $before, $after);

        return [
            'monthlyAmount' => $monthly,
            'firstMonth' => $first->format('Y-m'),
            'lastMonth' => $first->addMonths($months - 1)->format('Y-m'),
            'rows' => $rows,
            'shortMonths' => array_column(array_filter($rows, fn (array $row): bool => $row['short']), 'month'),
        ];
    }

    /**
     * Where spare cash does the most good: plans ranked by remaining balance
     * divided by the monthly payment, the fastest to finish first. Walks the
     * ranking and pays off each plan the money still covers in full, which
     * frees its monthly payment. A plan with add-on interest is flagged, since
     * paying it early does not save interest.
     *
     * @param  Collection<int, Debt>  $debts
     * @return array{amount: int, spent: int, leftover: int, freedMonthly: int, plans: list<array{planId: int, description: string, debtName: string, remaining: int, monthlyAmount: int, monthsLeft: int, paysOff: bool, interestType: string, savesInterest: bool}>}
     */
    public function extraCashPlan(Collection $debts, int $amount, CarbonInterface $today): array
    {
        $plans = $debts
            ->flatMap(fn (Debt $debt) => $debt->paymentPlans->map(fn (PaymentPlan $plan): array => [
                'plan' => $plan,
                'debtName' => $debt->name,
                'remaining' => $this->remainingBalance($plan, $today),
            ]))
            ->filter(fn (array $row): bool => $row['remaining'] > 0 && $row['plan']->monthly_amount > 0)
            ->sort(fn (array $a, array $b): int => [$a['remaining'] / $a['plan']->monthly_amount, $a['remaining'], $a['plan']->id] <=> [$b['remaining'] / $b['plan']->monthly_amount, $b['remaining'], $b['plan']->id])
            ->values();

        $money = max(0, $amount);
        $freed = 0;
        $rows = [];

        foreach ($plans as $row) {
            $plan = $row['plan'];
            $paysOff = $row['remaining'] <= $money;

            if ($paysOff) {
                $money -= $row['remaining'];
                $freed += $plan->monthly_amount;
            }

            $rows[] = [
                'planId' => $plan->id,
                'description' => $plan->description,
                'debtName' => $row['debtName'],
                'remaining' => $row['remaining'],
                'monthlyAmount' => $plan->monthly_amount,
                'monthsLeft' => $this->monthsLeft($plan, $today),
                'paysOff' => $paysOff,
                'interestType' => $this->interestTypeOf($plan)->value,
                'savesInterest' => $this->earlyPayoffSavesInterest($plan),
            ];
        }

        return [
            'amount' => max(0, $amount),
            'spent' => max(0, $amount) - $money,
            'leftover' => $money,
            'freedMonthly' => $freed,
            'plans' => $rows,
        ];
    }

    /**
     * A card's carried balance cleared two ways with the same monthly payment:
     * converted to a fixed-rate installment (interest is a flat share of the
     * balance every month), or left revolving at the card's own rate.
     *
     * @return array{balance: int, monthlyPayment: int, conversion: array{months: int, totalInterest: int, totalPaid: int}, revolving: array{months: int, totalInterest: int, totalPaid: int, neverClears: bool}, cheaper: string, interestSaved: int}|null
     */
    public function conversionComparison(Debt $card, int $months, float $addOnRatePercent, CarbonInterface $today): ?array
    {
        $balance = $card->cardDetail->revolving_balance ?? 0;

        if ($balance <= 0) {
            return null;
        }

        $monthlyInterest = (int) ceil($balance * $addOnRatePercent / 100);
        $monthly = (int) ceil($balance / $months) + $monthlyInterest;
        $conversionInterest = $monthlyInterest * $months;
        $revolving = $this->clearingSchedule($card, $monthly, $today);
        $revolvingInterest = $revolving['neverClears'] ? 0 : $revolving['totalInterest'];

        $cheaper = match (true) {
            $revolving['neverClears'] => 'conversion',
            $revolvingInterest === $conversionInterest => 'same',
            $revolvingInterest < $conversionInterest => 'revolving',
            default => 'conversion',
        };

        return [
            'balance' => $balance,
            'monthlyPayment' => $monthly,
            'conversion' => ['months' => $months, 'totalInterest' => $conversionInterest, 'totalPaid' => $balance + $conversionInterest],
            'revolving' => ['months' => $revolving['months'], 'totalInterest' => $revolvingInterest, 'totalPaid' => $revolving['totalPaid'], 'neverClears' => $revolving['neverClears']],
            'cheaper' => $cheaper,
            'interestSaved' => $revolving['neverClears'] ? 0 : abs($revolvingInterest - $conversionInterest),
        ];
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
     * Plans billed on the next statement, the revolving balance, and what has
     * been charged to the card since the last statement closed. Charges up to
     * and including that closing day are already in the balance, and ones after
     * the next closing day belong to the statement after. Needs the card's
     * transactions loaded.
     */
    public function projectedNextStatement(Debt $debt, CarbonInterface $today): int
    {
        $statement = $this->nextStatement($debt, $today);
        $lastClosed = $this->latestClosingDate($debt->cardDetail->statement_day, $today)->toDateString();
        $closesOn = $statement['closes_on']->toDateString();

        $charges = $debt->transactions
            ->filter(fn (Transaction $transaction): bool => $transaction->matched_transaction_id === null && $transaction->date->toDateString() > $lastClosed && $transaction->date->toDateString() <= $closesOn)
            ->sum('amount');

        return $debt->paymentPlans->sum(fn (PaymentPlan $plan): int => $this->paymentFor($plan, $statement['month']))
            + $debt->cardDetail->revolving_balance
            + $charges;
    }

    /**
     * Before spending on a card that already carries a balance: how much it
     * carries, what it costs a month, and the spendable account with the most
     * money to use instead. Null when the card carries nothing.
     *
     * @param  Collection<int, Account>  $accounts
     * @return array{debtId: int, name: string, revolving: int, monthlyRate: float, suggestedAccount: array{id: int, name: string, balance: int}|null}|null
     */
    public function spendingGuard(Debt $card, Collection $accounts): ?array
    {
        $revolving = $card->cardDetail->revolving_balance ?? 0;

        if ($revolving <= 0) {
            return null;
        }

        $best = $accounts
            ->filter(fn (Account $account): bool => $account->is_spendable && $account->balance > 0)
            ->sortByDesc('balance')
            ->first();

        return [
            'debtId' => $card->id,
            'name' => $card->name,
            'revolving' => $revolving,
            'monthlyRate' => (float) ($card->monthly_interest_rate ?? 0),
            'suggestedAccount' => $best === null ? null : ['id' => $best->id, 'name' => $best->name, 'balance' => $best->balance],
        ];
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
        $fixed = $this->installmentsInMinimum($debt, $today) + $card->charges_due_in_full;

        if ($card->min_payment_percent === null && $card->min_payment_floor === null) {
            return $fixed > 0 ? $fixed : null;
        }

        $share = (int) ceil(max(0, $card->revolving_balance - $card->charges_due_in_full) * ($card->min_payment_percent ?? 0) / 100);
        $sum = $share + $fixed;

        return $card->revolving_balance === 0 && $fixed === 0 ? 0 : max($card->min_payment_floor ?? 0, $sum);
    }

    /**
     * Splits what the user can pay this cycle across their cards. Minimums come
     * first, highest rate first, so if the money runs out the unpaid minimum
     * lands on the lowest-rate card. What is left goes to the revolving balance
     * of the highest-rate card, then the next. Installments and charges in the
     * minimum do not reduce the revolving balance, so only the rest of a
     * minimum does. Interest is one month on what is still revolving.
     *
     * @param  Collection<int, Debt>  $debts
     * @return array{available: int, totalMinimums: int, shortBy: int, leftover: int, totalInterest: int, cards: list<array{debtId: int, name: string, rate: float, minimum: int, minimumPaid: int, missedMinimum: int, extra: int, paid: int, revolvingBefore: int, revolvingAfter: int, interest: int}>}
     */
    public function allocatePayment(Collection $debts, int $available, CarbonInterface $today): array
    {
        $cards = $debts
            ->filter(fn (Debt $debt): bool => $debt->cardDetail !== null && $debt->status !== DebtStatus::PaidOff)
            ->map(function (Debt $debt) use ($today): array {
                $minimum = $this->minimumDue($debt, $today) ?? 0;

                return [
                    'debt' => $debt,
                    'rate' => (float) ($debt->monthly_interest_rate ?? 0),
                    'minimum' => $minimum,
                    'fixedPart' => $minimum - $this->minimumTowardsBalance($debt, $today),
                    'revolving' => $debt->cardDetail->revolving_balance,
                ];
            })
            ->filter(fn (array $card): bool => $card['minimum'] > 0 || $card['revolving'] > 0)
            ->sort(fn (array $a, array $b): int => [$b['rate'], $a['debt']->name] <=> [$a['rate'], $b['debt']->name])
            ->values()
            ->all();

        $remaining = max(0, $available);

        foreach ($cards as $index => $card) {
            $paid = min($card['minimum'], $remaining);
            $remaining -= $paid;
            $cards[$index]['minimumPaid'] = $paid;
            $cards[$index]['revolvingLeft'] = max(0, $card['revolving'] - max(0, $paid - $card['fixedPart']));
        }

        foreach ($cards as $index => $card) {
            $extra = min($remaining, $card['revolvingLeft']);
            $remaining -= $extra;
            $cards[$index]['extra'] = $extra;
        }

        $rows = array_values(array_map(function (array $card): array {
            $after = $card['revolvingLeft'] - $card['extra'];

            return [
                'debtId' => $card['debt']->id,
                'name' => $card['debt']->name,
                'rate' => $card['rate'],
                'minimum' => $card['minimum'],
                'minimumPaid' => $card['minimumPaid'],
                'missedMinimum' => $card['minimum'] - $card['minimumPaid'],
                'extra' => $card['extra'],
                'paid' => $card['minimumPaid'] + $card['extra'],
                'revolvingBefore' => $card['revolving'],
                'revolvingAfter' => $after,
                'interest' => (int) ceil($after * $card['rate'] / 100),
            ];
        }, $cards));

        $totalMinimums = array_sum(array_column($rows, 'minimum'));

        return [
            'available' => max(0, $available),
            'totalMinimums' => $totalMinimums,
            'shortBy' => max(0, $totalMinimums - max(0, $available)),
            'leftover' => $remaining,
            'totalInterest' => array_sum(array_column($rows, 'interest')),
            'cards' => $rows,
        ];
    }

    /**
     * The part of the minimum that pays down the revolving balance. Installments
     * and charges in the minimum do not.
     */
    public function minimumTowardsBalance(Debt $debt, CarbonInterface $today): int
    {
        $minimum = $this->minimumDue($debt, $today) ?? 0;

        return max(0, $minimum - $this->installmentsInMinimum($debt, $today) - $debt->cardDetail->charges_due_in_full);
    }

    /**
     * When a card's revolving balance clears if the same amount is paid towards
     * it every month. The first payment is the one due next. After each payment
     * the rest of the balance is charged a month of interest, and the last
     * payment is whatever is left. It never clears when the payment does not
     * outrun that interest.
     *
     * @return array{months: int, clearedBy: string|null, totalPaid: int, totalInterest: int, neverClears: bool}
     */
    public function clearingSchedule(Debt $debt, int $monthlyPayment, CarbonInterface $today): array
    {
        $balance = $debt->cardDetail->revolving_balance;
        $rate = (float) ($debt->monthly_interest_rate ?? 0);
        $months = 0;
        $paid = 0;
        $interest = 0;

        while ($balance > 0) {
            $payment = min($balance, max(0, $monthlyPayment));
            $remaining = $balance - $payment;
            $charge = $remaining > 0 ? (int) ceil($remaining * $rate / 100) : 0;

            if ($months >= 600 || $payment === 0 || $remaining + $charge >= $balance) {
                return ['months' => 0, 'clearedBy' => null, 'totalPaid' => 0, 'totalInterest' => 0, 'neverClears' => true];
            }

            $months++;
            $paid += $payment;
            $interest += $charge;
            $balance = $remaining + $charge;
        }

        $first = $this->firstOfMonth($this->nextDueDate($debt, $today) ?? $today);

        return [
            'months' => $months,
            'clearedBy' => $months > 0 ? $first->addMonths($months - 1)->format('Y-m') : null,
            'totalPaid' => $paid,
            'totalInterest' => $interest,
            'neverClears' => false,
        ];
    }

    /**
     * Recovery mode: what it takes to clear every carried-over card balance in
     * $months months. Each card needs a level payment (with its interest) that
     * clears it in time. The minimum already counted in the debt payment pays
     * part of that, so only the rest is extra to set aside.
     *
     * @param  Collection<int, Debt>  $debts
     * @return array{months: int, carryover: int, extra: int, cards: list<array{debtId: int, name: string, balance: int, payment: int, extra: int}>}
     */
    public function recoveryPlan(Collection $debts, int $months, CarbonInterface $today): array
    {
        $cards = $debts
            ->filter(fn (Debt $debt): bool => $debt->cardDetail !== null && $debt->status !== DebtStatus::PaidOff && $debt->cardDetail->revolving_balance > 0)
            ->map(function (Debt $debt) use ($months, $today): array {
                $balance = $debt->cardDetail->revolving_balance;
                $payment = $this->amortizedPayment($balance, (float) ($debt->monthly_interest_rate ?? 0), $months);

                return [
                    'debtId' => $debt->id,
                    'name' => $debt->name,
                    'balance' => $balance,
                    'payment' => $payment,
                    'extra' => max(0, $payment - $this->minimumTowardsBalance($debt, $today)),
                ];
            })
            ->sortBy('name')
            ->values()
            ->all();

        return [
            'months' => $months,
            'carryover' => array_sum(array_column($cards, 'balance')),
            'extra' => array_sum(array_column($cards, 'extra')),
            'cards' => array_values($cards),
        ];
    }

    /**
     * Cards that have carried a balance over in at least 2 of the last 3
     * months, counting this one, and still carry one now. Worth a call to the
     * bank about a balance conversion or restructuring. A month without a
     * saved balance keeps the last one saved. It needs a balance saved at least
     * two months ago, or there is not enough history to say.
     *
     * @param  Collection<int, Debt>  $debts
     * @return list<array{debtId: int, name: string, monthsCarried: int, balance: int}>
     */
    public function carriedBalanceAlerts(Collection $debts, CarbonInterface $today): array
    {
        $months = array_map(fn (int $ago): string => $this->firstOfMonth($today)->subMonthsNoOverflow($ago)->format('Y-m'), [2, 1, 0]);
        $alerts = [];

        foreach ($debts as $debt) {
            if ($debt->cardDetail === null || $debt->status === DebtStatus::PaidOff) {
                continue;
            }

            $snapshots = $debt->balanceSnapshots->sortBy('month');

            if ($snapshots->isEmpty() || $snapshots->first()->month > $months[0]) {
                continue;
            }

            $carried = collect($months)
                ->map(fn (string $month): int => $snapshots->filter(fn ($snapshot): bool => $snapshot->month <= $month)->last()->revolving_balance)
                ->filter(fn (int $balance): bool => $balance > 0);
            $current = $snapshots->last()->revolving_balance;

            if ($carried->count() >= 2 && $current > 0) {
                $alerts[] = ['debtId' => $debt->id, 'name' => $debt->name, 'monthsCarried' => $carried->count(), 'balance' => $current];
            }
        }

        return $alerts;
    }

    /** Installments flagged as part of the minimum, for the statement being paid. */
    private function installmentsInMinimum(Debt $debt, CarbonInterface $today): int
    {
        $statement = $this->pendingStatement($debt, CarbonImmutable::instance($today)->startOfDay()) ?? $this->nextStatement($debt, $today);

        return $debt->paymentPlans
            ->filter(fn (PaymentPlan $plan): bool => $plan->in_minimum)
            ->sum(fn (PaymentPlan $plan): int => $this->paymentFor($plan, $statement['month']));
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
