<?php

namespace App\Http\Controllers;

use App\Models\SinkingFund;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class SinkingFundController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $today = Carbon::today();

        $funds = $user->sinkingFunds()
            ->with(['transactions' => fn ($q) => $q->where('type', 'expense')->orderByDesc('transacted_at')->limit(5)])
            ->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('due_date')
            ->orderBy('name')
            ->get()
            ->each(function (SinkingFund $f) use ($today): void {
                if (! $f->due_date || (float) $f->saved >= (float) $f->target) {
                    return;
                }

                $monthsLeft = max(1, (int) $today->diffInMonths($f->due_date, false) + 1);
                $suggested = round(((float) $f->target - (float) $f->saved) / $monthsLeft, 2);

                if ($suggested !== (float) $f->per_month) {
                    $f->update(['per_month' => $suggested]);
                }
            })
            ->map(function (SinkingFund $f) use ($today) {
                $monthsLeft = $f->due_date
                    ? max(1, (int) $today->diffInMonths($f->due_date, false) + 1)
                    : null;

                return [
                    'id' => $f->id,
                    'name' => $f->name,
                    'icon' => $f->icon,
                    'color' => $f->color,
                    'bg' => $f->bg,
                    'saved' => (float) $f->saved,
                    'target' => (float) $f->target,
                    'per_month' => (float) $f->per_month,
                    'due_date' => $f->due_date?->toDateString(),
                    'months_left' => $monthsLeft,
                    'position' => $f->position,
                    'payments' => $f->transactions->map(fn ($t) => [
                        'id' => $t->id,
                        'amount' => (float) $t->amount,
                        'merchant' => $t->merchant,
                        'transacted_at' => $t->transacted_at->toDateString(),
                    ]),
                ];
            });

        $saTotal = (float) $user->sinkingFunds()->sum('saved');
        $saTarget = (float) $user->sinkingFunds()->sum('target');
        $saPerMonth = (float) $user->sinkingFunds()->sum('per_month');

        $accounts = $user->accounts()->orderBy('name')->get()->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
        ]);

        return Inertia::render('SetAsides', [
            'funds' => $funds,
            'saTotal' => $saTotal,
            'saTarget' => $saTarget,
            'saPerMonth' => $saPerMonth,
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $position = $request->user()->sinkingFunds()->max('position') + 1;

        $request->user()->sinkingFunds()->create([...$validated, 'position' => $position]);

        return back();
    }

    public function update(Request $request, SinkingFund $sinkingFund): RedirectResponse
    {
        abort_if($sinkingFund->user_id !== $request->user()->id, 403);

        $validated = $request->validate($this->rules());

        $sinkingFund->update($validated);

        return back();
    }

    public function topUp(Request $request, SinkingFund $sinkingFund): RedirectResponse
    {
        abort_if($sinkingFund->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
        ]);

        $sinkingFund->increment('saved', $validated['amount']);

        return back();
    }

    public function destroy(Request $request, SinkingFund $sinkingFund): RedirectResponse
    {
        abort_if($sinkingFund->user_id !== $request->user()->id, 403);

        $sinkingFund->delete();

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
            'due_date' => ['nullable', 'date'],
        ];
    }
}
