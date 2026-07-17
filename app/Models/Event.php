<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $fillable = ['user_id', 'title', 'date', 'time', 'tag', 'color'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
