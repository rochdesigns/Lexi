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

    /**
     * The categories an expense can have.
     *
     * @return array<int, string>
     */
    public static function expenseValues(): array
    {
        return collect(self::cases())
            ->reject(fn (self $category): bool => $category === self::Income)
            ->map(fn (self $category): string => $category->value)
            ->values()
            ->all();
    }
}
