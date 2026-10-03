<?php

namespace App\Http\Requests\Money;

use App\Models\Money\Debt;
use App\Models\Money\DebtStatus;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Services\Money\Centavos;
use App\Services\Money\Projection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class PaymentPlanRequest extends FormRequest
{
    /**
     * Amounts arrive in pesos and are stored as centavos.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'description' => ['required', 'string', 'max:150'],
            'principal' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'monthly_amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
            'total_months' => ['required', 'integer', 'between:1,360'],
            'start_month' => ['required', 'date_format:Y-m'],
            'interest_type' => ['nullable', Rule::in([InterestType::None->value, InterestType::Amortized->value, InterestType::AddOn->value])],
            'in_minimum' => ['required', 'boolean'],
            'status' => ['sometimes', Rule::enum(DebtStatus::class)],
        ];
    }

    /**
     * The monthly amount may be left out only for an amortized plan, where it
     * is worked out from the principal, rate and term.
     *
     * @return array<int, callable(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $debt = $this->debt();
            $type = $this->effectiveInterestType($debt);

            if ($type === InterestType::Amortized) {
                if ($debt->monthly_interest_rate === null) {
                    $validator->errors()->add('interest_type', 'Set an interest rate on the debt first.');
                }

                if ($this->input('principal') === null) {
                    $validator->errors()->add('principal', 'Amortized plans need a principal.');
                }

                return;
            }

            if ($this->input('monthly_amount') === null) {
                $validator->errors()->add('monthly_amount', 'Enter the monthly amount.');
            }

            if ($type === InterestType::AddOn && $this->input('principal') === null) {
                $validator->errors()->add('principal', 'Add-on plans need the amount borrowed.');
            }
        }];
    }

    /**
     * @return array<string, mixed>
     */
    public function planAttributes(Projection $projection): array
    {
        $data = $this->validated();
        $debt = $this->debt();
        $type = $this->effectiveInterestType($debt);

        $monthly = isset($data['monthly_amount']) ? Centavos::fromPesos($data['monthly_amount']) : null;
        $principal = isset($data['principal']) ? Centavos::fromPesos($data['principal']) : null;

        if ($type === InterestType::Amortized && $monthly === null) {
            $monthly = $projection->amortizedPayment($principal, $debt->monthly_interest_rate, $data['total_months']);
        }

        return [
            ...collect($data)->only(['description', 'total_months', 'interest_type', 'status'])->all(),
            'monthly_amount' => $monthly,
            'principal' => $principal ?? $monthly * $data['total_months'],
            'start_month' => $data['start_month'].'-01',
            'in_minimum' => $debt->cardDetail !== null && $data['in_minimum'],
        ];
    }

    private function effectiveInterestType(Debt $debt): InterestType
    {
        return InterestType::tryFrom((string) $this->input('interest_type')) ?? $debt->interest_type;
    }

    private function debt(): Debt
    {
        $plan = $this->route('paymentPlan');

        if ($plan instanceof PaymentPlan) {
            return $plan->debt;
        }

        $debt = $this->route('debt');
        abort_unless($debt instanceof Debt, 404);

        return $debt;
    }
}
