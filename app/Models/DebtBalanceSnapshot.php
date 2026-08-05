<?php

namespace App\Models;

use Database\Factories\DebtBalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtBalanceSnapshot extends Model
{
    /** @use HasFactory<DebtBalanceSnapshotFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'debt_id', 'balance', 'snapshot_date'];

    protected $casts = [
        'balance' => 'decimal:2',
        'snapshot_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
