<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetMonth extends Model
{
    protected $fillable = ['user_id', 'year', 'month', 'budget', 'base_spent'];

    protected $casts = [
        'budget' => 'decimal:2',
        'base_spent' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
