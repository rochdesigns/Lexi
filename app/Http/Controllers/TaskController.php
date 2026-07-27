<?php

namespace App\Http\Controllers;

use App\Models\Task;
use App\Models\TaskSection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $sections = TaskSection::where('user_id', $request->user()->id)
            ->orderBy('position')
            ->with(['tasks' => fn ($q) => $q->orderBy('done')->orderBy('created_at')])
            ->get();

        $groups = $sections->map(fn (TaskSection $section): array => [
            'id' => $section->id,
            'label' => $section->label,
            'position' => $section->position,
            'color' => $section->color,
            'tasks' => $section->tasks->map(fn (Task $task): array => [
                'id' => $task->id,
                'label' => $task->label,
                'section_id' => $task->section_id,
                'done' => $task->done,
            ])->values()->all(),
        ])->values();

        return Inertia::render('Tasks', ['groups' => $groups]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'section_id' => ['required', 'integer'],
        ]);

        abort_if(
            ! TaskSection::where('id', $validated['section_id'])
                ->where('user_id', $request->user()->id)
                ->exists(),
            403
        );

        Task::create([...$validated, 'user_id' => $request->user()->id]);

        return redirect()->route('tasks.index');
    }

    public function update(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->user_id !== $request->user()->id, 403);

        $validated = $request->validate(['done' => ['required', 'boolean']]);

        $task->update($validated);

        return back();
    }

    public function destroy(Request $request, Task $task): RedirectResponse
    {
        abort_if($task->user_id !== $request->user()->id, 403);

        $task->delete();

        return back();
    }
}
