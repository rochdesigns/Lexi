<?php

namespace App\Models\Money;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A card's revolving balance (integer centavos) as last saved in a month,
 * kept so a balance carried over month after month can be spotted. month is "Y-m".
 */
class CardBalanceSnapshot extends Model
{
    protected $fillable = ['debt_id', 'month', 'revolving_balance'];

    /** @var array<string, string> */
    protected $casts = [
        'revolving_balance' => 'integer',
    ];

    /**
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
