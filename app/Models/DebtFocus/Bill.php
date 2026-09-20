<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\BillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    protected $table = 'debt_focus_bills';

    protected $fillable = [
        'user_id', 'name', 'expected_amount', 'frequency', 'anchor_month', 'amount_varies', 'paid_from_account_id', 'due_day', 'active',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'amount_varies' => 'boolean',
        'active' => 'boolean',
    ];

    /** How often a bill can recur. Monthly lands every month; the rest count from their anchor. */
    public const FREQUENCIES = ['monthly', 'quarterly', 'yearly'];

    /** Months between landings, by frequency. */
    private const CADENCE = ['monthly' => 1, 'quarterly' => 3, 'yearly' => 12];

    /**
     * Whether this bill lands in the given month. Anything less often than
     * monthly counts whole cadences from its anchor month, so a quarterly bill
     * anchored to February lands in February, May, August and November — and
     * sits out of the Bills envelope in between.
     */
    public function landsIn(int $year, int $month): bool
    {
        $cadence = self::CADENCE[$this->frequency] ?? 1;

        if ($cadence === 1) {
            return true;
        }

        // An unanchored recurring bill is treated as anchored to the month it
        // was created in, so it always has a cycle to count from. Only ever
        // called on a stored bill, which always has a created_at.
        $anchorMonth = $this->anchor_month ?? (int) $this->created_at->month;
        $anchorYear = (int) $this->created_at->year;

        $monthsSinceAnchor = ($year - $anchorYear) * 12 + ($month - $anchorMonth);

        return $monthsSinceAnchor % $cadence === 0;
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The account this bill is normally paid from.
     *
     * @return BelongsTo<Account, $this>
     */
    public function paidFromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'paid_from_account_id');
    }

    /**
     * @return HasMany<BillPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(BillPeriod::class);
    }
}
