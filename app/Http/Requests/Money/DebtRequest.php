<?php

namespace App\Http\Requests\Money;

use App\Models\Money\DebtStatus;
use App\Models\Money\DebtType;
use App\Models\Money\InterestType;
use App\Services\Money\Centavos;
use App\Services\Money\Projection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class DebtRequest extends FormRequest
{
    /**
     * Amounts arrive in pesos and are stored as centavos. Card fields apply
     * to credit cards only, balance fields to informal debts only.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $rules = [
            'name' => ['required', 'string', 'max:100'],
            'type' => ['required', Rule::enum(DebtType::class)],
            'lender' => ['nullable', 'string', 'max:100'],
            'interest_type' => ['required', Rule::enum(InterestType::class)],
            'monthly_interest_rate' => ['nullable', 'required_if:interest_type,amortized,revolving', 'numeric', 'between:0,100'],
            'due_day' => ['nullable', Rule::requiredIf(fn (): bool => $this->input('type') !== DebtType::Informal->value && ! $this->dueIsAfterStatement()), 'integer', 'between:1,31'],
            'due_days_after_statement' => ['nullable', 'integer', 'between:1,60'],
            'status' => ['sometimes', Rule::enum(DebtStatus::class)],
            'notes' => ['nullable', 'string', 'max:2000'],

            'outstanding_balance' => ['nullable', 'required_if:type,informal', 'numeric', 'min:0', 'max:1000000000'],
            'target_payoff_date' => ['nullable', 'date'],

            'credit_limit' => ['nullable', 'required_if:type,credit_card', 'numeric', 'min:0', 'max:1000000000'],
            'statement_day' => ['nullable', 'required_if:type,credit_card', 'integer', 'between:1,31'],
            'min_payment_percent' => ['nullable', 'numeric', 'between:0,100'],
            'min_payment_floor' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'charges_due_in_full' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
            'revolving_balance' => ['nullable', Rule::requiredIf(fn (): bool => $this->isCard() && $this->input('total_due') === null), 'numeric', 'min:0', 'max:1000000000'],
        ];

        if ($this->isNewCard()) {
            $rules += [
                'total_due' => ['nullable', 'numeric', 'min:0', 'max:1000000000'],
                'installments' => ['nullable', 'array', 'max:20'],
                'installments.*.description' => ['required', 'string', 'max:150'],
                'installments.*.monthly_amount' => ['nullable', 'required_without:installments.*.total_amount', 'numeric', 'gt:0', 'max:1000000000'],
                'installments.*.total_amount' => ['nullable', 'required_without:installments.*.monthly_amount', 'numeric', 'gt:0', 'max:1000000000'],
                'installments.*.principal' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
                'installments.*.start_month' => ['required', 'date_format:Y-m'],
                'installments.*.end_month' => ['required', 'date_format:Y-m'],
                'installments.*.in_minimum' => ['sometimes', 'boolean'],
            ];
        }

        if ($this->wantsFirstPlan()) {
            $rules += [
                'plan' => ['required', 'array'],
                'plan.principal' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
                'plan.monthly_amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
                'plan.total_months' => ['required', 'integer', 'between:1,360'],
                'plan.start_month' => ['required', 'date_format:Y-m'],
            ];
        }

        return $rules;
    }

    /**
     * Same rule as PaymentPlanRequest: only an amortized plan may leave the
     * monthly amount out, since it is worked out from principal, rate and term.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($this->isNewCard() && $this->input('total_due') !== null && $this->installmentsTotal() > Centavos::fromPesos($this->input('total_due'))) {
                $validator->errors()->add('total_due', 'Your installments add up to more than the total due.');
            }

            foreach ($this->input('installments', []) as $index => $row) {
                if (! is_array($row) || ! preg_match('/^\d{4}-\d{2}$/', (string) ($row['start_month'] ?? '')) || ! preg_match('/^\d{4}-\d{2}$/', (string) ($row['end_month'] ?? ''))) {
                    continue;
                }

                $months = $this->installmentMonths($row);

                if ($months < 1 || $months > 360) {
                    $validator->errors()->add("installments.{$index}.end_month", $months < 1 ? 'The last payment cannot be before the first.' : 'An installment can run for 360 months at most.');

                    continue;
                }

                if (isset($row['principal']) && Centavos::fromPesos($row['principal']) > $this->installmentRepaid($row, $months)) {
                    $validator->errors()->add("installments.{$index}.principal", 'The amount borrowed is more than you will pay back.');
                }
            }

            if (! $this->wantsFirstPlan()) {
                return;
            }

            $type = $this->input('interest_type');

            if ($type === InterestType::Amortized->value) {
                if ($this->input('plan.principal') === null) {
                    $validator->errors()->add('plan.principal', 'Enter how much you borrowed.');
                }

                return;
            }

            if ($this->input('plan.monthly_amount') === null) {
                $validator->errors()->add('plan.monthly_amount', 'Enter the monthly amount.');
            }

            if ($type === InterestType::AddOn->value && $this->input('plan.principal') === null) {
                $validator->errors()->add('plan.principal', 'Enter the amount borrowed.');
            }
        }];
    }

    /**
     * Loans and BNPL get their first payment plan with the debt, so a new one
     * never sits at zero. Cards and later plans use the plan form.
     */
    public function wantsFirstPlan(): bool
    {
        return $this->route('debt') === null
            && in_array($this->input('type'), [DebtType::Loan->value, DebtType::Bnpl->value], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function planAttributes(Projection $projection): array
    {
        $plan = $this->validated('plan');
        $monthly = isset($plan['monthly_amount']) ? Centavos::fromPesos($plan['monthly_amount']) : null;
        $principal = isset($plan['principal']) ? Centavos::fromPesos($plan['principal']) : null;

        if ($monthly === null) {
            $monthly = $projection->amortizedPayment($principal, (float) $this->validated('monthly_interest_rate'), $plan['total_months']);
        }

        return [
            'description' => $this->validated('name'),
            'principal' => $principal ?? $monthly * $plan['total_months'],
            'monthly_amount' => $monthly,
            'total_months' => $plan['total_months'],
            'start_month' => $plan['start_month'].'-01',
            'in_minimum' => false,
        ];
    }

    /**
     * A card may set its due date as days after the statement closes instead
     * of a fixed day of the month.
     */
    private function dueIsAfterStatement(): bool
    {
        return $this->isCard() && $this->input('due_days_after_statement') !== null;
    }

    public function isNewCard(): bool
    {
        return $this->isCard() && $this->route('debt') === null;
    }

    /**
     * What the card's latest statement carries for its installments, in
     * centavos, since the total due typed in is that statement's. One that had
     * not started yet, or had ended, is not on it.
     */
    private function installmentsTotal(): int
    {
        $month = app(Projection::class)
            ->latestClosingDate((int) $this->input('statement_day', 1), now())
            ->format('Y-m');

        return (int) collect($this->input('installments', []))
            ->filter(fn (mixed $row): bool => is_array($row)
                && ($row['start_month'] ?? '9999-99') <= $month
                && ($row['end_month'] ?? '0000-00') >= $month)
            ->sum(fn (array $row): int => $this->installmentMonthly($row));
    }

    /**
     * What the row pays back in all, interest included.
     *
     * @param  array<string, mixed>  $row
     */
    private function installmentRepaid(array $row, int $months): int
    {
        return isset($row['monthly_amount'])
            ? Centavos::fromPesos($row['monthly_amount']) * $months
            : Centavos::fromPesos($row['total_amount'] ?? 0);
    }

    /**
     * A row gives either what it costs each month or what it costs in all.
     * Given the total, the monthly amount is the total spread over its months.
     *
     * @param  array<string, mixed>  $row
     */
    private function installmentMonthly(array $row): int
    {
        if (isset($row['monthly_amount'])) {
            return Centavos::fromPesos($row['monthly_amount']);
        }

        $isMonth = fn (mixed $value): bool => preg_match('/^\d{4}-\d{2}$/', (string) $value) === 1;

        if (! isset($row['total_amount']) || ! $isMonth($row['start_month'] ?? null) || ! $isMonth($row['end_month'] ?? null)) {
            return 0;
        }

        $months = $this->installmentMonths($row);

        return $months < 1 ? 0 : (int) round(Centavos::fromPesos($row['total_amount']) / $months);
    }

    /**
     * Installments entered with a new card. Each starts on the card's next
     * statement, since the total due already carries this month's slice.
     *
     * @return list<array<string, mixed>>
     */
    public function installmentAttributes(): array
    {
        return collect($this->validated('installments') ?? [])
            ->map(function (array $row): array {
                $months = $this->installmentMonths($row);
                $monthly = $this->installmentMonthly($row);
                $repaid = $this->installmentRepaid($row, $months);
                $borrowed = isset($row['principal']) ? Centavos::fromPesos($row['principal']) : null;
                $hasInterest = $borrowed !== null && $borrowed < $repaid;

                return [
                    'description' => $row['description'],
                    'monthly_amount' => $monthly,
                    'principal' => $hasInterest ? $borrowed : $repaid,
                    'interest_type' => $hasInterest ? InterestType::AddOn->value : null,
                    'total_months' => $months,
                    'start_month' => $row['start_month'].'-01',
                    'in_minimum' => (bool) ($row['in_minimum'] ?? false),
                ];
            })
            ->all();
    }

    /**
     * Payments from the start month to the end month, both included.
     *
     * @param  array<string, mixed>  $row
     */
    private function installmentMonths(array $row): int
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $row['start_month']);
        $end = CarbonImmutable::createFromFormat('!Y-m', $row['end_month']);

        return (int) $start->diffInMonths($end, false) + 1;
    }

    public function isCard(): bool
    {
        return $this->input('type') === DebtType::CreditCard->value;
    }

    /**
     * @return array<string, mixed>
     */
    public function debtAttributes(): array
    {
        $data = $this->validated();
        $informal = $data['type'] === DebtType::Informal->value;

        return [
            ...collect($data)->only(['name', 'type', 'lender', 'interest_type', 'monthly_interest_rate', 'due_day', 'notes', 'status'])->all(),
            'due_day' => $this->dueIsAfterStatement() ? null : ($data['due_day'] ?? null),
            'outstanding_balance' => $informal ? Centavos::fromPesos($data['outstanding_balance']) : null,
            'target_payoff_date' => $informal ? ($data['target_payoff_date'] ?? null) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function cardAttributes(): array
    {
        $data = $this->validated();

        return [
            'credit_limit' => Centavos::fromPesos($data['credit_limit']),
            'charges_due_in_full' => Centavos::fromPesos($data['charges_due_in_full'] ?? 0),
            'statement_day' => (int) $data['statement_day'],
            'due_days_after_statement' => $data['due_days_after_statement'] ?? null,
            'min_payment_percent' => $data['min_payment_percent'] ?? null,
            'min_payment_floor' => isset($data['min_payment_floor']) ? Centavos::fromPesos($data['min_payment_floor']) : null,
            'revolving_balance' => isset($data['total_due'])
                ? Centavos::fromPesos($data['total_due']) - $this->installmentsTotal()
                : Centavos::fromPesos($data['revolving_balance']),
        ];
    }
}
