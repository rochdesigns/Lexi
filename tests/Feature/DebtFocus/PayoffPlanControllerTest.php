<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Settings;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use App\Services\DebtFocus\LedgerService;
use Carbon\Carbon;

test('guests are redirected to login', function () {
    $this->get('/payoff-plan')->assertRedirect('/login');
});

test('renders empty state props for a user with no debts', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['debts'])->toBe([]);
    expect($props['monthsRemaining'])->toBeNull();
    expect($props['onTrack'])->toBeTrue();
    expect($props['debtFreeDate'])->toBeNull();
    expect($props['progress'])->toBeNull();
    expect($props['pace'])->toBeNull();
});

test('creates default settings for a first-time visitor', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['settings'])->toBe(['strategy' => 'avalanche', 'extra_monthly' => 0.0, 'priority_debt_id' => null]);
    expect(Settings::where('user_id', $user->id)->exists())->toBeTrue();
});

test('renders each debt merged with its live payoff simulation result', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create([
        'balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null, 'debt_type' => 'installment',
    ]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['debts'])->toHaveCount(1);
    expect($props['debts'][0]['id'])->toBe($debt->id);
    expect($props['debts'][0]['payoff_month'])->toBe(12);
    expect($props['debts'][0]['total_interest'])->toBeNull();
    expect($props['monthsRemaining'])->toBe(12);
    expect($props['onTrack'])->toBeTrue();
    expect($props['debtFreeDate'])->not->toBeNull();
});

test('orders debts by highest monthly rate first under the avalanche strategy', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $lowRate = Debt::factory()->for($user)->create(['name' => 'Low rate', 'monthly_rate' => 5]);
    $highRate = Debt::factory()->for($user)->create(['name' => 'High rate', 'monthly_rate' => 20]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $names = collect($response->viewData('page')['props']['debts'])->pluck('name');

    expect($names->all())->toBe(['High rate', 'Low rate']);
});

test('orders debts by lowest starting balance first under the snowball strategy', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'snowball']);
    $bigBalance = Debt::factory()->for($user)->create(['name' => 'Big balance', 'balance' => 5000]);
    $smallBalance = Debt::factory()->for($user)->create(['name' => 'Small balance', 'balance' => 500]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $names = collect($response->viewData('page')['props']['debts'])->pluck('name');

    expect($names->all())->toBe(['Small balance', 'Big balance']);
});

test('stores a new debt for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Visa',
        'balance' => 1500,
        'monthly_rate' => 1.99,
        'minimum_payment' => 45,
        'debt_type' => 'revolving',
    ])->assertRedirect();

    $debt = Debt::where('user_id', $user->id)->firstOrFail();
    expect($debt->name)->toBe('Visa');
    expect((float) $debt->balance)->toBe(1500.0);
});

test('storing a debt requires a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'balance' => 100, 'minimum_payment' => 10, 'debt_type' => 'installment',
    ])->assertSessionHasErrors('name');
});

test('updates an owned debt', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['name' => 'Old name']);

    $this->actingAs($user)->patch("/payoff-plan/debts/{$debt->id}", [
        'name' => 'New name', 'balance' => 200, 'minimum_payment' => 20, 'debt_type' => 'installment',
    ])->assertRedirect();

    expect($debt->fresh()->name)->toBe('New name');
});

test('cannot update another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($owner)->create();

    $this->actingAs($intruder)->patch("/payoff-plan/debts/{$debt->id}", [
        'name' => 'Hacked', 'balance' => 1, 'minimum_payment' => 1, 'debt_type' => 'installment',
    ])->assertForbidden();
});

test('deletes an owned debt', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();

    $this->actingAs($user)->delete("/payoff-plan/debts/{$debt->id}")->assertRedirect();

    expect(Debt::find($debt->id))->toBeNull();
});

test('cannot delete another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($owner)->create();

    $this->actingAs($intruder)->delete("/payoff-plan/debts/{$debt->id}")->assertForbidden();

    expect(Debt::find($debt->id))->not->toBeNull();
});

test('updates strategy and extra monthly payment settings', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 0]);

    $this->actingAs($user)->patch('/payoff-plan/settings', [
        'strategy' => 'snowball', 'extra_monthly' => 150,
    ])->assertRedirect();

    $settings = Settings::where('user_id', $user->id)->firstOrFail();
    expect($settings->strategy)->toBe('snowball');
    expect((float) $settings->extra_monthly)->toBe(150.0);
});

test('rejects an invalid strategy value', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->patch('/payoff-plan/settings', [
        'strategy' => 'lowest-hanging-fruit', 'extra_monthly' => 0,
    ])->assertSessionHasErrors('strategy');
});

test('links a debt to a liability account, keeping the account balance', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000]);
    $account = Account::factory()->for($user)->liability()->create(['balance' => 800]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'account',
    ])->assertRedirect();

    expect($debt->fresh()->linked_account_id)->toBe($account->id);
    expect((float) $debt->fresh()->balance)->toBe(800.0);
    expect((float) $account->fresh()->balance)->toBe(800.0);
});

test('links a debt to a liability account, keeping the debt balance', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000]);
    $account = Account::factory()->for($user)->liability()->create(['balance' => 800]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'debt',
    ])->assertRedirect();

    expect($debt->fresh()->linked_account_id)->toBe($account->id);
    expect((float) $debt->fresh()->balance)->toBe(1000.0);
    expect((float) $account->fresh()->balance)->toBe(1000.0);
});

test('cannot link a debt to another user\'s account', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($intruder)->create();
    $account = Account::factory()->for($owner)->liability()->create();

    $this->actingAs($intruder)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'account',
    ])->assertSessionHasErrors('linked_account_id');

    expect($debt->fresh()->linked_account_id)->toBeNull();
});

test('cannot link a debt to a non-liability account', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();
    $account = Account::factory()->for($user)->create(['is_liability' => false]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'account',
    ])->assertSessionHasErrors('linked_account_id');

    expect($debt->fresh()->linked_account_id)->toBeNull();
});

test('cannot link another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($owner)->create();
    $account = Account::factory()->for($intruder)->liability()->create();

    $this->actingAs($intruder)->post("/payoff-plan/debts/{$debt->id}/link", [
        'linked_account_id' => $account->id,
        'balance_choice' => 'account',
    ])->assertForbidden();
});

test('unlinking a debt snapshots the synced balance and clears the link', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create(['balance' => 800]);
    $debt = Debt::factory()->for($user)->create(['balance' => 800, 'linked_account_id' => $account->id]);

    (new LedgerService)->recordExpense($account, 50, 'Purchase');

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/unlink")->assertRedirect();

    expect($debt->fresh()->linked_account_id)->toBeNull();
    expect((float) $debt->fresh()->balance)->toBe(850.0);
});

test('cannot unlink another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = Account::factory()->for($owner)->liability()->create();
    $debt = Debt::factory()->for($owner)->create(['linked_account_id' => $account->id]);

    $this->actingAs($intruder)->post("/payoff-plan/debts/{$debt->id}/unlink")->assertForbidden();

    expect($debt->fresh()->linked_account_id)->toBe($account->id);
});

test('renders a linked debt\'s live balance from its ledger account, not its own column', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create(['balance' => 800]);
    $debt = Debt::factory()->for($user)->create([
        'balance' => 800, 'minimum_payment' => 100, 'monthly_rate' => null, 'debt_type' => 'revolving',
        'linked_account_id' => $account->id,
    ]);

    (new LedgerService)->recordExpense($account, 50, 'Purchase');

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props']['debts'];

    expect((float) $props[0]['balance'])->toBe(850.0);
    expect($props[0]['linked_account_id'])->toBe($account->id);
    expect($props[0]['linked_account_name'])->toBe($account->name);
});

test('a newly created debt starts with starting_balance equal to its balance and zero percent paid', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props']['debts'][0];

    expect((float) $debt->fresh()->starting_balance)->toBe(1000.0);
    expect($props['starting_balance'])->toBe(1000.0);
    expect($props['percent_paid'])->toBe(0.0);
});

test('editing a debt\'s balance does not reset its starting_balance or its percent_paid progress', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['name' => 'Visa', 'balance' => 1000]);

    $this->actingAs($user)->patch("/payoff-plan/debts/{$debt->id}", [
        'name' => 'Visa', 'balance' => 750, 'minimum_payment' => 20, 'debt_type' => 'installment',
    ])->assertRedirect();

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $props = $response->viewData('page')['props']['debts'][0];

    expect($props['starting_balance'])->toBe(1000.0);
    expect($props['percent_paid'])->toBe(0.25);
});

test('exposes overall progress totals, percent paid, and reached milestones', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1000]);
    $debt->update(['balance' => 750]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $progress = $response->viewData('page')['props']['progress'];

    expect($progress['totalStartingBalance'])->toBe(1000.0);
    expect($progress['totalBalance'])->toBe(750.0);
    expect($progress['totalPaidOff'])->toBe(250.0);
    expect($progress['percentPaid'])->toBe(0.25);
    expect($progress['milestonesReached'])->toBe([25]);
});

test('counts debts that have reached a zero balance for the paid-off milestone', function () {
    $user = User::factory()->create();
    $paidOff = Debt::factory()->for($user)->create(['balance' => 500]);
    $paidOff->update(['balance' => 0]);
    Debt::factory()->for($user)->create(['balance' => 300]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $progress = $response->viewData('page')['props']['progress'];

    expect($progress['paidOffCount'])->toBe(1);
    expect($progress['totalDebtsCount'])->toBe(2);
});

test('pace is null when the plan started less than a month ago', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 1000]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();

    expect($response->viewData('page')['props']['pace'])->toBeNull();
});

test('pace reports being ahead of the plan when the actual balance is lower than projected', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null]);
    Debt::where('id', $debt->id)->update(['created_at' => now()->subMonths(3)]);
    $debt->update(['balance' => 850]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $pace = $response->viewData('page')['props']['pace'];

    expect($pace['elapsedMonths'])->toBe(3);
    expect($pace['status'])->toBe('ahead');
    expect($pace['deltaAmount'])->toBe(50.0);
});

test('pace reports being behind the plan when the actual balance is higher than projected', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null]);
    Debt::where('id', $debt->id)->update(['created_at' => now()->subMonths(3)]);
    $debt->update(['balance' => 950]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $pace = $response->viewData('page')['props']['pace'];

    expect($pace['elapsedMonths'])->toBe(3);
    expect($pace['status'])->toBe('behind');
    expect($pace['deltaAmount'])->toBe(50.0);
});

test('index exposes the user\'s liability accounts, with balance, for the debt-linking picker', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->liability()->create(['name' => 'Visa', 'balance' => 640]);
    Account::factory()->for($user)->create(['name' => 'Checking', 'is_liability' => false]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $liabilityAccounts = $response->viewData('page')['props']['liabilityAccounts'];

    expect(collect($liabilityAccounts)->pluck('name')->all())->toBe(['Visa']);
    expect((float) $liabilityAccounts[0]['balance'])->toBe(640.0);
});

test('a linked revolving debt shows spend vs payment for the current month', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create([
        'debt_type' => 'revolving',
        'linked_account_id' => $card->id,
        'balance' => 500,
    ]);
    $ledger = new LedgerService;
    $ledger->recordExpense($card, 150, 'Groceries', now());
    $ledger->recordTransfer($checking, $card, 200, 'Card payment', now());

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $spendVsPayment = $response->viewData('page')['props']['debts'][0]['spend_vs_payment'];

    expect($spendVsPayment['spent'])->toBe(150.0);
    expect($spendVsPayment['paid'])->toBe(200.0);
    expect($spendVsPayment['net'])->toBe(50.0);
});

test('spend vs payment excludes transactions from a previous month', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    $debt = Debt::factory()->for($user)->create(['debt_type' => 'revolving', 'linked_account_id' => $card->id]);
    (new LedgerService)->recordExpense($card, 999, 'Old spend', now()->subMonthNoOverflow()->startOfMonth());

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $spendVsPayment = $response->viewData('page')['props']['debts'][0]['spend_vs_payment'];

    expect($spendVsPayment['spent'])->toBe(0.0);
    expect($spendVsPayment['paid'])->toBe(0.0);
});

test('an installment debt has no spend vs payment figure even if linked', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    Debt::factory()->for($user)->create(['debt_type' => 'installment', 'linked_account_id' => $card->id]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();

    expect($response->viewData('page')['props']['debts'][0]['spend_vs_payment'])->toBeNull();
});

test('a revolving debt with no linked account has no spend vs payment figure', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['debt_type' => 'revolving', 'linked_account_id' => null]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();

    expect($response->viewData('page')['props']['debts'][0]['spend_vs_payment'])->toBeNull();
});

test('spend vs payment only counts expenses and incoming transfers on the linked account, not payments made from it', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create(['debt_type' => 'revolving', 'linked_account_id' => $card->id]);
    $ledger = new LedgerService;
    $ledger->recordIncome($checking, 50, 'Unrelated income', now());
    $ledger->recordTransfer($card, $checking, 20, 'Refund routed elsewhere', now());

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $spendVsPayment = $response->viewData('page')['props']['debts'][0]['spend_vs_payment'];

    expect($spendVsPayment['spent'])->toBe(0.0);
    expect($spendVsPayment['paid'])->toBe(0.0);
});

test('spend vs payment does not leak another user\'s transactions on an account with the same id coincidence', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $ownerCard = Account::factory()->for($owner)->liability()->create();
    $debt = Debt::factory()->for($owner)->create(['debt_type' => 'revolving', 'linked_account_id' => $ownerCard->id]);
    (new LedgerService)->recordExpense($ownerCard, 300, 'Owner spend', now());

    $intruderCard = Account::factory()->for($intruder)->liability()->create();
    (new LedgerService)->recordExpense($intruderCard, 999, 'Intruder spend', now());

    $response = $this->actingAs($owner)->get('/payoff-plan')->assertOk();
    $spendVsPayment = $response->viewData('page')['props']['debts'][0]['spend_vs_payment'];

    expect($spendVsPayment['spent'])->toBe(300.0);
});

test('spend vs payment reflects negative net when spending outpaces payments', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create(['debt_type' => 'revolving', 'linked_account_id' => $card->id]);
    $ledger = new LedgerService;
    $ledger->recordExpense($card, 400, 'Big purchase', now());
    $ledger->recordTransfer($checking, $card, 100, 'Small payment', now());

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $spendVsPayment = $response->viewData('page')['props']['debts'][0]['spend_vs_payment'];

    expect($spendVsPayment['net'])->toBe(-300.0);
});

test('totals are zero for a user with no debts', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $totals = $response->viewData('page')['props']['totals'];

    expect($totals)->toBe([
        'totalMinimumMonthly' => 0.0,
        'totalMonthlyPayment' => 0.0,
        'totalInterestProjected' => 0.0,
        'totalInterestIsComplete' => false,
    ]);
});

test('totals sum minimum payments across debts and add the extra monthly payment', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['extra_monthly' => 50]);
    Debt::factory()->for($user)->create(['minimum_payment' => 100, 'monthly_rate' => null]);
    Debt::factory()->for($user)->create(['minimum_payment' => 75, 'monthly_rate' => null]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $totals = $response->viewData('page')['props']['totals'];

    expect($totals['totalMinimumMonthly'])->toBe(175.0);
    expect($totals['totalMonthlyPayment'])->toBe(225.0);
});

test('projected total interest sums known per-debt interest and flags when a debt is missing a rate', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 200, 'monthly_rate' => 2]);
    Debt::factory()->for($user)->create(['balance' => 500, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $totals = $response->viewData('page')['props']['totals'];

    expect($totals['totalInterestProjected'])->toBeGreaterThan(0.0);
    expect($totals['totalInterestIsComplete'])->toBeFalse();
});

test('flags a debt due within the next 3 days as due soon', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        Debt::factory()->for($user)->create(['name' => 'Visa', 'due_day' => 17]);

        $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
        $notifications = $response->viewData('page')['props']['notifications'];

        expect($notifications)->toContain(['type' => 'debt_due_soon', 'severity' => 'warning', 'subject' => 'Visa', 'days' => 2]);
    });
});

test('does not flag a debt as due soon once its minimum payment has been made this period', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        $checking = Account::factory()->for($user)->create(['balance' => 1000]);
        $debt = Debt::factory()->for($user)->create([
            'name' => 'Maya CC', 'due_day' => 17, 'balance' => 1000, 'minimum_payment' => 100, 'monthly_rate' => null,
        ]);

        $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
            'from_account_id' => $checking->id, 'amount' => 100, 'occurred_at' => '2026-06-15',
        ])->assertRedirect();

        $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();

        expect($response->viewData('page')['props']['notifications'])->toBe([]);
    });
});

test('does not flag a debt whose due day is more than 3 days away', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        Debt::factory()->for($user)->create(['name' => 'Visa', 'due_day' => 28, 'balance' => 1000, 'minimum_payment' => 100, 'monthly_rate' => null]);

        $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();

        expect($response->viewData('page')['props']['notifications'])->toBe([]);
    });
});

test('flags a debt as not on track when its minimum payment does not cover accruing interest', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create([
        'name' => 'Maxed card', 'balance' => 5000, 'minimum_payment' => 1, 'monthly_rate' => 5, 'due_day' => null,
    ]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $notifications = $response->viewData('page')['props']['notifications'];

    expect($notifications)->toContain(['type' => 'debt_not_on_track', 'severity' => 'critical', 'subject' => 'Maxed card']);
});

// ── Redesign: month rail, baseline, extra curve, strategy comparison ──────

test('the month rail carries one bar per month of the plan, ending at zero', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['monthRail'])->toHaveCount(13);
    expect($props['monthRail'][0]['remaining'])->toBe(1200.0);
    expect($props['monthRail'][12]['remaining'])->toBe(0.0);
    expect($props['monthRail'][0]['label'])->toBe(now()->startOfMonth()->format('M Y'));
});

test('the month rail is null when the plan never finishes', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 10000, 'minimum_payment' => 5, 'monthly_rate' => 12]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['monthRail'])->toBeNull();
});

test('the baseline reports the months and interest the standing extra payment has bought', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 12000, 'minimum_payment' => 200, 'monthly_rate' => 1]);
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 800]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['baseline']['monthsSaved'])->toBeGreaterThan(0);
    expect($props['baseline']['interestAvoided'])->toBeGreaterThan(0.0);
    expect($props['baseline']['months'])->toBeGreaterThan($props['monthsRemaining']);
});

test('the baseline reports nothing saved when no extra payment is set', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'monthly_rate' => null]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['baseline']['monthsSaved'])->toBe(0);
    expect($props['baseline']['interestAvoided'])->toBe(0.0);
});

test('the extra-payment curve starts at zero and reaches at least the unplaced money', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 12000, 'minimum_payment' => 200, 'monthly_rate' => 1]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['extraCurve'][0]['extra'])->toBe(0.0);
    expect(end($props['extraCurve'])['extra'])->toBeGreaterThanOrEqual($props['unplaced']);
    expect(end($props['extraCurve'])['months'])->toBeLessThan($props['extraCurve'][0]['months']);
});

test('both strategies are projected side by side with their queues', function () {
    $user = User::factory()->create();
    $small = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 50, 'monthly_rate' => 0.5]);
    $large = Debt::factory()->for($user)->create(['balance' => 9000, 'minimum_payment' => 200, 'monthly_rate' => 3]);
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 500]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['strategyCompare']['avalanche']['queue'][0]['id'])->toBe($large->id);
    expect($props['strategyCompare']['snowball']['queue'][0]['id'])->toBe($small->id);
    expect($props['strategyCompare']['avalanche']['firstDebtGoneMonth'])->not->toBeNull();
    expect($props['strategyCompare']['avalanche']['totalInterest'])
        ->toBeLessThan($props['strategyCompare']['snowball']['totalInterest']);
});

test('a debt whose minimum cannot cover its interest is flagged with the top-up that fixes it', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 10000, 'minimum_payment' => 100, 'monthly_rate' => 1.4]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['debts'][0]['going_backwards'])->toBeTrue();
    expect($props['debts'][0]['top_up_to_clear'])->toBeGreaterThan(40.0);
    expect($props['debts'][0]['monthly_interest'])->toBe(140.0);
});

// ── Redesign: pinning a target debt ──────────────────────────────────────

test('pinning a debt moves it to the front of the queue and takes the extra payment', function () {
    $user = User::factory()->create();
    $highRate = Debt::factory()->for($user)->create(['balance' => 9000, 'minimum_payment' => 200, 'monthly_rate' => 3]);
    $pinned = Debt::factory()->for($user)->create(['balance' => 9000, 'minimum_payment' => 200, 'monthly_rate' => 0.5]);

    $this->actingAs($user)->patch('/payoff-plan/priority', ['priority_debt_id' => $pinned->id])->assertRedirect();

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['settings']['priority_debt_id'])->toBe($pinned->id);
    expect($props['debts'][0]['id'])->toBe($pinned->id);
    expect($props['debts'][0]['is_priority'])->toBeTrue();
    expect(collect($props['debts'])->firstWhere('id', $highRate->id)['is_priority'])->toBeFalse();
});

test('clearing the pin hands the order back to the strategy', function () {
    $user = User::factory()->create();
    $highRate = Debt::factory()->for($user)->create(['balance' => 9000, 'minimum_payment' => 200, 'monthly_rate' => 3]);
    $pinned = Debt::factory()->for($user)->create(['balance' => 9000, 'minimum_payment' => 200, 'monthly_rate' => 0.5]);
    Settings::factory()->for($user)->create(['priority_debt_id' => $pinned->id]);

    $this->actingAs($user)->patch('/payoff-plan/priority', ['priority_debt_id' => null])->assertRedirect();

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['debts'][0]['id'])->toBe($highRate->id);
});

test('a pin cannot be set on another user\'s debt', function () {
    $user = User::factory()->create();
    $otherDebt = Debt::factory()->for(User::factory())->create();

    $this->actingAs($user)->patch('/payoff-plan/priority', ['priority_debt_id' => $otherDebt->id])
        ->assertSessionHasErrors('priority_debt_id');
});

test('a pin on an already-cleared debt is ignored so the queue does not stall', function () {
    $user = User::factory()->create();
    $live = Debt::factory()->for($user)->create(['balance' => 5000, 'minimum_payment' => 200, 'monthly_rate' => 1]);
    $cleared = Debt::factory()->for($user)->create(['balance' => 0, 'minimum_payment' => 200, 'monthly_rate' => 1]);
    Settings::factory()->for($user)->create(['priority_debt_id' => $cleared->id]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect(collect($props['debts'])->firstWhere('id', $live->id)['is_priority'])->toBeFalse();
    expect(collect($props['debts'])->firstWhere('id', $cleared->id)['is_priority'])->toBeFalse();
    expect(collect($props['debts'])->firstWhere('id', $cleared->id)['is_pinned'])->toBeTrue();
});

// ── Redesign: sending an extra payment ───────────────────────────────────

test('sending extra to a linked debt records a transfer tagged with the debt', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 20000]);
    $card = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 8000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 8000, 'minimum_payment' => 300, 'linked_account_id' => $card->id]);
    Settings::factory()->for($user)->create(['extra_monthly' => 0]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/extra", [
        'amount' => 1000,
        'from_account_id' => $checking->id,
        'make_permanent' => false,
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(19000.0);
    expect((float) $card->fresh()->balance)->toBe(7000.0);
    expect(Transaction::where('debt_id', $debt->id)->count())->toBe(1);
    expect((float) $user->debtFocusSettings()->first()->extra_monthly)->toBe(0.0);
});

test('sending extra to an unlinked debt reduces its balance directly', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 20000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 8000, 'minimum_payment' => 300, 'linked_account_id' => null]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/extra", [
        'amount' => 1000,
        'from_account_id' => $checking->id,
        'make_permanent' => false,
    ])->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(7000.0);
    expect((float) $checking->fresh()->balance)->toBe(19000.0);
});

test('making an extra payment permanent raises the standing monthly extra', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 20000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 8000, 'minimum_payment' => 300, 'linked_account_id' => null]);
    Settings::factory()->for($user)->create(['extra_monthly' => 500]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/extra", [
        'amount' => 1000,
        'from_account_id' => $checking->id,
        'make_permanent' => true,
    ])->assertRedirect();

    expect((float) $user->debtFocusSettings()->first()->extra_monthly)->toBe(1500.0);
});

test('extra payments cannot be sent to another user\'s debt', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 20000]);
    $otherDebt = Debt::factory()->for(User::factory())->create();

    $this->actingAs($user)->post("/payoff-plan/debts/{$otherDebt->id}/extra", [
        'amount' => 1000,
        'from_account_id' => $checking->id,
        'make_permanent' => false,
    ])->assertForbidden();
});

test('extra payments cannot be drawn from a liability account', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 8000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 8000, 'minimum_payment' => 300]);

    $this->actingAs($user)->post("/payoff-plan/debts/{$debt->id}/extra", [
        'amount' => 1000,
        'from_account_id' => $card->id,
        'make_permanent' => false,
    ])->assertSessionHasErrors('from_account_id');
});

test('a user with no debts is not told the plan never finishes', function () {
    $user = User::factory()->create();

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['debts'])->toBe([]);
    expect($props['monthsRemaining'])->toBeNull();
    expect($props['onTrack'])->toBeTrue();
    expect($props['monthRail'])->toBeNull();
    expect($props['extraCurve'])->toBe([]);
    expect($props['progress'])->toBeNull();
    expect($props['unplaced'])->toBe(0.0);
    expect($props['strategyCompare']['avalanche']['months'])->toBeNull();
    expect($props['strategyCompare']['avalanche']['queue'])->toBe([]);
});

// ── 15e: previewing a debt before adding it ──────────────────────────────

test('previewing a debt reports the horizon it would push the plan to', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 12000, 'minimum_payment' => 1000, 'monthly_rate' => null]);

    $preview = $this->actingAs($user)->postJson('/payoff-plan/debts/preview', [
        'balance' => 6000,
        'monthly_rate' => null,
        'minimum_payment' => 500,
    ])->assertOk()->json();

    // The existing debt clears in 12 months; the new one needs 12 of its own,
    // then inherits the freed minimum, so the horizon does not move.
    expect($preview['monthsRemaining'])->toBe(12);
    expect($preview['monthsAdded'])->toBe(0);
    expect($preview['queuePosition'])->not->toBeNull();
    expect($preview['debtFreeDate'])->toBe(now()->addMonthsNoOverflow(12)->format('M Y'));
});

test('a debt that outlasts the current plan reports the months it adds', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 500, 'monthly_rate' => null]);

    $preview = $this->actingAs($user)->postJson('/payoff-plan/debts/preview', [
        'balance' => 10000,
        'monthly_rate' => null,
        'minimum_payment' => 500,
    ])->assertOk()->json();

    expect($preview['monthsAdded'])->toBeGreaterThan(0);
    expect($preview['monthsRemaining'])->toBeGreaterThan(2);
});

test('previewing an interest-bearing debt reports the interest it adds', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 5000, 'minimum_payment' => 500, 'monthly_rate' => 1]);

    $preview = $this->actingAs($user)->postJson('/payoff-plan/debts/preview', [
        'balance' => 18400,
        'monthly_rate' => 2.9,
        'minimum_payment' => 1150,
    ])->assertOk()->json();

    expect($preview['interestAdded'])->toBeGreaterThan(0.0);
});

test('the preview rail marks the months the new debt pushes past the current horizon', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 500, 'monthly_rate' => null]);

    $preview = $this->actingAs($user)->postJson('/payoff-plan/debts/preview', [
        'balance' => 10000,
        'monthly_rate' => null,
        'minimum_payment' => 500,
    ])->assertOk()->json();

    $rail = $preview['rail'];

    expect($rail)->not->toBeNull();
    expect(collect($rail)->firstWhere('added', true))->not->toBeNull();
    expect($rail[0]['added'])->toBeFalse();
    expect((float) end($rail)['remaining'])->toBe(0.0);
});

test('a preview that never finishes reports null months rather than a guess', function () {
    $user = User::factory()->create();

    $preview = $this->actingAs($user)->postJson('/payoff-plan/debts/preview', [
        'balance' => 10000,
        'monthly_rate' => 12,
        'minimum_payment' => 5,
    ])->assertOk()->json();

    expect($preview['monthsRemaining'])->toBeNull();
    expect($preview['debtFreeDate'])->toBeNull();
    expect($preview['monthsAdded'])->toBeNull();
    expect($preview['rail'])->toBeNull();
});

test('the preview reports where the debt lands in the queue and what sits ahead of it', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Sharp', 'balance' => 9000, 'minimum_payment' => 400, 'monthly_rate' => 5]);
    Debt::factory()->for($user)->create(['name' => 'Middling', 'balance' => 9000, 'minimum_payment' => 400, 'monthly_rate' => 3]);
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 0]);

    $preview = $this->actingAs($user)->postJson('/payoff-plan/debts/preview', [
        'balance' => 9000,
        'monthly_rate' => 4,
        'minimum_payment' => 400,
    ])->assertOk()->json();

    expect($preview['queuePosition'])->toBe(2);
    expect($preview['queueAhead'])->toBe(['Sharp']);
});

test('previewing a debt saves nothing', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->postJson('/payoff-plan/debts/preview', [
        'balance' => 6000,
        'monthly_rate' => 2,
        'minimum_payment' => 500,
    ])->assertOk();

    expect(Debt::count())->toBe(0);
});

test('guests cannot preview a debt', function () {
    $this->postJson('/payoff-plan/debts/preview', [
        'balance' => 6000, 'monthly_rate' => null, 'minimum_payment' => 500,
    ])->assertUnauthorized();
});

test('a debt is stored with the kind it was added as', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Metrobank',
        'balance' => 18400,
        'monthly_rate' => 2.9,
        'minimum_payment' => 1150,
        'debt_type' => 'revolving',
        'kind' => 'card',
        'due_day' => 7,
    ])->assertRedirect();

    $debt = Debt::where('user_id', $user->id)->firstOrFail();

    expect($debt->kind)->toBe('card');
    expect($debt->debt_type)->toBe('revolving');
    expect((float) $debt->starting_balance)->toBe(18400.0);
});

test('an unrecognised kind is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Mystery',
        'balance' => 100,
        'monthly_rate' => null,
        'minimum_payment' => 10,
        'debt_type' => 'installment',
        'kind' => 'mortgage',
        'due_day' => null,
    ])->assertSessionHasErrors('kind');
});

test('each debt carries the kind it was recorded as, so the queue can show the right icon', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Home Credit', 'debt_type' => 'installment', 'kind' => 'installment', 'balance' => 5000]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['debts'][0]['kind'])->toBe('installment');
});

test('the standing monthly extra can be set directly and reshapes the plan', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 12000, 'minimum_payment' => 200, 'monthly_rate' => 1]);
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 0]);

    $before = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    $this->actingAs($user)->patch('/payoff-plan/settings', [
        'strategy' => 'avalanche',
        'extra_monthly' => 800,
    ])->assertRedirect();

    $after = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect((float) $user->debtFocusSettings()->first()->extra_monthly)->toBe(800.0);
    expect($after['totals']['totalMonthlyPayment'])->toBe(1000.0);
    expect($after['monthsRemaining'])->toBeLessThan($before['monthsRemaining']);
    expect($after['baseline']['monthsSaved'])->toBeGreaterThan(0);
});

test('the monthly extra cannot be set negative', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 500]);

    $this->actingAs($user)->patch('/payoff-plan/settings', [
        'strategy' => 'avalanche', 'extra_monthly' => -100,
    ])->assertSessionHasErrors('extra_monthly');

    expect((float) $user->debtFocusSettings()->first()->extra_monthly)->toBe(500.0);
});

// ── Linking a card at the moment the debt is added ───────────────────────

test('a debt can be linked to a ledger account as it is created', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->create(['is_liability' => true, 'name' => 'Maya Black', 'balance' => 0]);

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Maya Black',
        'balance' => 18400,
        'monthly_rate' => 2.9,
        'minimum_payment' => 1150,
        'debt_type' => 'revolving',
        'kind' => 'card',
        'linked_account_id' => $card->id,
        'due_day' => 7,
    ])->assertRedirect();

    $debt = Debt::where('user_id', $user->id)->firstOrFail();

    expect($debt->linked_account_id)->toBe($card->id);
    // The balance entered is the one that counts, so the card is brought to it.
    expect((float) $card->fresh()->balance)->toBe(18400.0);
});

test('a debt linked at creation reads its balance from the account afterwards', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 0]);

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Maya Black', 'balance' => 18400, 'monthly_rate' => 2.9,
        'minimum_payment' => 1150, 'debt_type' => 'revolving', 'kind' => 'card',
        'linked_account_id' => $card->id, 'due_day' => 7,
    ])->assertRedirect();

    $card->update(['balance' => 15000]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['debts'][0]['balance'])->toBe(15000.0);
    expect($props['debts'][0]['linked_account_name'])->toBe($card->name);
});

test('a debt cannot be created against an asset account', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false]);

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Nope', 'balance' => 100, 'minimum_payment' => 10,
        'debt_type' => 'revolving', 'linked_account_id' => $checking->id,
    ])->assertSessionHasErrors('linked_account_id');
});

test('a debt cannot be created against another user\'s account', function () {
    $user = User::factory()->create();
    $theirs = Account::factory()->for(User::factory())->create(['is_liability' => true]);

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Nope', 'balance' => 100, 'minimum_payment' => 10,
        'debt_type' => 'revolving', 'linked_account_id' => $theirs->id,
    ])->assertSessionHasErrors('linked_account_id');
});

test('a debt created without an account is left unlinked and untouched', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 500]);

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Ate Vane', 'balance' => 230000, 'minimum_payment' => 15000,
        'debt_type' => 'installment', 'kind' => 'person',
    ])->assertRedirect();

    expect(Debt::where('user_id', $user->id)->firstOrFail()->linked_account_id)->toBeNull();
    expect((float) $card->fresh()->balance)->toBe(500.0);
});

// ── "Going backwards" means the plan fails to clear it ───────────────────

test('a debt the plan clears is not flagged as going backwards, even if its own minimum is short', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 0]);
    // Clears in a few months, then hands its minimum on.
    Debt::factory()->for($user)->create(['name' => 'Loan', 'balance' => 18000, 'minimum_payment' => 6000, 'monthly_rate' => null]);
    // On its own this never falls: no minimum against a 3% monthly rate.
    Debt::factory()->for($user)->create(['name' => 'Card', 'balance' => 29500, 'minimum_payment' => 0, 'monthly_rate' => 3]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];
    $card = collect($props['debts'])->firstWhere('name', 'Card');

    expect($props['monthsRemaining'])->not->toBeNull();
    expect($card['payoff_month'])->not->toBeNull();
    // The plan clears it, so it must not be shown as not falling...
    expect($card['going_backwards'])->toBeFalse();
    // ...but the reason it needed rescuing is still reported.
    expect($card['minimum_covers_interest'])->toBeFalse();
});

test('a debt nothing in the plan can clear is still flagged as going backwards', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 0]);
    Debt::factory()->for($user)->create(['name' => 'Card', 'balance' => 29500, 'minimum_payment' => 0, 'monthly_rate' => 3]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['monthsRemaining'])->toBeNull();
    expect($props['debts'][0]['going_backwards'])->toBeTrue();
    expect($props['debts'][0]['minimum_covers_interest'])->toBeFalse();
});

test('a cleared debt is never flagged as going backwards', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Done', 'balance' => 0, 'minimum_payment' => 0, 'monthly_rate' => 3]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['debts'][0]['payoff_month'])->toBeNull();
    expect($props['debts'][0]['going_backwards'])->toBeFalse();
});

// ── A card added with no minimum gets a workable one ─────────────────────

test('a revolving debt added without a minimum is given one that clears it', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Maya Black',
        'balance' => 29499.90,
        'monthly_rate' => 3,
        'minimum_payment' => 0,
        'debt_type' => 'revolving',
        'kind' => 'card',
    ])->assertRedirect();

    $debt = Debt::where('user_id', $user->id)->firstOrFail();

    expect((float) $debt->minimum_payment)->toBe(1180.0);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['monthsRemaining'])->not->toBeNull();
    expect($props['debts'][0]['going_backwards'])->toBeFalse();
});

test('a minimum that was actually given is left alone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Maya Black', 'balance' => 29499.90, 'monthly_rate' => 3,
        'minimum_payment' => 2500, 'debt_type' => 'revolving', 'kind' => 'card',
    ])->assertRedirect();

    expect((float) Debt::where('user_id', $user->id)->firstOrFail()->minimum_payment)->toBe(2500.0);
});

test('an installment debt with no minimum is left as entered', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Ate Vane', 'balance' => 230000, 'monthly_rate' => null,
        'minimum_payment' => 0, 'debt_type' => 'installment', 'kind' => 'person',
    ])->assertRedirect();

    expect((float) Debt::where('user_id', $user->id)->firstOrFail()->minimum_payment)->toBe(0.0);
});

test('a rateless card with no minimum has nothing to infer from and is left alone', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/payoff-plan/debts', [
        'name' => 'Store card', 'balance' => 5000, 'monthly_rate' => null,
        'minimum_payment' => 0, 'debt_type' => 'revolving', 'kind' => 'card',
    ])->assertRedirect();

    expect((float) Debt::where('user_id', $user->id)->firstOrFail()->minimum_payment)->toBe(0.0);
});

test('an over-committed month reads as no headroom rather than a negative', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['minimum_payment' => 5000]);

    $props = $this->actingAs($user)->get('/payoff-plan')->assertOk()->viewData('page')['props'];

    expect($props['unplaced'])->toBe(0.0);
});
