<?php

namespace App\Models\Money;

enum TransactionSource: string
{
    case Manual = 'manual';
    case StatementImport = 'statement_import';
}
