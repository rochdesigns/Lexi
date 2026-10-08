<?php

namespace App\Models\Money;

enum TransactionCategory: string
{
    case Food = 'food';
    case Groceries = 'groceries';
    case Transport = 'transport';
    case Shopping = 'shopping';
    case Health = 'health';
    case Fun = 'fun';
    case Home = 'home';
    case Other = 'other';

    /** Money received. Not one of the categories offered for spending. */
    case Income = 'income';

    /** Money moved between the user's own accounts. Not one of the categories offered for spending. */
    case Transfer = 'transfer';

    /** A correction made by reconciling an account. Not one of the categories offered for spending. */
    case Adjustment = 'adjustment';

    /**
     * The categories an expense can have.
     *
     * @return array<int, string>
     */
    public static function expenseValues(): array
    {
        return collect(self::cases())
            ->reject(fn (self $category): bool => in_array($category, [self::Income, self::Transfer, self::Adjustment], true))
            ->map(fn (self $category): string => $category->value)
            ->values()
            ->all();
    }
}
