<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request): Response
    {
        $tasks = Task::where('user_id', $request->user()->id)
            ->orderBy('done')
            ->orderBy('created_at')
            ->get();

        $buckets = [
            ['key' => 'today', 'label' => 'Today'],
            ['key' => 'week', 'label' => 'This week'],
            ['key' => 'someday', 'label' => 'Someday'],
        ];

        $groups = collect($buckets)->map(function (array $bucket) use ($tasks): array {
            $grouped = $tasks->where('group', $bucket['key'])->values();

            return [
                'key' => $bucket['key'],
                'label' => $bucket['label'],
                'tasks' => $grouped->map(fn (Task $task): array => [
                    'id' => $task->id,
                    'label' => $task->label,
                    'group' => $task->group,
                    'done' => $task->done,
                ])->values(),
            ];
        })->values();

        return Inertia::render('Tasks', ['groups' => $groups]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'label' => ['required', 'string', 'max:255'],
            'group' => ['required', 'in:today,week,someday'],
        ]);

        Task::create([...$validated, 'user_id' => $request->user()->id]);

        return redirect()->route('tasks.index');
    }

    public function update(Request $request, Task $task): JsonResponse
    {
        abort_if($task->user_id !== $request->user()->id, 403);

        $validated = $request->validate(['done' => ['required', 'boolean']]);

        $task->update($validated);

        return response()->json(['done' => $task->done]);
    }
}
