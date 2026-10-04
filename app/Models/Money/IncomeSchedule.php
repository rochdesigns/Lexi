<?php

namespace App\Models\Money;

enum IncomeSchedule: string
{
    case Monthly = 'monthly';
    case SemiMonthly = 'semi_monthly';
}
