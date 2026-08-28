<?php

namespace App\Models\DebtFocus;

use Database\Factories\DebtFocus\EnvelopePeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvelopePeriod extends Model
{
    /** @use HasFactory<EnvelopePeriodFactory> */
    use HasFactory;

    protected $table = 'debt_focus_envelope_periods';

    protected $fillable = [
        'envelope_id', 'year', 'month', 'target', 'sweep_offered_at', 'sweep_accepted',
    ];

    protected $casts = [
        'target' => 'decimal:2',
        'sweep_offered_at' => 'datetime',
        'sweep_accepted' => 'boolean',
    ];

    /**
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
