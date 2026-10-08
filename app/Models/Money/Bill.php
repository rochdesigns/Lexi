<?php

namespace App\Models\Money;

use App\Models\User;
use Carbon\CarbonInterface;
use Database\Factories\Money\BillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * amount is integer centavos per payment. An annual bill is paid once a year
 * in due_month (1 to 12) and is set aside at one twelfth each month. A
 * quarterly bill is paid every third month starting at due_month and is set
 * aside at one third each month. account_id
 * is the account it is usually paid from, or debt_id the credit card it is charged to. An expense logged against the bill
 * marks the bill paid for the month it falls in.
 */
class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'account_id', 'debt_id', 'name', 'amount', 'frequency', 'due_day', 'due_month', 'autopay'];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'integer',
        'frequency' => BillFrequency::class,
        'due_day' => 'integer',
        'due_month' => 'integer',
        'autopay' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * The credit card the bill is charged to, when it is not paid from an account.
     *
     * @return BelongsTo<Debt, $this>
     */
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    /**
     * Payments logged against this bill. Imported rows that repeat a manual entry are left out.
     *
     * @return HasMany<Transaction, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Transaction::class)->whereNull('matched_transaction_id');
    }

    /**
     * Whether a payment is logged for the due on $due: anything dated after the
     * previous due up to the due itself, so a payment made early counts.
     */
    public function isPaidForDue(CarbonInterface $due, CarbonInterface $previousDue): bool
    {
        return $this->payments->contains(fn (Transaction $payment): bool => $payment->date->greaterThan($previousDue) && $payment->date->lessThanOrEqualTo($due));
    }

    /**
     * Whether a payment is logged in the calendar month of the given date.
     */
    public function isPaidInMonthOf(CarbonInterface $date): bool
    {
        return $this->payments->contains(fn (Transaction $payment): bool => $payment->date->isSameMonth($date));
    }
}
