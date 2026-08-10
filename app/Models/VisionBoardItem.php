<?php

namespace App\Models;

use Database\Factories\VisionBoardItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VisionBoardItem extends Model
{
    /** @use HasFactory<VisionBoardItemFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'image_path', 'caption', 'position'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
