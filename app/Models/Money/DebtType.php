<?php

namespace App\Models\Money;

enum DebtType: string
{
    case CreditCard = 'credit_card';
    case Loan = 'loan';
    case Bnpl = 'bnpl';
    case Informal = 'informal';
}
