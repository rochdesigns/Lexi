<?php

namespace App\Http\Controllers;

use App\Models\TaskSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class TaskSectionController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['label' => ['required', 'string', 'max:100']]);

        $nextPosition = TaskSection::where('user_id', $request->user()->id)->max('position') + 1;

        TaskSection::create([
            'user_id' => $request->user()->id,
            'label' => $validated['label'],
            'position' => $nextPosition,
        ]);

        return redirect()->route('tasks.index');
    }

    public function update(Request $request, TaskSection $taskSection): RedirectResponse
    {
        abort_if($taskSection->user_id !== $request->user()->id, 403);

        $validated = $request->validate(['label' => ['required', 'string', 'max:100']]);

        $taskSection->update($validated);

        return back();
    }

    public function destroy(Request $request, TaskSection $taskSection): RedirectResponse
    {
        abort_if($taskSection->user_id !== $request->user()->id, 403);

        $taskSection->delete();

        return redirect()->route('tasks.index');
    }

    public function reorder(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'sections' => ['required', 'array'],
            'sections.*.id' => ['required', 'integer'],
            'sections.*.position' => ['required', 'integer', 'min:1'],
        ]);

        $userId = $request->user()->id;

        foreach ($validated['sections'] as $item) {
            TaskSection::where('id', $item['id'])
                ->where('user_id', $userId)
                ->update(['position' => $item['position']]);
        }

        return back();
    }
}
