<?php

namespace App\Http\Requests\Money;

use App\Models\Money\DebtType;
use App\Models\Money\TransactionCategory;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    /**
     * The amount arrives in pesos. An expense is paid from one of my accounts
     * or charged to one of my cards, not both and not neither.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'category' => ['required', Rule::enum(TransactionCategory::class)],
            'note' => ['nullable', 'string', 'max:200'],
            'account_id' => ['nullable', 'required_without:debt_id', 'prohibits:debt_id', Rule::exists('accounts', 'id')->where('user_id', $userId)],
            'debt_id' => ['nullable', 'required_without:account_id', 'prohibits:account_id', Rule::exists('debts', 'id')->where('user_id', $userId)->where('type', DebtType::CreditCard->value)],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionAttributes(): array
    {
        $data = $this->validated();

        return [
            'date' => $data['date'],
            'amount' => Centavos::fromPesos($data['amount']),
            'category' => $data['category'],
            'note' => $data['note'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'debt_id' => $data['debt_id'] ?? null,
        ];
    }
}
