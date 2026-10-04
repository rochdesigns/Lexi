<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * balance is integer centavos, a snapshot the user keeps up to date. An
 * account that is not spendable (savings kept aside) is shown but never
 * counted as cash available.
 */
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'type', 'balance', 'is_spendable'];

    /** @var array<string, string> */
    protected $casts = [
        'type' => AccountType::class,
        'balance' => 'integer',
        'is_spendable' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
