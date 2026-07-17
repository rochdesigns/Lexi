<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetCategory extends Model
{
    protected $fillable = ['user_id', 'name', 'icon', 'color', 'limit_amount'];

    protected $casts = ['limit_amount' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
