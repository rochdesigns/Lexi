<?php

namespace App\Models;

use Database\Factories\VisionBoardItemFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class VisionBoardItem extends Model
{
    /** @use HasFactory<VisionBoardItemFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'image_path', 'caption', 'position'];

    /**
     * Get the private disk vision board images are stored on.
     */
    public static function disk(): Filesystem
    {
        return Storage::disk(config('filesystems.uploads'));
    }

    /**
     * Get the directory a given user's images are stored under.
     */
    public static function directoryFor(int $userId): string
    {
        return 'vision-board/'.$userId;
    }

    /**
     * Get a short-lived URL the browser can use to load this image.
     *
     * The route behind it is authenticated and ownership-checked; the
     * signature limits the damage if the URL itself leaks.
     */
    public function temporaryImageUrl(): string
    {
        return URL::temporarySignedRoute(
            'vision-board-items.image',
            now()->addMinutes((int) config('session.lifetime')),
            ['visionBoardItem' => $this->id],
        );
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
