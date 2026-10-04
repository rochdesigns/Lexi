<?php

namespace App\Http\Requests\Money;

use App\Models\Money\Debt;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DebtPaymentRequest extends FormRequest
{
    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        /** @var Debt $debt */
        $debt = $this->route('debt');

        return [
            'payment_plan_id' => ['nullable', Rule::exists('payment_plans', 'id')->where('debt_id', $debt->id)],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'is_extra' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function paymentAttributes(): array
    {
        $data = $this->validated();

        return [...$data, 'amount' => Centavos::fromPesos($data['amount'])];
    }
}
