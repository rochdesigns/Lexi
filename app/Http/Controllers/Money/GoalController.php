<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\GoalRequest;
use App\Models\Money\Goal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class GoalController extends Controller
{
    public function store(GoalRequest $request): RedirectResponse
    {
        $request->user()->goals()->create($request->goalAttributes());

        return back();
    }

    public function update(GoalRequest $request, Goal $goal): RedirectResponse
    {
        abort_if($goal->user_id !== $request->user()->id, 403);

        $goal->update($request->goalAttributes());

        return back();
    }

    public function destroy(Request $request, Goal $goal): RedirectResponse
    {
        abort_if($goal->user_id !== $request->user()->id, 403);

        $goal->delete();

        return back();
    }
}
