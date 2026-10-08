<?php

namespace App\Http\Requests\Money;

use App\Models\Money\BillFrequency;
use App\Models\Money\DebtType;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillRequest extends FormRequest
{
    /**
     * The amount arrives in pesos. The due month applies to annual and quarterly bills: for a quarterly bill it is the first month of the year it falls due, then every third month after.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'debt_id' => ['nullable', 'integer', 'prohibits:account_id', Rule::exists('debts', 'id')->where('user_id', $this->user()->id)->where('type', DebtType::CreditCard->value)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'frequency' => ['required', Rule::enum(BillFrequency::class)],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'due_month' => ['nullable', 'required_if:frequency,annual,quarterly', 'integer', 'between:1,12'],
            'autopay' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function billAttributes(): array
    {
        $data = $this->validated();
        $hasDueMonth = BillFrequency::from($data['frequency'])->isSetAside();

        return [
            ...$data,
            'amount' => Centavos::fromPesos($data['amount']),
            'due_month' => $hasDueMonth ? $data['due_month'] : null,
        ];
    }
}
