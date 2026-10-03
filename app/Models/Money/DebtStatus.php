<?php

namespace App\Models\Money;

enum DebtStatus: string
{
    case Active = 'active';
    case PaidOff = 'paid_off';
}
