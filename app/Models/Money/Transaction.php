<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\TransactionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * An expense or money received. amount is integer centavos. An expense is
 * paid from an account or charged to a card, never both. Income is paid into an
 * account, and may point at the income source and pay day it settles. A row imported from a statement that matches
 * an entry the user logged by hand points at it and is not counted, so the
 * expense is only there once.
 */
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'kind',
        'date',
        'amount',
        'category',
        'note',
        'source',
        'debt_id',
        'account_id',
        'income_source_id',
        'pay_date',
        'matched_transaction_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => TransactionKind::class,
        'date' => 'immutable_date',
        'pay_date' => 'immutable_date',
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
     * @return BelongsTo<IncomeSource, $this>
     */
    public function incomeSource(): BelongsTo
    {
        return $this->belongsTo(IncomeSource::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * Rows that count: everything except an imported row that repeats a manual entry.
     *
     * @param  Builder<Transaction>  $query
     */
    public function scopeCounted(Builder $query): void
    {
        $query->whereNull('matched_transaction_id');
    }

    /**
     * The imported statement row that matched this manual entry, if any.
     *
     * @return HasOne<Transaction, $this>
     */
    public function statementRow(): HasOne
    {
        return $this->hasOne(self::class, 'matched_transaction_id');
    }
}
