<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Account extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'institution', 'last4', 'balance', 'type'];

    protected $casts = ['balance' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linkedDebt(): HasOne
    {
        return $this->hasOne(Debt::class, 'account_id');
    }

    public function syncLinkedDebt(): void
    {
        $debt = $this->linkedDebt()->first();
        if (! $debt) {
            return;
        }

        $balance = (float) $this->balance;
        $updates = ['balance' => $balance];

        if ($debt->minimum_payment_rate !== null) {
            $updates['per_month'] = round($balance * (float) $debt->minimum_payment_rate / 100, 2);
        }

        $debt->update($updates);
    }
}
