<?php

namespace App\Models\Money;

use Database\Factories\Money\CardDetailFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Extra fields for a credit card debt. credit_limit, min_payment_floor and
 * revolving_balance are integer centavos.
 */
class CardDetail extends Model
{
    /** @use HasFactory<CardDetailFactory> */
    use HasFactory;

    protected $fillable = [
        'debt_id',
        'credit_limit',
        'statement_day',
        'due_days_after_statement',
        'min_payment_percent',
        'min_payment_floor',
        'revolving_balance',
        'charges_due_in_full',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'credit_limit' => 'integer',
        'statement_day' => 'integer',
        'due_days_after_statement' => 'integer',
        'min_payment_percent' => 'float',
        'min_payment_floor' => 'integer',
        'revolving_balance' => 'integer',
        'charges_due_in_full' => 'integer',
    ];

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
