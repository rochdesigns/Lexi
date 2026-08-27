<?php

namespace App\Models;

use App\Contracts\Envelopable;
use App\Contracts\Forecastable;
use App\Support\CyclePeriod;
use Database\Factories\RecurringBillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RecurringBill extends Model implements Envelopable, Forecastable
{
    /** @use HasFactory<RecurringBillFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'budget_category_id', 'name', 'icon', 'color', 'bg', 'amount', 'day_of_month', 'last_paid_at'];

    protected $casts = [
        'amount' => 'decimal:2',
        'day_of_month' => 'integer',
        'last_paid_at' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function budgetCategory(): BelongsTo
    {
        return $this->belongsTo(BudgetCategory::class);
    }

    public function envelopeName(): string
    {
        return $this->name;
    }

    public function envelopeIcon(): string
    {
        return $this->icon;
    }

    public function envelopeColor(): string
    {
        return $this->color;
    }

    public function defaultAssignedAmount(): float
    {
        return (float) $this->amount;
    }

    public function forecastAmount(CyclePeriod $period, int $periodIndex): float
    {
        return (float) $this->amount;
    }
}
