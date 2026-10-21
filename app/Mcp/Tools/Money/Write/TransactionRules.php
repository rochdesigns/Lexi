<?php

namespace App\Mcp\Tools\Money\Write;

use App\Models\User;
use App\Services\Money\Ledger;
use Illuminate\Validation\Rule;

/**
 * The rules an entry's fields follow, shared by recording a new entry and
 * editing one, so an edit can never leave an entry a new one could not be.
 */
trait TransactionRules
{
    /**
     * @return array<string, array<int, mixed>>
     */
    protected function transactionRules(User $user, string $kind, Ledger $ledger): array
    {
        $ownedAccount = Rule::exists('accounts', 'id')->where('user_id', $user->id);
        $common = [
            'amountCentavos' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'note' => ['nullable', 'string', 'max:200'],
        ];

        return match ($kind) {
            'transfer' => [
                ...$common,
                'category' => ['prohibited'],
                'customCategoryId' => ['prohibited'],
                'accountId' => ['required', 'integer', $ownedAccount],
                'toAccountId' => ['required', 'integer', 'different:accountId', $ownedAccount],
                'debtId' => ['prohibited'],
                'billId' => ['prohibited'],
                'incomeSourceId' => ['prohibited'],
                'payDate' => ['prohibited'],
            ],
            'income' => [
                ...$common,
                'category' => ['prohibited'],
                'customCategoryId' => ['prohibited'],
                'accountId' => ['required', 'integer', $ownedAccount],
                'toAccountId' => ['prohibited'],
                'debtId' => ['prohibited'],
                'billId' => ['prohibited'],
                'incomeSourceId' => ['nullable', 'integer', Rule::exists('income_sources', 'id')->where('user_id', $user->id)],
                'payDate' => ['nullable', 'date_format:Y-m-d'],
            ],
            default => [
                ...$common,
                'category' => ['nullable', 'required_without:customCategoryId', Rule::in($ledger->expenseCategories())],
                'customCategoryId' => ['nullable', 'integer', Rule::exists('custom_categories', 'id')->where('user_id', $user->id)],
                'accountId' => ['nullable', 'integer', 'required_without_all:debtId,billId', 'prohibits:debtId', $ownedAccount],
                'debtId' => ['nullable', 'integer', 'required_without_all:accountId,billId', 'prohibits:accountId', Rule::exists('debts', 'id')->where('user_id', $user->id)->where('type', 'credit_card')],
                'billId' => ['nullable', 'integer', Rule::exists('bills', 'id')->where('user_id', $user->id)],
                'toAccountId' => ['prohibited'],
                'incomeSourceId' => ['prohibited'],
                'payDate' => ['prohibited'],
            ],
        };
    }

    /**
     * An expense in one of the user's own categories is stored as "other"
     * pointing at it, the same way the web form stores it.
     *
     * @param  array<string, mixed>  $data
     * @return array{category: string, custom_category_id: int|null}
     */
    protected function categoryAttributes(string $kind, array $data): array
    {
        if ($kind !== 'expense') {
            return ['category' => $kind, 'custom_category_id' => null];
        }

        $customCategoryId = $data['customCategoryId'] ?? null;

        return [
            'category' => $customCategoryId !== null ? 'other' : $data['category'],
            'custom_category_id' => $customCategoryId,
        ];
    }
}
