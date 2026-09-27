<?php

namespace App\Http\Controllers;

use App\Models\VisionBoardItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VisionBoardItemController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user->visionBoardItems()->count() >= 9) {
            return back()->withErrors(['image' => 'Your vision board is full — remove an item before adding another.']);
        }

        $validated = $request->validate([
            'image' => ['required', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'caption' => ['required', 'string', 'max:80'],
        ]);

        $path = $request->file('image')->store(
            VisionBoardItem::directoryFor($user->id),
            ['disk' => config('filesystems.uploads'), 'visibility' => 'private'],
        );

        $position = (int) $user->visionBoardItems()->max('position') + 1;

        $user->visionBoardItems()->create([
            'image_path' => $path,
            'caption' => $validated['caption'],
            'position' => $position,
        ]);

        return back();
    }

    public function update(Request $request, VisionBoardItem $visionBoardItem): RedirectResponse
    {
        abort_if($visionBoardItem->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'image' => ['nullable', 'image', 'max:5120', 'mimes:jpg,jpeg,png,webp'],
            'caption' => ['required', 'string', 'max:80'],
        ]);

        $update = ['caption' => $validated['caption']];

        if ($request->hasFile('image')) {
            VisionBoardItem::disk()->delete($visionBoardItem->image_path);
            $update['image_path'] = $request->file('image')->store(
                VisionBoardItem::directoryFor($visionBoardItem->user_id),
                ['disk' => config('filesystems.uploads'), 'visibility' => 'private'],
            );
        }

        $visionBoardItem->update($update);

        return back();
    }

    public function destroy(Request $request, VisionBoardItem $visionBoardItem): RedirectResponse
    {
        abort_if($visionBoardItem->user_id !== $request->user()->id, 403);

        VisionBoardItem::disk()->delete($visionBoardItem->image_path);
        $visionBoardItem->delete();

        return back();
    }

    /**
     * Stream a vision board image to its owner.
     *
     * The images live on a private disk and have no public URL; this is the
     * only way to read one, and it checks ownership on every request.
     */
    public function image(Request $request, VisionBoardItem $visionBoardItem): StreamedResponse
    {
        abort_if($visionBoardItem->user_id !== $request->user()->id, 403);

        $disk = VisionBoardItem::disk();

        abort_unless($disk->exists($visionBoardItem->image_path), 404);

        return $disk->response(
            $visionBoardItem->image_path,
            headers: ['Cache-Control' => 'private, max-age=3600'],
        );
    }
}
