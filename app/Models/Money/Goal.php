<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\GoalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A savings goal. Money columns are integer centavos. A buffer holds
 * buffer_months of monthly obligations and a sinking fund saves towards a
 * target_amount. When account_id is set the account's balance is what has been
 * saved, otherwise saved is.
 */
class Goal extends Model
{
    /** @use HasFactory<GoalFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'kind',
        'name',
        'monthly_contribution',
        'saved',
        'buffer_months',
        'target_amount',
        'target_date',
        'account_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'kind' => GoalKind::class,
        'monthly_contribution' => 'integer',
        'saved' => 'integer',
        'buffer_months' => 'integer',
        'target_amount' => 'integer',
        'target_date' => 'immutable_date',
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
}
