<?php

namespace App\Http\Controllers;

use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TaskController extends Controller
{
    public function update(Request $request, Task $task): JsonResponse
    {
        abort_if($task->user_id !== $request->user()->id, 403);

        $validated = $request->validate(['done' => ['required', 'boolean']]);

        $task->update($validated);

        return response()->json(['ok' => true]);
    }
}
