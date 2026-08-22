<?php

namespace App\Models;

use App\Contracts\Envelopable;
use Database\Factories\BudgetCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BudgetCategory extends Model implements Envelopable
{
    /** @use HasFactory<BudgetCategoryFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'group', 'icon', 'color', 'limit_amount'];

    protected $casts = ['limit_amount' => 'decimal:2'];

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
        return (float) $this->limit_amount;
    }
}
