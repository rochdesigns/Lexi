<?php

namespace App\Models;

use App\Models\Money\Account;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'theme',
        'recovery_months',
        'yearly_bills_account_id',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'recovery_months' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The account yearly and quarterly bills are saved in.
     *
     * @return BelongsTo<Account, $this>
     */
    public function yearlyBillsAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'yearly_bills_account_id');
    }
}
