<?php

namespace App\Http\Requests\Money;

use App\Models\Money\TransactionCategory;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SpendingLimitRequest extends FormRequest
{
    /**
     * A limit on one of my own categories is kept against "other", as its entries are.
     */
    protected function prepareForValidation(): void
    {
        if ($this->filled('custom_category_id')) {
            $this->merge(['category' => TransactionCategory::Other->value]);
        }
    }

    /**
     * The amount arrives in pesos. No amount clears the limit.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'category' => ['required', 'string', Rule::in(TransactionCategory::expenseValues())],
            'custom_category_id' => ['nullable', 'integer', Rule::exists('custom_categories', 'id')->where('user_id', $this->user()->id)],
            'amount' => ['nullable', 'numeric', 'gt:0', 'max:1000000000'],
        ];
    }

    /**
     * @return array{category: string, custom_category_id: int|null, amount: int|null}
     */
    public function limitAttributes(): array
    {
        $data = $this->validated();

        return [
            'category' => $data['category'],
            'custom_category_id' => isset($data['custom_category_id']) ? (int) $data['custom_category_id'] : null,
            'amount' => isset($data['amount']) ? Centavos::fromPesos($data['amount']) : null,
        ];
    }
}
