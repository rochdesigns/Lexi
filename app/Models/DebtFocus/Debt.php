<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\DebtFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    protected $table = 'debt_focus_debts';

    protected $fillable = [
        'user_id', 'name', 'balance', 'monthly_rate', 'minimum_payment', 'debt_type', 'due_day', 'linked_account_id',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'monthly_rate' => 'decimal:2',
        'minimum_payment' => 'decimal:2',
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
    public function linkedAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'linked_account_id');
    }
}
