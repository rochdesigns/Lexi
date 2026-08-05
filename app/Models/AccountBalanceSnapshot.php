<?php

namespace App\Models;

use Database\Factories\AccountBalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountBalanceSnapshot extends Model
{
    /** @use HasFactory<AccountBalanceSnapshotFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'account_id', 'balance', 'snapshot_date'];

    protected $casts = [
        'balance' => 'decimal:2',
        'snapshot_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
