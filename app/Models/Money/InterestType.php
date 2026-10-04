<?php

namespace App\Models\Money;

enum InterestType: string
{
    case None = 'none';
    case Amortized = 'amortized';
    case AddOn = 'add_on';
    case Revolving = 'revolving';
}
