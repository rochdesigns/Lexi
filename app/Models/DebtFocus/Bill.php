<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\BillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    protected $table = 'debt_focus_bills';

    protected $fillable = [
        'user_id', 'name', 'expected_amount', 'due_day', 'active',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'active' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BillPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(BillPeriod::class);
    }
}
