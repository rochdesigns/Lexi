<?php

namespace App\Models\Money;

enum TransactionKind: string
{
    case Expense = 'expense';
    case Income = 'income';
    case Transfer = 'transfer';

    /** A reconciliation that raised an account's balance to match the bank. */
    case AdjustmentIncrease = 'adjustment_increase';

    /** A reconciliation that lowered an account's balance to match the bank. */
    case AdjustmentDecrease = 'adjustment_decrease';

    public function isAdjustment(): bool
    {
        return in_array($this, [self::AdjustmentIncrease, self::AdjustmentDecrease], true);
    }
}
