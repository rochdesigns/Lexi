<?php

namespace App\Models\Money;

enum BillFrequency: string
{
    case Monthly = 'monthly';
    case Annual = 'annual';
}
