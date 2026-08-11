<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'theme',
        'budget_cycle_days',
        'budget_cycle_anchor',
        'cycle_type',
        'monthly_start_day',
        'fi_number',
        'fi_expected_return_rate',
        'emergency_fund_target_months',
        'investment_monthly_contribution_target',
    ];

    protected $casts = [
        'budget_cycle_days' => 'integer',
        'budget_cycle_anchor' => 'date',
        'cycle_type' => 'string',
        'monthly_start_day' => 'integer',
        'fi_number' => 'decimal:2',
        'fi_expected_return_rate' => 'decimal:2',
        'emergency_fund_target_months' => 'integer',
        'investment_monthly_contribution_target' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array{start: Carbon, end: Carbon, index: int}
     */
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
