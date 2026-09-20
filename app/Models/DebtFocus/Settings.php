<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\SettingsFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Settings extends Model
{
    /** @use HasFactory<SettingsFactory> */
    use HasFactory;

    protected $table = 'debt_focus_settings';

    protected $fillable = [
        'user_id', 'strategy', 'extra_monthly', 'priority_debt_id', 'expected_monthly_income',
    ];

    protected $casts = [
        'extra_monthly' => 'decimal:2',
        'expected_monthly_income' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * A debt the user pinned as the payoff target, overriding the strategy order.
     *
     * @return BelongsTo<Debt, $this>
     */
    public function priorityDebt(): BelongsTo
    {
        return $this->belongsTo(Debt::class, 'priority_debt_id');
    }
}
