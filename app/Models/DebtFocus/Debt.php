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
        'user_id', 'name', 'balance', 'apr', 'minimum_payment', 'debt_type',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'apr' => 'decimal:2',
        'minimum_payment' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
