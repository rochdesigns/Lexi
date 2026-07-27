<?php

namespace App\Models;

use Database\Factories\BudgetCycleFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BudgetCycle extends Model
{
    /** @use HasFactory<BudgetCycleFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'start_date', 'end_date', 'budget'];

    protected $casts = [
        'start_date' => 'date:Y-m-d',
        'end_date' => 'date:Y-m-d',
        'budget' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
