<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HabitLog extends Model
{
    protected $fillable = ['habit_id', 'date', 'done'];

    protected $casts = ['date' => 'date', 'done' => 'boolean'];

    public function habit(): BelongsTo
    {
        return $this->belongsTo(Habit::class);
    }
}
