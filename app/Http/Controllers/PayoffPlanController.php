<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Settings;
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
            'starting_balance' => (float) $debt->starting_balance,
            'percent_paid' => $this->percentPaid($debt),
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
            'progress' => $this->computeProgress($debts),
            'pace' => $this->computePace($debts, $settings, $engine),
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

    private function percentPaid(Debt $debt): float
    {
        $starting = (float) $debt->starting_balance;

        if ($starting <= 0.0) {
            return (float) $debt->balance <= 0.0 ? 1.0 : 0.0;
        }

        $paid = max(0.0, $starting - (float) $debt->balance);

        return min(1.0, $paid / $starting);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @return array{totalStartingBalance: float, totalBalance: float, totalPaidOff: float, percentPaid: float, paidOffCount: int, totalDebtsCount: int, milestonesReached: array<int, int>}|null
     */
    private function computeProgress($debts): ?array
    {
        if ($debts->isEmpty()) {
            return null;
        }

        $totalStarting = (float) $debts->sum(fn (Debt $debt) => (float) $debt->starting_balance);
        $totalBalance = (float) $debts->sum(fn (Debt $debt) => (float) $debt->balance);
        $totalPaidOff = max(0.0, $totalStarting - $totalBalance);
        $percentPaid = $totalStarting > 0.0 ? min(1.0, $totalPaidOff / $totalStarting) : 0.0;
        $paidOffCount = $debts->filter(fn (Debt $debt) => (float) $debt->starting_balance > 0.0 && (float) $debt->balance <= 0.0)->count();

        $milestonesReached = collect([25, 50, 75, 100])
            ->filter(fn (int $threshold) => $percentPaid * 100 >= $threshold)
            ->values()
            ->all();

        return [
            'totalStartingBalance' => round($totalStarting, 2),
            'totalBalance' => round($totalBalance, 2),
            'totalPaidOff' => round($totalPaidOff, 2),
            'percentPaid' => $percentPaid,
            'paidOffCount' => $paidOffCount,
            'totalDebtsCount' => $debts->count(),
            'milestonesReached' => $milestonesReached,
        ];
    }

    /**
     * Compares the actual current total balance against the balance the plan
     * would predict at this point, had payments followed it since each debt's
     * starting balance was first recorded.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @return array{elapsedMonths: int, status: string, deltaAmount: float}|null
     */
    private function computePace($debts, Settings $settings, PayoffEngine $engine): ?array
    {
        if ($debts->isEmpty()) {
            return null;
        }

        $planStartedAt = $debts->min(fn (Debt $debt) => $debt->created_at);
        $elapsedMonths = (int) floor($planStartedAt->diffInMonths(Carbon::now()));

        if ($elapsedMonths < 1) {
            return null;
        }

        $baselineDebts = $debts->map(function (Debt $debt) {
            $baseline = clone $debt;
            $baseline->balance = $debt->starting_balance;

            return $baseline;
        });

        $baselineResult = $engine->simulate($baselineDebts, $settings->strategy, (float) $settings->extra_monthly, withTrajectory: true);
        $trajectory = $baselineResult['trajectory'] ?? [0.0];

        $projectedBalance = $trajectory[min($elapsedMonths, count($trajectory) - 1)];
        $actualBalance = (float) $debts->sum(fn (Debt $debt) => (float) $debt->balance);
        $delta = $projectedBalance - $actualBalance;

        $status = match (true) {
            abs($delta) < 1.0 => 'on_pace',
            $delta > 0 => 'ahead',
            default => 'behind',
        };

        return [
            'elapsedMonths' => $elapsedMonths,
            'status' => $status,
            'deltaAmount' => round(abs($delta), 2),
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
