<?php

namespace App\Http\Requests\Money;

use App\Models\Money\DebtType;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\Money\TransactionKind;
use App\Services\Money\Centavos;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TransactionRequest extends FormRequest
{
    /**
     * The amount arrives in pesos. An expense is paid from one of my accounts
     * or charged to one of my cards, not both and not neither. Income is paid
     * into one of my accounts, has no category, and may say which of my income
     * sources and pay day it settles.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $userId = $this->user()->id;
        $account = Rule::exists('accounts', 'id')->where('user_id', $userId);

        if ($this->kind() === TransactionKind::Income) {
            return [
                'kind' => ['sometimes', Rule::enum(TransactionKind::class)],
                'date' => ['required', 'date'],
                'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
                'note' => ['nullable', 'string', 'max:200'],
                'category' => ['prohibited'],
                'custom_category_id' => ['prohibited'],
                'account_id' => ['required', $account],
                'debt_id' => ['prohibited'],
                'income_source_id' => ['nullable', Rule::exists('income_sources', 'id')->where('user_id', $userId)],
                'pay_date' => ['nullable', 'date'],
            ];
        }

        return [
            'kind' => ['sometimes', Rule::enum(TransactionKind::class)],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:1000000000'],
            'category' => ['required', Rule::in(TransactionCategory::expenseValues())],
            'custom_category_id' => ['nullable', 'integer', Rule::exists('custom_categories', 'id')->where('user_id', $userId)],
            'note' => ['nullable', 'string', 'max:200'],
            'account_id' => ['nullable', 'required_without:debt_id', 'prohibits:debt_id', $account],
            'debt_id' => ['nullable', 'required_without:account_id', 'prohibits:account_id', Rule::exists('debts', 'id')->where('user_id', $userId)->where('type', DebtType::CreditCard->value)],
            'income_source_id' => ['prohibited'],
            'pay_date' => ['prohibited'],
        ];
    }

    /**
     * An entry keeps the kind it was made with. A new one is an expense
     * unless it says it is income.
     */
    public function kind(): TransactionKind
    {
        $transaction = $this->route('transaction');

        if ($transaction instanceof Transaction) {
            return $transaction->kind;
        }

        return TransactionKind::tryFrom((string) $this->input('kind', 'expense')) ?? TransactionKind::Expense;
    }

    /**
     * @return array<string, mixed>
     */
    public function transactionAttributes(): array
    {
        $data = $this->validated();
        $income = $this->kind() === TransactionKind::Income;
        $customCategoryId = $income ? null : ($data['custom_category_id'] ?? null);

        return [
            'kind' => $this->kind(),
            'date' => $data['date'],
            'amount' => Centavos::fromPesos($data['amount']),
            'category' => $income ? TransactionCategory::Income->value : ($customCategoryId !== null ? TransactionCategory::Other->value : $data['category']),
            'custom_category_id' => $customCategoryId,
            'note' => $data['note'] ?? null,
            'account_id' => $data['account_id'] ?? null,
            'debt_id' => $data['debt_id'] ?? null,
            'income_source_id' => $data['income_source_id'] ?? null,
            'pay_date' => $data['pay_date'] ?? null,
        ];
    }
}
