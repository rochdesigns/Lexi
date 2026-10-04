<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\DebtFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Money columns (outstanding_balance) are integer centavos. Debts without a
 * payment plan, such as money owed to family, use outstanding_balance and an
 * optional target_payoff_date instead.
 */
class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'name',
        'type',
        'lender',
        'interest_type',
        'monthly_interest_rate',
        'due_day',
        'status',
        'notes',
        'outstanding_balance',
        'target_payoff_date',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'type' => DebtType::class,
        'interest_type' => InterestType::class,
        'status' => DebtStatus::class,
        'monthly_interest_rate' => 'float',
        'due_day' => 'integer',
        'outstanding_balance' => 'integer',
        'target_payoff_date' => 'immutable_date',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasOne<CardDetail, $this>
     */
    public function cardDetail(): HasOne
    {
        return $this->hasOne(CardDetail::class);
    }

    /**
     * @return HasMany<PaymentPlan, $this>
     */
    public function paymentPlans(): HasMany
    {
        return $this->hasMany(PaymentPlan::class);
    }

    /**
     * @return HasMany<CardBalanceSnapshot, $this>
     */
    public function balanceSnapshots(): HasMany
    {
        return $this->hasMany(CardBalanceSnapshot::class);
    }

    /**
     * @return HasMany<DebtPayment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }
}
