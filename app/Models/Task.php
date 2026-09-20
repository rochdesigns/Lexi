<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Task extends Model
{
    protected $fillable = ['user_id', 'label', 'section_id', 'done'];

    protected $casts = ['done' => 'boolean'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<TaskSection, $this>
     */
    public function section(): BelongsTo
    {
        return $this->belongsTo(TaskSection::class, 'section_id');
    }
}
