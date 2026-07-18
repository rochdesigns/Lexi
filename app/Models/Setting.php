<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = [
        'user_id',
        'theme',
        'budget_cycle_days',
        'budget_cycle_anchor',
    ];

    protected $casts = [
        'budget_cycle_days' => 'integer',
        'budget_cycle_anchor' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cycleForDate(Carbon $date): array
    {
        $anchor = ($this->budget_cycle_anchor ?? Carbon::parse($this->created_at))->copy()->startOfDay();
        $target = $date->copy()->startOfDay();
        $days = $this->budget_cycle_days;

        $diffDays = (int) round(($target->timestamp - $anchor->timestamp) / 86400);
        $index = (int) floor($diffDays / $days);

        $start = $anchor->copy()->addDays($index * $days);
        $end = $start->copy()->addDays($days - 1);

        return ['start' => $start, 'end' => $end, 'index' => $index];
    }
}
