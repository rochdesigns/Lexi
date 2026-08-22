<?php

namespace App\Models;

use Database\Factories\EnvelopeAssignmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class EnvelopeAssignment extends Model
{
    /** @use HasFactory<EnvelopeAssignmentFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'envelope_type', 'envelope_id', 'cycle_start', 'assigned'];

    protected $casts = [
        'cycle_start' => 'date',
        'assigned' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function envelope(): MorphTo
    {
        return $this->morphTo();
    }
}
