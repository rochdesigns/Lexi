<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $accounts = $user->accounts()
            ->orderBy('name')
            ->get();

        $linkedCreditIds = $user->accounts()
            ->where('type', 'credit')
            ->whereHas('linkedDebt')
            ->pluck('id');

        $accountTotal = (float) $accounts->reject(fn ($a) => $linkedCreditIds->contains($a->id))->sum('balance');
        $debtTotal = (float) $user->debts()->sum('balance');
        $netWorth = $accountTotal - $debtTotal;

        $typeOrder = ['cash' => 0, 'credit' => 1, 'investment' => 2];

        $groups = $accounts
            ->groupBy('type')
            ->map(fn ($items, $type) => [
                'type' => $type,
                'label' => match ($type) {
                    'cash' => 'Cash',
                    'credit' => 'Credit',
                    'investment' => 'Investments',
                    default => ucfirst($type),
                },
                'subtotal' => (float) $items->sum('balance'),
                'accounts' => $items->values(),
            ])
            ->sortBy(fn ($g) => $typeOrder[$g['type']] ?? 99)
            ->values();

        $setting = $user->setting;

        if ($setting) {
            $cycle = $setting->cycleForDate(Carbon::now());
            $cycleStart = $cycle['start'];
            $cycleEnd = $cycle['end'];
            $isCalendarMonth = $cycleStart->day === 1 && $cycleEnd->isSameDay($cycleStart->copy()->endOfMonth());
            $cycleLabel = $isCalendarMonth
                ? $cycleStart->format('F Y')
                : $cycleStart->format('M j').' – '.$cycleEnd->format('M j');
        } else {
            $cycleStart = Carbon::now()->startOfMonth();
            $cycleEnd = Carbon::now()->endOfMonth();
            $cycleLabel = Carbon::now()->format('F Y');
        }

        $cycleTransactions = $user->transactions()
            ->whereBetween('transacted_at', [$cycleStart->toDateString(), $cycleEnd->toDateString()])
            ->get(['type', 'amount']);

        $cashIn = (float) $cycleTransactions->where('type', 'income')->sum('amount');
        $cashOut = (float) $cycleTransactions->where('type', 'expense')->sum('amount');
        $cashNet = $cashIn - $cashOut;
        $cashOutPct = $cashIn > 0 ? min(100, (int) round($cashOut / $cashIn * 100)) : 0;

        $previousNetWorth = $netWorth - $cashNet;
        $netWorthChangePct = $previousNetWorth != 0
            ? round($cashNet / abs($previousNetWorth) * 100, 1)
            : 0.0;

        return Inertia::render('Accounts', [
            'netWorth' => $netWorth,
            'accountTotal' => $accountTotal,
            'debtTotal' => $debtTotal,
            'netWorthChangePct' => $netWorthChangePct,
            'groups' => $groups,
            'cycleLabel' => $cycleLabel,
            'cashIn' => $cashIn,
            'cashOut' => $cashOut,
            'cashNet' => $cashNet,
            'cashOutPct' => $cashOutPct,
        ]);
    }

    public function transactions(Request $request, Account $account): JsonResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $user = $request->user();

        $transactions = $user
            ->transactions()
            ->with(['budgetCategory', 'toAccount'])
            ->where(fn ($q) => $q->where('account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->orderByDesc('transacted_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($tx) => [
                'id' => $tx->id,
                'type' => $tx->type,
                'amount' => (float) $tx->amount,
                'merchant' => $tx->merchant,
                'transacted_at' => $tx->transacted_at->toDateString(),
                'account_id' => $tx->account_id,
                'to_account_id' => $tx->to_account_id,
                'budget_category_id' => $tx->budget_category_id,
                'is_destination' => $tx->to_account_id === $account->id,
                'category' => $tx->budgetCategory ? [
                    'id' => $tx->budgetCategory->id,
                    'name' => $tx->budgetCategory->name,
                    'icon' => $tx->budgetCategory->icon,
                    'color' => $tx->budgetCategory->color,
                ] : null,
                'to_account' => $tx->toAccount ? ['id' => $tx->toAccount->id, 'name' => $tx->toAccount->name] : null,
            ]);

        $categories = $user->budgetCategories()
            ->orderBy('name')
            ->get(['id', 'name', 'icon', 'color']);

        return response()->json([
            'transactions' => $transactions,
            'categories' => $categories,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->accountRules());

        $request->user()->accounts()->create($validated);

        return redirect()->route('accounts.index');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $validated = $request->validate($this->accountRules());

        $account->update($validated);

        if ($account->type === 'credit') {
            $account->refresh()->syncLinkedDebt();
        }

        return back();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function accountRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'institution' => ['nullable', 'string', 'max:100'],
            'last4' => ['nullable', 'string', 'max:4', 'regex:/^\d{0,4}$/'],
            'balance' => ['required', 'numeric'],
            'type' => ['required', 'in:cash,credit,investment'],
        ];
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $account->delete();

        return back();
    }
}
