<?php

namespace App\Models;

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
}
