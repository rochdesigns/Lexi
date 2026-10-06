<?php

namespace App\Http\Requests\Money;

use App\Models\Money\BillFrequency;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BillRequest extends FormRequest
{
    /**
     * The amount arrives in pesos. The due month applies to annual bills only.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'account_id' => ['nullable', 'integer', Rule::exists('accounts', 'id')->where('user_id', $this->user()->id)],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'frequency' => ['required', Rule::enum(BillFrequency::class)],
            'due_day' => ['required', 'integer', 'between:1,31'],
            'due_month' => ['nullable', 'required_if:frequency,annual', 'integer', 'between:1,12'],
            'autopay' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function billAttributes(): array
    {
        $data = $this->validated();
        $annual = $data['frequency'] === BillFrequency::Annual->value;

        return [
            ...$data,
            'amount' => Centavos::fromPesos($data['amount']),
            'due_month' => $annual ? $data['due_month'] : null,
        ];
    }
}
