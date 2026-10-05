<?php

namespace App\Models\Money;

use Database\Factories\Money\DebtPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A payment actually made. amount is integer centavos. When it names the
 * account it was paid from, that account's balance came down by the amount.
 */
class DebtPayment extends Model
{
    /** @use HasFactory<DebtPaymentFactory> */
    use HasFactory;

    protected $fillable = ['debt_id', 'payment_plan_id', 'account_id', 'date', 'amount', 'is_extra'];

    /** @var array<string, string> */
    protected $casts = [
        'date' => 'immutable_date',
        'amount' => 'integer',
        'is_extra' => 'boolean',
    ];

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<PaymentPlan, $this>
     */
    public function paymentPlan(): BelongsTo
    {
        return $this->belongsTo(PaymentPlan::class);
    }
}
