<?php

namespace App\Models\Money;

use Database\Factories\Money\PaymentPlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card installment or a loan's amortization. principal and monthly_amount
 * are integer centavos; start_month is the first of the month that carries
 * the first payment. A null interest_type means "same as the debt".
 */
class PaymentPlan extends Model
{
    /** @use HasFactory<PaymentPlanFactory> */
    use HasFactory;

    protected $fillable = [
        'debt_id',
        'description',
        'principal',
        'monthly_amount',
        'total_months',
        'start_month',
        'interest_type',
        'in_minimum',
        'status',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'principal' => 'integer',
        'monthly_amount' => 'integer',
        'total_months' => 'integer',
        'start_month' => 'immutable_date',
        'interest_type' => InterestType::class,
        'in_minimum' => 'boolean',
        'status' => DebtStatus::class,
    ];

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
