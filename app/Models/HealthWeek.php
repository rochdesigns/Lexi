<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthWeek extends Model
{
    protected $table = 'health_week';

    protected $fillable = ['user_id', 'day', 'steps'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
