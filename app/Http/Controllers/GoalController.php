<?php

namespace App\Http\Controllers;

use App\Models\Goal;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GoalController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $goals = $user->goals()
            ->with(['transactions' => fn ($q) => $q->with('account')->orderByDesc('transacted_at')->orderByDesc('id')])
            ->orderByRaw('CASE WHEN by_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('by_date')
            ->orderBy('name')
            ->get()
            ->map(fn (Goal $g) => [
                'id' => $g->id,
                'name' => $g->name,
                'icon' => $g->icon,
                'color' => $g->color,
                'bg' => $g->bg,
                'saved' => (float) $g->saved,
                'target' => (float) $g->target,
                'per_month' => (float) $g->per_month,
                'by_date' => $g->by_date?->toDateString(),
                'position' => $g->position,
                'transactions' => $g->transactions->map(fn ($tx) => [
                    'id' => $tx->id,
                    'amount' => (float) $tx->amount,
                    'merchant' => $tx->merchant,
                    'transacted_at' => $tx->transacted_at->toDateString(),
                    'account' => ['id' => $tx->account_id, 'name' => $tx->account->name],
                ]),
            ]);

        $goalTotal = (float) $user->goals()->sum('saved');
        $goalTarget = (float) $user->goals()->sum('target');
        $goalPerMonth = (float) $user->goals()->sum('per_month');

        $accounts = $user->accounts()->orderBy('name')->get()->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
        ]);

        return Inertia::render('Goals', [
            'goals' => $goals,
            'goalTotal' => $goalTotal,
            'goalTarget' => $goalTarget,
            'goalPerMonth' => $goalPerMonth,
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $position = $request->user()->goals()->max('position') + 1;

        $request->user()->goals()->create([...$validated, 'position' => $position]);

        return back();
    }

    public function update(Request $request, Goal $goal): RedirectResponse
    {
        abort_if($goal->user_id !== $request->user()->id, 403);

        $goal->update($request->validate($this->rules()));

        return back();
    }

    public function topUp(Request $request, Goal $goal): RedirectResponse
    {
        abort_if($goal->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'transacted_at' => ['required', 'date'],
        ]);

        $user = $request->user();
        $account = $user->accounts()->findOrFail($validated['account_id']);

        $user->transactions()->create([
            'type' => 'expense',
            'amount' => $validated['amount'],
            'account_id' => $validated['account_id'],
            'goal_id' => $goal->id,
            'merchant' => $goal->name,
            'transacted_at' => $validated['transacted_at'],
        ]);

        $account->decrement('balance', $validated['amount']);
        $goal->increment('saved', $validated['amount']);

        return back();
    }

    public function destroy(Request $request, Goal $goal): RedirectResponse
    {
        abort_if($goal->user_id !== $request->user()->id, 403);

        $goal->delete();

        return back();
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['required', 'string', 'max:50'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bg' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'saved' => ['required', 'numeric', 'min:0'],
            'target' => ['required', 'numeric', 'min:0.01'],
            'per_month' => ['required', 'numeric', 'min:0'],
            'by_date' => ['nullable', 'date'],
        ];
    }
}
