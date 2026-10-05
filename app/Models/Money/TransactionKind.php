<?php

namespace App\Models\Money;

enum TransactionKind: string
{
    case Expense = 'expense';
    case Income = 'income';
    case Transfer = 'transfer';
}
