<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\EnvelopeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Envelope extends Model
{
    /** @use HasFactory<EnvelopeFactory> */
    use HasFactory;

    protected $table = 'debt_focus_envelopes';

    protected $fillable = [
        'user_id', 'name', 'icon', 'default_target', 'active',
    ];

    protected $casts = [
        'default_target' => 'decimal:2',
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
     * @return HasMany<EnvelopePeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(EnvelopePeriod::class);
    }
}
