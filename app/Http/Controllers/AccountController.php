<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()
            ->accounts()
            ->orderBy('name')
            ->get();

        $netWorth = (float) $accounts->sum('balance');

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

        $setting = $request->user()->setting;
        $cycleLabel = Carbon::now()->format('F Y');
        if ($setting?->budget_cycle_anchor) {
            $cycle = $setting->cycleForDate(Carbon::now());
            $cycleLabel = $cycle['start']->format('M j').' – '.$cycle['end']->format('M j');
        }

        return Inertia::render('Accounts', [
            'netWorth' => $netWorth,
            'groups' => $groups,
            'cycleLabel' => $cycleLabel,
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
