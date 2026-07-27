<?php

namespace App\Models;

use Database\Factories\RecurringBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringBill extends Model
{
    /** @use HasFactory<RecurringBillFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'budget_category_id', 'name', 'icon', 'color', 'bg', 'amount', 'day_of_month'];

    protected $casts = [
        'amount' => 'decimal:2',
        'day_of_month' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }
}
