<?php

namespace App\Models\DebtFocus;

use Database\Factories\DebtFocus\BillPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPeriod extends Model
{
    /** @use HasFactory<BillPeriodFactory> */
    use HasFactory;

    protected $table = 'debt_focus_bill_periods';

    protected $fillable = [
        'bill_id', 'year', 'month', 'expected_amount', 'actual_amount', 'paid_at',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
