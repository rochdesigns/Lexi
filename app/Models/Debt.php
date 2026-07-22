<?php

namespace App\Models;

use Database\Factories\DebtFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'icon', 'color', 'bg', 'balance', 'original', 'per_month', 'interest_rate', 'due_day', 'position'];

    protected $casts = [
        'balance' => 'decimal:2',
        'original' => 'decimal:2',
        'per_month' => 'decimal:2',
        'interest_rate' => 'decimal:2',
        'due_day' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
