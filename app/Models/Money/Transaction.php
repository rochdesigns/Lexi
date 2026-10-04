<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An expense. amount is integer centavos. It is paid from an account or
 * charged to a card, never both.
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'date',
        'amount',
        'category',
        'note',
        'source',
        'debt_id',
        'account_id',
        'matched_transaction_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'date' => 'immutable_date',
        'amount' => 'integer',
        'category' => TransactionCategory::class,
        'source' => TransactionSource::class,
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

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
}
