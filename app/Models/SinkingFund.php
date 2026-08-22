<?php

namespace App\Models;

use App\Contracts\Envelopable;
use Database\Factories\SinkingFundFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SinkingFund extends Model implements Envelopable
{
    /** @use HasFactory<SinkingFundFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'icon', 'color', 'bg', 'saved', 'target', 'per_month', 'due_date', 'position', 'migrated_goal_id'];

    protected $casts = [
        'saved' => 'decimal:2',
        'target' => 'decimal:2',
        'per_month' => 'decimal:2',
        'due_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function envelopeName(): string
    {
        return $this->name;
    }

    public function envelopeIcon(): string
    {
        return $this->icon;
    }

    public function envelopeColor(): string
    {
        return $this->color;
    }

    public function defaultAssignedAmount(): float
    {
        return (float) $this->per_month;
    }
}
