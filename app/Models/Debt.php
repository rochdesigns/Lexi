<?php

namespace App\Models;

use App\Contracts\Envelopable;
use App\Contracts\Forecastable;
use App\Support\CyclePeriod;
use Database\Factories\DebtFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Debt extends Model implements Envelopable, Forecastable
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'budget_category_id', 'account_id',
        'name', 'icon', 'color', 'bg',
        'balance', 'original', 'per_month',
        'interest_rate', 'interest_rate_period', 'minimum_payment_rate',
        'due_day', 'position',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'original' => 'decimal:2',
        'per_month' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'minimum_payment_rate' => 'decimal:2',
        'due_day' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function debtPayments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }

    public function balanceSnapshots(): HasMany
    {
        return $this->hasMany(DebtBalanceSnapshot::class);
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
        return (float) $this->per_month;
    }

    /**
     * Set by MonthlyForecastService immediately before projecting this debt's forecast
     * row — the period index its payoff simulation says the balance reaches zero. Not a
     * database column; null means "not yet computed" or "never pays off in the window."
     */
    public ?int $forecastPayoffPeriodIndex = null;

    public function forecastAmount(CyclePeriod $period, int $periodIndex): float
    {
        if ($this->forecastPayoffPeriodIndex !== null && $periodIndex >= $this->forecastPayoffPeriodIndex) {
            return 0.0;
        }

        return (float) $this->per_month;
    }
}
