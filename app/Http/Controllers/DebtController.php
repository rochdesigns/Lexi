<?php

namespace App\Http\Controllers;

use App\Models\Debt;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class DebtController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();
        $today = Carbon::today();

        $debts = $user->debts()
            ->orderBy('position')
            ->orderBy('name')
            ->get()
            ->map(function (Debt $d) use ($today) {
                $monthsLeft = (float) $d->per_month > 0
                    ? (int) ceil((float) $d->balance / (float) $d->per_month)
                    : null;

                return [
                    'id' => $d->id,
                    'name' => $d->name,
                    'icon' => $d->icon,
                    'color' => $d->color,
                    'bg' => $d->bg,
                    'balance' => (float) $d->balance,
                    'original' => (float) $d->original,
                    'per_month' => (float) $d->per_month,
                    'interest_rate' => $d->interest_rate !== null ? (float) $d->interest_rate : null,
                    'due_day' => $d->due_day,
                    'due_label' => $this->dueDateLabel($d->due_day, $today),
                    'months_left' => $monthsLeft,
                    'position' => $d->position,
                ];
            });

        $debtTotal = (float) $user->debts()->sum('balance');
        $debtOriginal = (float) $user->debts()->sum('original');
        $debtMonthly = (float) $user->debts()->sum('per_month');
        $debtCount = $user->debts()->count();

        $debtPaidPct = $debtOriginal > 0
            ? (int) round((($debtOriginal - $debtTotal) / $debtOriginal) * 100)
            : 0;

        $maxMonths = $debts->max('months_left') ?? 0;
        $debtFreeDate = $maxMonths > 0
            ? $today->copy()->addMonths($maxMonths)->format('M Y')
            : null;

        $accounts = $user->accounts()->orderBy('name')->get()->map(fn ($a) => [
            'id' => $a->id,
            'name' => $a->name,
            'type' => $a->type,
        ]);

        return Inertia::render('Debt', [
            'debts' => $debts,
            'debtTotal' => $debtTotal,
            'debtOriginal' => $debtOriginal,
            'debtMonthly' => $debtMonthly,
            'debtCount' => $debtCount,
            'debtPaidPct' => $debtPaidPct,
            'debtFreeDate' => $debtFreeDate,
            'accounts' => $accounts,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->rules());

        $position = $request->user()->debts()->max('position') + 1;

        $request->user()->debts()->create([...$validated, 'position' => $position]);

        return back();
    }

    public function update(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $validated = $request->validate($this->rules());

        $debt->update($validated);

        return back();
    }

    public function makePayment(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $user = $request->user();

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01'],
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'merchant' => ['nullable', 'string', 'max:100'],
            'transacted_at' => ['required', 'date'],
        ]);

        $account = $user->accounts()->findOrFail($validated['account_id']);

        Transaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'type' => 'expense',
            'amount' => $validated['amount'],
            'merchant' => $validated['merchant'] ?? $debt->name,
            'transacted_at' => $validated['transacted_at'],
        ]);

        $account->decrement('balance', $validated['amount']);

        $newBalance = max(0, (float) $debt->balance - (float) $validated['amount']);
        $debt->update(['balance' => $newBalance]);

        return back();
    }

    public function destroy(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $debt->delete();

        return back();
    }

    private function dueDateLabel(?int $dueDay, Carbon $today): ?string
    {
        if (! $dueDay) {
            return null;
        }

        $candidate = $today->copy()->startOfMonth()->addDays($dueDay - 1);

        if ($candidate->lt($today)) {
            $candidate->addMonth();
        }

        return 'Due '.$candidate->format('M j');
    }

    /** @return array<string, array<int, string>> */
    private function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['required', 'string', 'max:50'],
            'color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'bg' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'balance' => ['required', 'numeric', 'min:0'],
            'original' => ['required', 'numeric', 'min:0.01'],
            'per_month' => ['required', 'numeric', 'min:0.01'],
            'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
        ];
    }
}
