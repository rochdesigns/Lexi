<?php

namespace App\Models\Money;

enum GoalKind: string
{
    case Buffer = 'buffer';
    case Sinking = 'sinking';
}
