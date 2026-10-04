<?php

namespace App\Models\Money;

enum AccountType: string
{
    case Cash = 'cash';
    case Bank = 'bank';
    case Ewallet = 'ewallet';
}
