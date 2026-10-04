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
}
