<?php

namespace App\Http\Controllers;

use App\Models\VisionBoardItem;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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

        $path = $request->file('image')->store('vision-board/'.$user->id, 'public');

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
            Storage::disk('public')->delete($visionBoardItem->image_path);
            $update['image_path'] = $request->file('image')->store('vision-board/'.$visionBoardItem->user_id, 'public');
        }

        $visionBoardItem->update($update);

        return back();
    }

    public function destroy(Request $request, VisionBoardItem $visionBoardItem): RedirectResponse
    {
        abort_if($visionBoardItem->user_id !== $request->user()->id, 403);

        Storage::disk('public')->delete($visionBoardItem->image_path);
        $visionBoardItem->delete();

        return back();
    }
}
