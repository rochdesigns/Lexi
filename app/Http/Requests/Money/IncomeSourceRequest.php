<?php

namespace App\Http\Requests\Money;

use App\Models\Money\IncomeSchedule;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IncomeSourceRequest extends FormRequest
{
    /**
     * The amount arrives in pesos for one pay day. The second pay day applies
     * to semi-monthly sources only.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'schedule' => ['required', Rule::enum(IncomeSchedule::class)],
            'pay_day_1' => ['required', 'integer', 'between:1,31'],
            'pay_day_2' => ['nullable', 'required_if:schedule,semi_monthly', 'integer', 'between:1,31', 'different:pay_day_1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function incomeAttributes(): array
    {
        $data = $this->validated();
        $semiMonthly = $data['schedule'] === IncomeSchedule::SemiMonthly->value;

        return [
            ...$data,
            'amount' => Centavos::fromPesos($data['amount']),
            'pay_day_2' => $semiMonthly ? $data['pay_day_2'] : null,
        ];
    }
}
