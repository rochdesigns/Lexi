<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\SpendingLimitFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A monthly cap on spending in one category, in force from the month it starts
 * until a later one replaces it. No amount means no limit from that month on.
 */
class SpendingLimit extends Model
{
    /** @use HasFactory<SpendingLimitFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'category', 'custom_category_id', 'amount', 'starts_on'];

    /** @var array<string, string> */
    protected $casts = [
        'category' => TransactionCategory::class,
        'amount' => 'integer',
        'starts_on' => 'immutable_date',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<CustomCategory, $this>
     */
    public function customCategory(): BelongsTo
    {
        return $this->belongsTo(CustomCategory::class);
    }
}
