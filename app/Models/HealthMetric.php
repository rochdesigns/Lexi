<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HealthMetric extends Model
{
    protected $fillable = ['user_id', 'name', 'value', 'sub', 'pct', 'color', 'icon'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
