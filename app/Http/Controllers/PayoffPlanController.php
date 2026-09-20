<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Settings;
use App\Models\DebtFocus\Transaction;
use App\Services\DebtFocus\BudgetService;
use App\Services\DebtFocus\LedgerService;
use App\Services\DebtFocus\PayoffEngine;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
use Inertia\Inertia;
use Inertia\Response;

class PayoffPlanController extends Controller
{
    /** Bars the month rail is thinned down to before it stops reading as a rail. */
    private const MAX_RAIL_BARS = 72;

    /** Sample points along the extra-payment curve the Send-extra slider reads. */
    private const EXTRA_CURVE_STEPS = 24;

    /** The curve always reaches at least this far, so the slider has room even with nothing spare. */
    private const MIN_EXTRA_CURVE_CEILING = 5000.0;

    /** Payments listed in the one-debt sheet before it links out to the ledger. */
    private const PAYMENTS_SHOWN_PER_DEBT = 6;

    /** Stand-in id for the unsaved debt being previewed, kept clear of real rows. */
    private const CANDIDATE_DEBT_ID = -1;

    public function index(Request $request, PayoffEngine $engine, BudgetService $budget): Response
    {
        $user = $request->user();
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);

        $debts = $user->debtFocusDebts()->with('linkedAccount')->get();

        foreach ($debts as $debt) {
            if ($debt->linkedAccount !== null) {
                $debt->balance = $debt->linkedAccount->balance;
            }
        }

        $strategy = $settings->strategy;
        $extraMonthly = (float) $settings->extra_monthly;
        $priorityDebtId = $this->activePriorityDebtId($debts, $settings);

        $result = $engine->simulate($debts, $strategy, $extraMonthly, withTrajectory: true, priorityDebtId: $priorityDebtId);
        $perDebt = $this->keyByDebt($result['perDebt']);

        $ordered = $engine->orderByPriority($debts, $strategy, $priorityDebtId);
        $paidByDebt = $this->debtPaymentsForPeriod($debts);
        $paymentsByDebt = $this->recentPaymentsByDebt($debts);

        $debtProps = $ordered->map(fn (Debt $debt) => [
            'id' => $debt->id,
            'name' => $debt->name,
            'balance' => (float) $debt->balance,
            'starting_balance' => (float) $debt->starting_balance,
            'percent_paid' => $this->percentPaid($debt),
            'monthly_rate' => $debt->monthly_rate !== null ? (float) $debt->monthly_rate : null,
            'minimum_payment' => (float) $debt->minimum_payment,
            'debt_type' => $debt->debt_type,
            'kind' => $debt->kind,
            'due_day' => $debt->due_day,
            'linked_account_id' => $debt->linked_account_id,
            'linked_account_name' => $debt->linkedAccount?->name,
            'payoff_month' => $perDebt[$debt->id]['payoffMonth'] ?? null,
            'total_interest' => $perDebt[$debt->id]['totalInterest'] ?? null,
            'spend_vs_payment' => $this->spendVsPayment($debt),
            'is_priority' => $debt->id === $priorityDebtId,
            'is_pinned' => $debt->id === $settings->priority_debt_id,
            // Whether the *plan* fails to clear this debt — not whether its own
            // minimum covers its interest. A debt can be short on its own and
            // still clear, once a debt ahead of it frees up its minimum.
            'going_backwards' => (float) $debt->balance > 0.0
                && ($perDebt[$debt->id]['payoffMonth'] ?? null) === null,
            // The reason behind it, kept separate so the fix sheet can explain
            // itself even when the plan rescues the debt anyway.
            'minimum_covers_interest' => ! $engine->isGoingBackwards($debt),
            'top_up_to_clear' => $engine->minimumTopUpToClear($debt),
            'monthly_interest' => $debt->monthly_rate !== null
                ? round((float) $debt->balance * ((float) $debt->monthly_rate / 100), 2)
                : null,
            'paid_this_period' => round((float) ($paidByDebt[$debt->id] ?? 0), 2),
            'payments' => $paymentsByDebt[$debt->id] ?? [],
            'cleared_at' => (float) $debt->balance <= 0.0 ? $this->clearedLabel($debt) : null,
        ])->values();

        $liabilityAccounts = $user->debtFocusAccounts()->where('is_liability', true)->orderBy('name')->get()
            ->map(fn (Account $account) => ['id' => $account->id, 'name' => $account->name, 'balance' => (float) $account->balance])
            ->values();

        $assetAccounts = $user->debtFocusAccounts()->where('is_liability', false)->orderBy('name')->get()
            ->map(fn (Account $account) => ['id' => $account->id, 'name' => $account->name, 'balance' => (float) $account->balance])
            ->values();

        $monthsRemaining = $debts->isEmpty() ? null : $result['months'];
        $debtFreeDate = $monthsRemaining !== null
            ? Carbon::today()->addMonthsNoOverflow($monthsRemaining)->format('M Y')
            : null;

        $minimumsTotal = (float) $debts->filter(fn (Debt $debt) => (float) $debt->balance > 0)
            ->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        // This page reads the hand as headroom to throw at a debt, so an
        // over-committed month is simply no headroom. The Budget page is where
        // the shortfall itself is meant to be faced.
        $unplaced = round(max(0.0, $budget->unplacedFor($user, (int) now()->year, (int) now()->month, $minimumsTotal)), 2);

        return Inertia::render('PayoffPlan', [
            'debts' => $debtProps,
            'liabilityAccounts' => $liabilityAccounts,
            'assetAccounts' => $assetAccounts,
            'settings' => [
                'strategy' => $strategy,
                'extra_monthly' => $extraMonthly,
                'priority_debt_id' => $settings->priority_debt_id,
            ],
            'monthsRemaining' => $monthsRemaining,
            'onTrack' => $debts->isEmpty() ? true : $result['onTrack'],
            'debtFreeDate' => $debtFreeDate,
            'progress' => $this->computeProgress($debts),
            'pace' => $this->computePace($debts, $settings, $engine),
            'totals' => $this->computeTotals($debts, $perDebt, $settings),
            'monthRail' => $this->buildMonthRail($result['trajectory'] ?? null, $monthsRemaining),
            'baseline' => $this->computeBaseline($debts, $settings, $engine, $result, $priorityDebtId),
            'extraCurve' => $this->buildExtraCurve($debts, $strategy, $extraMonthly, $unplaced, $engine, $priorityDebtId),
            'unplaced' => $unplaced,
            'strategyCompare' => $this->compareStrategies($debts, $extraMonthly, $engine),
            'notifications' => $this->buildNotifications($debts, $perDebt, $paidByDebt),
        ]);
    }

    /**
     * Sends a one-off extra payment at a debt, optionally raising the standing
     * monthly extra to match so the plan keeps the new pace.
     */
    public function sendExtra(Request $request, Debt $debt, LedgerService $ledger): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'from_account_id' => ['required', 'integer', $this->assetAccountOwnershipRule($request->user()->id)],
            'occurred_at' => ['nullable', 'date'],
            'make_permanent' => ['required', 'boolean'],
        ]);

        $amount = round((float) $validated['amount'], 2);
        $fromAccount = Account::findOrFail((int) $validated['from_account_id']);
        $occurredAt = isset($validated['occurred_at']) ? Carbon::parse($validated['occurred_at']) : Carbon::today();
        $description = "Extra payment: {$debt->name}";

        if ($debt->linked_account_id !== null) {
            $transaction = $ledger->recordTransfer($fromAccount, $debt->linkedAccount, $amount, $description, $occurredAt);
        } else {
            $transaction = $ledger->recordExpense($fromAccount, $amount, $description, $occurredAt);
            $debt->update(['balance' => max(0.0, (float) $debt->balance - $amount)]);
        }

        $transaction->update(['debt_id' => $debt->id]);

        if ($validated['make_permanent']) {
            $settings = $request->user()->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
            $settings->update(['extra_monthly' => round((float) $settings->extra_monthly + $amount, 2)]);
        }

        return back();
    }

    /**
     * Pins a debt as the payoff target ahead of the strategy order, or clears the
     * pin so the strategy decides again.
     */
    public function setPriorityDebt(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'priority_debt_id' => [
                'nullable',
                'integer',
                Rule::exists('debt_focus_debts', 'id')->where('user_id', $user->id),
            ],
        ]);

        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $settings->update(['priority_debt_id' => $validated['priority_debt_id'] ?? null]);

        return back();
    }

    /**
     * Projects the plan as it would stand with one more debt in it, without
     * saving anything. The Add-a-debt sheet promises the horizon before you
     * commit, and the only way to keep that promise honest is to ask the same
     * engine the page itself is drawn from.
     */
    public function previewDebt(Request $request, PayoffEngine $engine): JsonResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'balance' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_payment' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
        ]);

        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $extraMonthly = (float) $settings->extra_monthly;

        $debts = $user->debtFocusDebts()->with('linkedAccount')->get();

        foreach ($debts as $debt) {
            if ($debt->linkedAccount !== null) {
                $debt->balance = $debt->linkedAccount->balance;
            }
        }

        $priorityDebtId = $this->activePriorityDebtId($debts, $settings);

        $before = $engine->simulate($debts, $settings->strategy, $extraMonthly, priorityDebtId: $priorityDebtId);

        $candidate = new Debt([
            'name' => 'Candidate',
            'balance' => (float) $validated['balance'],
            'monthly_rate' => $validated['monthly_rate'] ?? null,
            'minimum_payment' => (float) $validated['minimum_payment'],
        ]);
        // The engine indexes its working set by id, so an unsaved debt needs one
        // that cannot collide with a real row.
        $candidate->id = self::CANDIDATE_DEBT_ID;

        $withCandidate = $debts->push($candidate)->values();

        $after = $engine->simulate($withCandidate, $settings->strategy, $extraMonthly, withTrajectory: true, priorityDebtId: $priorityDebtId);

        $queue = $engine->orderByPriority(
            $withCandidate->filter(fn (Debt $debt) => (float) $debt->balance > 0),
            $settings->strategy,
            $priorityDebtId,
        )->values();

        $position = $queue->search(fn (Debt $debt) => $debt->id === self::CANDIDATE_DEBT_ID);

        return response()->json([
            'monthsRemaining' => $after['months'],
            'debtFreeDate' => $after['months'] !== null
                ? Carbon::today()->addMonthsNoOverflow($after['months'])->format('M Y')
                : null,
            'monthsAdded' => $after['months'] !== null && $before['months'] !== null
                ? $after['months'] - $before['months']
                : null,
            'interestAdded' => round(
                (float) collect($after['perDebt'])->sum('totalInterest') - (float) collect($before['perDebt'])->sum('totalInterest'),
                2,
            ),
            'queuePosition' => $position === false ? null : $position + 1,
            'queueAhead' => $position === false || $position === 0
                ? []
                : $queue->take($position)->pluck('name')->all(),
            'rail' => $this->buildCandidateRail($after['trajectory'] ?? null, $after['months'], $before['months']),
        ]);
    }

    public function storeDebt(Request $request, PayoffEngine $engine): RedirectResponse
    {
        $validated = $request->validate($this->debtRules($request->user()->id));

        // A revolving debt with no minimum never clears, so rather than record a
        // plan that cannot finish, fall back to a minimum that at least covers
        // the interest. The Add-a-debt sheet fills this in as you type; this
        // catches anything that arrives without it.
        if (($validated['debt_type'] ?? null) === 'revolving' && (float) ($validated['minimum_payment'] ?? 0) <= 0.0) {
            $suggested = $engine->suggestedMinimumPayment(
                (float) $validated['balance'],
                isset($validated['monthly_rate']) ? (float) $validated['monthly_rate'] : null,
            );

            if ($suggested !== null) {
                $validated['minimum_payment'] = $suggested;
            }
        }

        $debt = $request->user()->debtFocusDebts()->create($validated);

        // A debt that mirrors an account is read from that account, so the
        // balance just entered has to land there or it would be ignored.
        if ($debt->linked_account_id !== null) {
            $account = Account::query()->findOrFail($debt->linked_account_id);
            $account->balance = $debt->balance;
            $account->save();
        }

        return back();
    }

    public function updateDebt(Request $request, Debt $debt): RedirectResponse
    {
        abort_if($debt->user_id !== $request->user()->id, 403);

        $debt->update($request->validate($this->debtRules($request->user()->id)));

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

        $account = Account::query()->findOrFail((int) $validated['linked_account_id']);

        if ($validated['balance_choice'] === 'account') {
            $debt->balance = $account->balance;
        } else {
            $account->balance = $debt->balance;
            $account->save();
        }

        $debt->fill(['linked_account_id' => $account->id])->save();

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
     * The pinned target, ignored once that debt is cleared so the queue does not
     * stall on a debt with nothing left to pay.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     */
    private function activePriorityDebtId($debts, Settings $settings): ?int
    {
        if ($settings->priority_debt_id === null) {
            return null;
        }

        $pinned = $debts->firstWhere('id', $settings->priority_debt_id);

        return $pinned !== null && (float) $pinned->balance > 0.0 ? $settings->priority_debt_id : null;
    }

    /**
     * One bar per month of what is still owed, labelled with the month it lands
     * in. Long plans are thinned out so the rail stays a rail rather than a
     * thousand hairlines; the final month is always kept.
     *
     * @param  array<int, float>|null  $trajectory
     * @return array<int, array{label: string, remaining: float}>|null
     */
    private function buildMonthRail(?array $trajectory, ?int $monthsRemaining): ?array
    {
        if ($trajectory === null || $monthsRemaining === null || $monthsRemaining < 1) {
            return null;
        }

        $points = array_slice($trajectory, 0, $monthsRemaining + 1);
        $stride = (int) max(1, ceil(count($points) / self::MAX_RAIL_BARS));

        $rail = [];
        $start = Carbon::today()->startOfMonth();

        foreach ($points as $index => $remaining) {
            if ($index % $stride !== 0 && $index !== count($points) - 1) {
                continue;
            }

            $rail[] = [
                'label' => $start->copy()->addMonthsNoOverflow($index)->format('M Y'),
                'remaining' => round((float) $remaining, 2),
            ];
        }

        return $rail;
    }

    /**
     * The rail for the Add-a-debt preview: the plan as it would be, with the
     * months this debt pushes past the current horizon marked so they can be
     * drawn as what it costs you.
     *
     * @param  array<int, float>|null  $trajectory
     * @return array<int, array{remaining: float, added: bool}>|null
     */
    private function buildCandidateRail(?array $trajectory, ?int $months, ?int $monthsBefore): ?array
    {
        if ($trajectory === null || $months === null || $months < 1) {
            return null;
        }

        $points = array_slice($trajectory, 0, $months + 1);
        $stride = (int) max(1, ceil(count($points) / self::MAX_RAIL_BARS));

        $rail = [];

        foreach ($points as $index => $remaining) {
            if ($index % $stride !== 0 && $index !== count($points) - 1) {
                continue;
            }

            $rail[] = [
                'remaining' => round((float) $remaining, 2),
                'added' => $monthsBefore !== null && $index > $monthsBefore,
            ];
        }

        return $rail;
    }

    /**
     * What the plan would look like with no extra payment at all, so the page can
     * say how much the extra has bought so far.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @param  array{months: int|null, perDebt: array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>}  $current
     * @return array{months: int|null, debtFreeDate: string|null, monthsSaved: int|null, interestAvoided: float|null}
     */
    private function computeBaseline($debts, Settings $settings, PayoffEngine $engine, array $current, ?int $priorityDebtId): array
    {
        if ($debts->isEmpty() || (float) $settings->extra_monthly <= 0.0) {
            return ['months' => $current['months'], 'debtFreeDate' => null, 'monthsSaved' => 0, 'interestAvoided' => 0.0];
        }

        $baseline = $engine->simulate($debts, $settings->strategy, 0.0, priorityDebtId: $priorityDebtId);

        $monthsSaved = $baseline['months'] !== null && $current['months'] !== null
            ? max(0, $baseline['months'] - $current['months'])
            : null;

        $baselineInterest = (float) collect($baseline['perDebt'])->sum('totalInterest');
        $currentInterest = (float) collect($current['perDebt'])->sum('totalInterest');

        return [
            'months' => $baseline['months'],
            'debtFreeDate' => $baseline['months'] !== null
                ? Carbon::today()->addMonthsNoOverflow($baseline['months'])->format('M Y')
                : null,
            'monthsSaved' => $monthsSaved,
            'interestAvoided' => round(max(0.0, $baselineInterest - $currentInterest), 2),
        ];
    }

    /**
     * The plan sampled across a range of extra payments, so the Send-extra slider
     * can read off the effect of each step without asking the server again. The
     * range reaches past the money on hand so the slider always has room to move.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @return array<int, array{extra: float, months: int|null, totalInterest: float}>
     */
    private function buildExtraCurve($debts, string $strategy, float $extraMonthly, float $unplaced, PayoffEngine $engine, ?int $priorityDebtId): array
    {
        if ($debts->isEmpty()) {
            return [];
        }

        $maxExtra = max($unplaced, $extraMonthly * 2, self::MIN_EXTRA_CURVE_CEILING);

        return $engine->extraCurve($debts, $strategy, round($maxExtra, 2), self::EXTRA_CURVE_STEPS, $priorityDebtId);
    }

    /**
     * Both orderings run side by side, so switching strategy can show its cost
     * rather than silently reordering the queue.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @return array<string, array{months: int|null, debtFreeDate: string|null, totalInterest: float, firstDebtGoneMonth: int|null, queue: array<int, array{id: int, name: string, monthly_rate: float|null, balance: float}>}>
     */
    private function compareStrategies($debts, float $extraMonthly, PayoffEngine $engine): array
    {
        $comparison = [];

        foreach (['avalanche', 'snowball'] as $strategy) {
            $result = $engine->simulate($debts, $strategy, $extraMonthly);
            $payoffMonths = collect($result['perDebt'])->pluck('payoffMonth')->filter(fn (?int $month) => $month !== null);

            $comparison[$strategy] = [
                'months' => $debts->isEmpty() ? null : $result['months'],
                'debtFreeDate' => $result['months'] !== null
                    ? Carbon::today()->addMonthsNoOverflow($result['months'])->format('M Y')
                    : null,
                'totalInterest' => round((float) collect($result['perDebt'])->sum('totalInterest'), 2),
                'firstDebtGoneMonth' => $payoffMonths->isEmpty() ? null : (int) $payoffMonths->min(),
                'queue' => $engine->orderByPriority($debts->filter(fn (Debt $debt) => (float) $debt->balance > 0), $strategy)
                    ->map(fn (Debt $debt) => [
                        'id' => $debt->id,
                        'name' => $debt->name,
                        'monthly_rate' => $debt->monthly_rate !== null ? (float) $debt->monthly_rate : null,
                        'balance' => (float) $debt->balance,
                    ])->values()->all(),
            ];
        }

        return $comparison;
    }

    /**
     * The most recent payments logged against each debt, for the one-debt sheet.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @return array<int, list<array{id: int, amount: float, occurred_at: string, description: string|null}>>
     */
    private function recentPaymentsByDebt($debts): array
    {
        if ($debts->isEmpty()) {
            return [];
        }

        $payments = [];

        $transactions = Transaction::query()
            ->whereIn('debt_id', $debts->pluck('id'))
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->get();

        foreach ($transactions as $transaction) {
            $debtId = (int) $transaction->debt_id;

            if (count($payments[$debtId] ?? []) >= self::PAYMENTS_SHOWN_PER_DEBT) {
                continue;
            }

            $payments[$debtId][] = [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
                'occurred_at' => $transaction->occurred_at->format('j M'),
                'description' => $transaction->description,
            ];
        }

        return $payments;
    }

    /**
     * When a cleared debt was last paid — the closest honest stand-in for the
     * date it hit zero, since the clearing itself is not stamped anywhere.
     */
    private function clearedLabel(Debt $debt): ?string
    {
        $lastPayment = Transaction::query()
            ->where('debt_id', $debt->id)
            ->orderByDesc('occurred_at')
            ->first();

        return $lastPayment?->occurred_at->format('F Y');
    }

    private function liabilityAccountOwnershipRule(int $userId): Exists
    {
        return Rule::exists('debt_focus_accounts', 'id')
            ->where('user_id', $userId)
            ->where(fn ($query) => $query->where('is_liability', true));
    }

    private function assetAccountOwnershipRule(int $userId): Exists
    {
        // See BudgetController::assetAccountOwnershipRule — a raw `false` in an
        // Exists rule stringifies to "" and silently never matches.
        return Rule::exists('debt_focus_accounts', 'id')
            ->where('user_id', $userId)
            ->where(fn ($query) => $query->where('is_liability', false));
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function debtRules(int $userId): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'balance' => ['required', 'numeric', 'min:0'],
            'monthly_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'minimum_payment' => ['required', 'numeric', 'min:0'],
            'debt_type' => ['required', 'string', 'in:revolving,installment'],
            'kind' => ['nullable', 'string', 'in:card,loan,installment,person'],
            // A debt can be mirrored by a ledger account from the moment it is
            // added, rather than being created and then linked separately.
            'linked_account_id' => ['sometimes', 'nullable', 'integer', $this->liabilityAccountOwnershipRule($userId)],
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
     * @return array{spent: float, paid: float, net: float, period_label: string}|null
     */
    private function spendVsPayment(Debt $debt): ?array
    {
        if ($debt->debt_type !== 'revolving' || $debt->linked_account_id === null) {
            return null;
        }

        $periodStart = Carbon::now()->startOfMonth()->toDateString();
        $periodEnd = Carbon::now()->endOfMonth()->toDateString();

        $spent = (float) Transaction::query()
            ->where('user_id', $debt->user_id)
            ->where('type', 'expense')
            ->where('account_id', $debt->linked_account_id)
            ->whereBetween('occurred_at', [$periodStart, $periodEnd])
            ->sum('amount');

        $paid = (float) Transaction::query()
            ->where('user_id', $debt->user_id)
            ->where('type', 'transfer')
            ->where('to_account_id', $debt->linked_account_id)
            ->whereBetween('occurred_at', [$periodStart, $periodEnd])
            ->sum('amount');

        return [
            'spent' => round($spent, 2),
            'paid' => round($paid, 2),
            'net' => round($paid - $spent, 2),
            'period_label' => Carbon::now()->format('F Y'),
        ];
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
     * @param  array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>  $perDebt
     * @return array{totalMinimumMonthly: float, totalMonthlyPayment: float, totalInterestProjected: float, totalInterestIsComplete: bool}
     */
    private function computeTotals($debts, array $perDebt, Settings $settings): array
    {
        $totalMinimumMonthly = (float) $debts->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        $knownInterestDebts = array_filter($perDebt, fn (array $debt) => $debt['totalInterest'] !== null);

        return [
            'totalMinimumMonthly' => round($totalMinimumMonthly, 2),
            'totalMonthlyPayment' => round($totalMinimumMonthly + (float) $settings->extra_monthly, 2),
            'totalInterestProjected' => round((float) array_sum(array_column($knownInterestDebts, 'totalInterest')), 2),
            'totalInterestIsComplete' => $debts->isNotEmpty() && count($knownInterestDebts) === $debts->count(),
        ];
    }

    /**
     * Indexes a simulation's per-debt results by debt id.
     *
     * @param  array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>  $perDebt
     * @return array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>
     */
    private function keyByDebt(array $perDebt): array
    {
        $keyed = [];

        foreach ($perDebt as $entry) {
            $keyed[$entry['debtId']] = $entry;
        }

        return $keyed;
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @return Collection<int, float>
     */
    private function debtPaymentsForPeriod($debts): Collection
    {
        $periodStart = Carbon::now()->startOfMonth()->toDateString();
        $periodEnd = Carbon::now()->endOfMonth()->toDateString();

        return Transaction::query()
            ->whereIn('debt_id', $debts->pluck('id'))
            ->whereDate('occurred_at', '>=', $periodStart)
            ->whereDate('occurred_at', '<=', $periodEnd)
            ->selectRaw('debt_id, SUM(amount) as total')
            ->groupBy('debt_id')
            ->pluck('total', 'debt_id');
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Debt>  $debts
     * @param  array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>  $perDebt
     * @param  Collection<int, float>  $paidByDebt
     * @return array<int, array{type: string, severity: string, subject: string, days?: int}>
     */
    private function buildNotifications($debts, array $perDebt, Collection $paidByDebt): array
    {
        $today = now()->day;
        $notifications = [];

        foreach ($debts as $debt) {
            $sim = $perDebt[$debt->id] ?? null;

            if ($sim !== null && $debt->monthly_rate !== null && $sim['payoffMonth'] === null) {
                $notifications[] = ['type' => 'debt_not_on_track', 'severity' => 'critical', 'subject' => $debt->name];
            }

            if ($debt->due_day === null) {
                continue;
            }

            $paid = (float) ($paidByDebt[$debt->id] ?? 0);

            if ($paid >= (float) $debt->minimum_payment) {
                continue;
            }

            $daysUntilDue = $debt->due_day - $today;

            if ($daysUntilDue >= 0 && $daysUntilDue <= 3) {
                $notifications[] = ['type' => 'debt_due_soon', 'severity' => 'warning', 'subject' => $debt->name, 'days' => $daysUntilDue];
            }
        }

        usort($notifications, fn (array $a, array $b) => ($a['severity'] === 'critical' ? 0 : 1) <=> ($b['severity'] === 'critical' ? 0 : 1));

        return $notifications;
    }
}
