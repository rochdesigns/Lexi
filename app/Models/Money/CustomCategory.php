<?php

namespace App\Models\Money;

use App\Models\User;
use Database\Factories\Money\CustomCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A category the user added for their own spending.
 */
class CustomCategory extends Model
{
    /** @use HasFactory<CustomCategoryFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'name'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
