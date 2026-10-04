<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\IncomeSourceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * amount is integer centavos received on each pay day. A semi-monthly source
 * pays on pay_day_1 and pay_day_2 every month.
 */
class IncomeSource extends Model
{
    /** @use HasFactory<IncomeSourceFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'amount', 'schedule', 'pay_day_1', 'pay_day_2'];

    /** @var array<string, string> */
    protected $casts = [
        'amount' => 'integer',
        'schedule' => IncomeSchedule::class,
        'pay_day_1' => 'integer',
        'pay_day_2' => 'integer',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return list<int>
     */
    public function payDays(): array
    {
        return $this->schedule === IncomeSchedule::SemiMonthly
            ? [$this->pay_day_1, $this->pay_day_2]
            : [$this->pay_day_1];
    }
}
