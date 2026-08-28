<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Services\DebtFocus\PayoffEngine;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class PayoffPlanController extends Controller
{
    public function index(Request $request, PayoffEngine $engine): Response
    {
        $user = $request->user();
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);

        $debts = $user->debtFocusDebts()->with('linkedAccount')->get();

        foreach ($debts as $debt) {
            if ($debt->linkedAccount !== null) {
                $debt->balance = $debt->linkedAccount->balance;
            }
        }

        $result = $engine->simulate($debts, $settings->strategy, (float) $settings->extra_monthly);
        $perDebt = collect($result['perDebt'])->keyBy('debtId');

        $ordered = $this->orderByPriority($debts, $settings->strategy);

        $debtProps = $ordered->map(fn (Debt $debt) => [
            'id' => $debt->id,
            'name' => $debt->name,
            'balance' => (float) $debt->balance,
            'monthly_rate' => $debt->monthly_rate !== null ? (float) $debt->monthly_rate : null,
            'minimum_payment' => (float) $debt->minimum_payment,
            'debt_type' => $debt->debt_type,
            'due_day' => $debt->due_day,
            'linked_account_id' => $debt->linked_account_id,
            'linked_account_name' => $debt->linkedAccount?->name,
            'payoff_month' => $perDebt->get($debt->id)['payoffMonth'] ?? null,
            'total_interest' => $perDebt->get($debt->id)['totalInterest'] ?? null,
        ])->values();

        $liabilityAccounts = $user->debtFocusAccounts()->where('is_liability', true)->orderBy('name')->get()
            ->map(fn (Account $account) => ['id' => $account->id, 'name' => $account->name, 'balance' => (float) $account->balance])
            ->values();

        $monthsRemaining = $debts->isEmpty() ? null : $result['months'];
        $debtFreeDate = $monthsRemaining !== null
            ? Carbon::today()->addMonthsNoOverflow($monthsRemaining)->format('M Y')
            : null;

        return Inertia::render('PayoffPlan', [
            'debts' => $debtProps,
            'liabilityAccounts' => $liabilityAccounts,
            'settings' => ['strategy' => $settings->strategy, 'extra_monthly' => (float) $settings->extra_monthly],
            'monthsRemaining' => $monthsRemaining,
            'onTrack' => $debts->isEmpty() ? true : $result['onTrack'],
            'debtFreeDate' => $debtFreeDate,
        ]);
    }

    public function storeDebt(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->debtRules());

        $request->user()->debtFocusDebts()->create($validated);

        return back();
    }

    public function updateDebt(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $debt->update($request->validate($this->debtRules()));

        return back();
    }

    public function destroyDebt(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $debt->delete();

        return back();
    }

    public function linkDebt(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'linked_account_id' => [
                'required',
                'integer',
                Rule::exists('debt_focus_accounts', 'id')
                    ->where('user_id', $request->user()->id)
                    ->where('is_liability', true),
            ],
            'balance_choice' => ['required', 'string', 'in:debt,account'],
        ]);

        $account = Account::findOrFail($validated['linked_account_id']);

        if ($validated['balance_choice'] === 'account') {
            $debt->balance = $account->balance;
        } else {
            $account->balance = $debt->balance;
            $account->save();
        }

        $debt->linked_account_id = $account->id;
        $debt->save();

        return back();
    }

    public function unlinkDebt(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        if ($debt->linkedAccount !== null) {
            $debt->balance = $debt->linkedAccount->balance;
        }

        $debt->linked_account_id = null;
        $debt->save();

        return back();
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'strategy' => ['required', 'string', 'in:avalanche,snowball'],
            'extra_monthly' => ['required', 'numeric', 'min:0'],
        ]);

        $settings = $request->user()->debtFocusSettings()->firstOrCreate([]);
        $settings->update($validated);

        return back();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function debtRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'balance' => ['required', 'numeric', 'min:0'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_payment' => ['required', 'numeric', 'min:0'],
            'debt_type' => ['required', 'string', 'in:revolving,installment'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
        ];
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @return Collection<int, Debt>
     */
    private function orderByPriority($debts, string $strategy)
    {
        $ordered = $debts->values()->all();

        usort($ordered, fn (Debt $a, Debt $b) => $strategy === 'snowball'
            ? [(float) $a->balance, $a->id] <=> [(float) $b->balance, $b->id]
            : [(float) ($b->monthly_rate ?? -1), $a->id] <=> [(float) ($a->monthly_rate ?? -1), $b->id]);

        return collect($ordered);
    }
}
