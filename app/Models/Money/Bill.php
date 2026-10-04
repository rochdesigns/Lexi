<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\BillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * amount is integer centavos per payment. An annual bill is paid once a year
 * in due_month (1 to 12) and is set aside at one twelfth each month.
 */
class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'amount', 'frequency', 'due_day', 'due_month', 'autopay'];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'integer',
        'frequency' => BillFrequency::class,
        'due_day' => 'integer',
        'due_month' => 'integer',
        'autopay' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
