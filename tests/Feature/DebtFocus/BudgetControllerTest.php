<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Settings;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use App\Services\DebtFocus\BudgetService;
use App\Services\DebtFocus\LedgerService;
use Carbon\Carbon;

test('guests are redirected to login', function () {
    $this->get('/budget')->assertRedirect('/login');
});

test('renders empty state for a user with nothing set up', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['debtMinimums'])->toBe([]);
    expect($props['bills'])->toBe([]);
    expect($props['envelopes'])->toBe([]);
    expect($props['sweepCandidates'])->toBe([]);
    expect($props['priorityDebt'])->toBeNull();
    expect((float) $props['leftover'])->toBe(0.0);
});

test('defaults to the current year and month when none are given', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['year'])->toBe((int) now()->year);
    expect($props['month'])->toBe((int) now()->month);
});

test('shows debt minimums pulled live from active debts', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Maya CC', 'minimum_payment' => 75, 'due_day' => 12]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $minimums = $response->viewData('page')['props']['debtMinimums'];

    expect($minimums)->toHaveCount(1);
    expect($minimums[0]['name'])->toBe('Maya CC');
    expect((float) $minimums[0]['minimum_payment'])->toBe(75.0);
    expect($minimums[0]['due_day'])->toBe(12);
});

test('debt minimums are ordered by due day, with no-due-day debts last', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'No due day', 'due_day' => null]);
    Debt::factory()->for($user)->create(['name' => 'Due 28th', 'due_day' => 28]);
    Debt::factory()->for($user)->create(['name' => 'Due 5th', 'due_day' => 5]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $names = collect($response->viewData('page')['props']['debtMinimums'])->pluck('name')->all();

    expect($names)->toBe(['Due 5th', 'Due 28th', 'No due day']);
});

test('debtMinimums reports the total paid against a debt within the requested month', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create(['name' => 'Car loan', 'balance' => 1000, 'minimum_payment' => 300, 'linked_account_id' => null]);

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id, 'amount' => 100, 'occurred_at' => '2026-08-10',
    ])->assertRedirect();
    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id, 'amount' => 50, 'occurred_at' => '2026-08-20',
    ])->assertRedirect();

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();
    $minimums = collect($response->viewData('page')['props']['debtMinimums'])->keyBy('id');

    expect((float) $minimums[$debt->id]['paid_this_period'])->toBe(150.0);
});

test('debtMinimums does not count a payment logged in a different month', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 300, 'linked_account_id' => null]);

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id, 'amount' => 300, 'occurred_at' => '2026-07-31',
    ])->assertRedirect();

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();
    $minimums = collect($response->viewData('page')['props']['debtMinimums'])->keyBy('id');

    expect((float) $minimums[$debt->id]['paid_this_period'])->toBe(0.0);
});

test('shows bill and envelope periods for the requested month', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Electricity', 'expected_amount' => 60]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 200]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['bills'])->toHaveCount(1);
    expect($props['bills'][0]['name'])->toBe('Electricity');
    expect((float) $props['bills'][0]['expected_amount'])->toBe(60.0);
    expect($props['envelopes'])->toHaveCount(1);
    expect($props['envelopes'][0]['name'])->toBe('Groceries');
    expect((float) $props['envelopes'][0]['target'])->toBe(200.0);
    expect((float) $props['envelopes'][0]['spent'])->toBe(0.0);
});

test('computes leftover as income minus minimums, bills, and envelope targets', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 3000, 'Paycheck', now()->setDate(2026, 8, 1));
    Debt::factory()->for($user)->create(['minimum_payment' => 200]);
    Bill::factory()->for($user)->create(['expected_amount' => 100]);
    Envelope::factory()->for($user)->create(['default_target' => 500]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect((float) $response->viewData('page')['props']['leftover'])->toBe(2200.0);
});

test('leftover goes negative when assignments exceed income', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['minimum_payment' => 5000]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect((float) $response->viewData('page')['props']['leftover'])->toBe(-5000.0);
});

test('an over-committed month reports more placed than came in', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 10000, 'Paycheck', now()->setDate(2026, 8, 1));
    Envelope::factory()->for($user)->create(['default_target' => 12000]);

    $summary = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk()
        ->viewData('page')['props']['summary'];

    expect($summary['leftover'])->toBe(-2000.0);
    expect($summary['placed'])->toBe(12000.0);
});

test('dealing across envelopes is refused while the hand is negative', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['minimum_payment' => 5000]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 8, 'target' => 100]);

    $this->actingAs($user)->post(route('budget.deal'), [
        'year' => 2026,
        'month' => 8,
        'allocations' => [['envelope_period_id' => $period->id, 'amount' => 50]],
    ])->assertSessionHasErrors();

    expect((float) $period->fresh()->target)->toBe(100.0);
});

test('an extra debt payment is refused while the hand is negative', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create(['balance' => 5000]);
    Debt::factory()->for($user)->create(['minimum_payment' => 5000, 'linked_account_id' => $card->id]);
    $cash = Account::factory()->for($user)->create(['balance' => 1000]);

    $this->actingAs($user)->post(route('budget.extra-payment.confirm'), [
        'amount' => 100,
        'year' => 2026,
        'month' => 8,
        'from_account_id' => $cash->id,
    ])->assertSessionHasErrors();
});

test('priorityDebt is the highest-priority debt that has a linked account', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    Debt::factory()->for($user)->create(['name' => 'Unlinked, high rate', 'monthly_rate' => 30, 'linked_account_id' => null]);
    $linkedAccount = Account::factory()->for($user)->liability()->create();
    $linked = Debt::factory()->for($user)->create(['name' => 'Linked, lower rate', 'monthly_rate' => 10, 'linked_account_id' => $linkedAccount->id]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();

    expect($response->viewData('page')['props']['priorityDebt']['id'])->toBe($linked->id);
});

test('priorityDebt is null when no debt has a linked account', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['linked_account_id' => null]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();

    expect($response->viewData('page')['props']['priorityDebt'])->toBeNull();
});

test('bill and envelope templates are listed regardless of active status', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Active bill', 'active' => true]);
    Bill::factory()->for($user)->create(['name' => 'Inactive bill', 'active' => false]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $templates = collect($response->viewData('page')['props']['billTemplates'])->pluck('name');

    expect($templates->all())->toContain('Active bill', 'Inactive bill');
});

test('stores a new bill for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/bills', [
        'name' => 'Internet', 'expected_amount' => 45, 'due_day' => 15, 'active' => true,
    ])->assertRedirect();

    $bill = Bill::where('user_id', $user->id)->firstOrFail();
    expect($bill->name)->toBe('Internet');
    expect((float) $bill->expected_amount)->toBe(45.0);
});

test('storing a bill requires a name and a positive expected amount', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/bills', [
        'expected_amount' => 0, 'active' => true,
    ])->assertSessionHasErrors(['name', 'expected_amount']);
});

test('updates an owned bill including deactivating it', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['name' => 'Old name', 'active' => true]);

    $this->actingAs($user)->patch("/budget/bills/{$bill->id}", [
        'name' => 'New name', 'expected_amount' => 90, 'due_day' => null, 'active' => false,
    ])->assertRedirect();

    $bill->refresh();
    expect($bill->name)->toBe('New name');
    expect($bill->active)->toBeFalse();
});

test('cannot update another user\'s bill', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $bill = Bill::factory()->for($owner)->create();

    $this->actingAs($intruder)->patch("/budget/bills/{$bill->id}", [
        'name' => 'Hacked', 'expected_amount' => 1, 'active' => true,
    ])->assertForbidden();
});

test('deletes an owned bill', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create();

    $this->actingAs($user)->delete("/budget/bills/{$bill->id}")->assertRedirect();

    expect(Bill::find($bill->id))->toBeNull();
});

test('cannot delete another user\'s bill', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $bill = Bill::factory()->for($owner)->create();

    $this->actingAs($intruder)->delete("/budget/bills/{$bill->id}")->assertForbidden();

    expect(Bill::find($bill->id))->not->toBeNull();
});

test('a deactivated bill stops getting new period rows but keeps old ones', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['active' => true]);
    $this->actingAs($user)->get('/budget?year=2026&month=7')->assertOk();

    $this->actingAs($user)->patch("/budget/bills/{$bill->id}", [
        'name' => $bill->name, 'expected_amount' => (float) $bill->expected_amount, 'due_day' => $bill->due_day, 'active' => false,
    ])->assertRedirect();

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect($response->viewData('page')['props']['bills'])->toBe([]);
    expect(BillPeriod::where('bill_id', $bill->id)->where('month', 7)->exists())->toBeTrue();
});

test('stores a new envelope for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/envelopes', [
        'name' => 'Groceries', 'icon' => 'shopping_cart', 'default_target' => 250, 'active' => true,
    ])->assertRedirect();

    $envelope = Envelope::where('user_id', $user->id)->firstOrFail();
    expect($envelope->name)->toBe('Groceries');
    expect((float) $envelope->default_target)->toBe(250.0);
});

test('storing an envelope requires a name and a positive target', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/envelopes', [
        'default_target' => 0, 'active' => true,
    ])->assertSessionHasErrors(['name', 'default_target']);
});

test('updates an owned envelope', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create(['name' => 'Old name']);

    $this->actingAs($user)->patch("/budget/envelopes/{$envelope->id}", [
        'name' => 'New name', 'icon' => null, 'default_target' => 300, 'active' => true,
    ])->assertRedirect();

    expect($envelope->fresh()->name)->toBe('New name');
});

test('cannot update another user\'s envelope', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $envelope = Envelope::factory()->for($owner)->create();

    $this->actingAs($intruder)->patch("/budget/envelopes/{$envelope->id}", [
        'name' => 'Hacked', 'default_target' => 1, 'active' => true,
    ])->assertForbidden();
});

test('raising an envelope target re-prices the current month\'s period', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create(['name' => 'Dog Food', 'default_target' => 1]);

    // Viewing the month materialises the period snapshot at the old target.
    $this->actingAs($user)->get('/budget')->assertOk();
    expect((float) EnvelopePeriod::where('envelope_id', $envelope->id)->sole()->target)->toBe(1.0);

    $this->actingAs($user)->patch(route('budget.envelopes.update', $envelope), [
        'name' => 'Dog Food', 'icon' => null, 'default_target' => 13000, 'active' => true,
    ])->assertRedirect();

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect((float) $props['envelopes'][0]['target'])->toBe(13000.0);
});

test('re-pricing an envelope does not rewrite what past months were budgeted', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 1]);
    $lastMonth = now()->subMonthNoOverflow();
    $period = EnvelopePeriod::factory()->for($envelope)->create([
        'year' => (int) $lastMonth->year,
        'month' => (int) $lastMonth->month,
        'target' => 1,
    ]);

    $this->actingAs($user)->patch(route('budget.envelopes.update', $envelope), [
        'name' => $envelope->name, 'icon' => null, 'default_target' => 13000, 'active' => true,
    ])->assertRedirect();

    expect((float) $period->fresh()->target)->toBe(1.0);
});

test('deletes an owned envelope', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create();

    $this->actingAs($user)->delete("/budget/envelopes/{$envelope->id}")->assertRedirect();

    expect(Envelope::find($envelope->id))->toBeNull();
});

test('cannot delete another user\'s envelope', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $envelope = Envelope::factory()->for($owner)->create();

    $this->actingAs($intruder)->delete("/budget/envelopes/{$envelope->id}")->assertForbidden();

    expect(Envelope::find($envelope->id))->not->toBeNull();
});

test('marks a bill period paid with the actual amount', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $bill = Bill::factory()->for($user)->create();
    $period = BillPeriod::factory()->for($bill)->create(['actual_amount' => null, 'paid_at' => null]);

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 62.5, 'from_account_id' => $checking->id,
    ])->assertRedirect();

    $period->refresh();
    expect((float) $period->actual_amount)->toBe(62.5);
    expect($period->paid_at)->not->toBeNull();
});

test('marking a bill paid records an expense against the selected account', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $bill = Bill::factory()->for($user)->create(['name' => 'Electricity']);
    $period = BillPeriod::factory()->for($bill)->create(['actual_amount' => null, 'paid_at' => null]);

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 62.5, 'from_account_id' => $checking->id,
    ])->assertRedirect();

    $checking->refresh();
    expect((float) $checking->balance)->toBe(937.5);

    $transaction = Transaction::where('account_id', $checking->id)->where('type', 'expense')->firstOrFail();
    expect((float) $transaction->amount)->toBe(62.5);
    expect($transaction->description)->toBe('Bill: Electricity');
});

test('marking a bill paid requires a positive actual amount and a source account', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    $bill = Bill::factory()->for($user)->create();
    $period = BillPeriod::factory()->for($bill)->create();

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 0, 'from_account_id' => $checking->id,
    ])->assertSessionHasErrors('actual_amount');

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 10,
    ])->assertSessionHasErrors('from_account_id');
});

test('marking a bill paid from someone else\'s account is rejected', function () {
    $user = User::factory()->create();
    $theirs = Account::factory()->for(User::factory())->liability()->create();
    $bill = Bill::factory()->for($user)->create();
    $period = BillPeriod::factory()->for($bill)->create();

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 10, 'from_account_id' => $theirs->id,
    ])->assertSessionHasErrors('from_account_id');
});

test('cannot mark another user\'s bill period paid', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $checking = Account::factory()->for($intruder)->create();
    $bill = Bill::factory()->for($owner)->create();
    $period = BillPeriod::factory()->for($bill)->create();

    $this->actingAs($intruder)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 10, 'from_account_id' => $checking->id,
    ])->assertForbidden();
});

test('confirming a sweep transfers the freed amount to the priority linked debt', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    $debt = Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'monthly_rate' => 20]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = EnvelopePeriod::factory()->for($envelope)->create(['target' => 100, 'sweep_offered_at' => now()]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $checking->id,
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(900.0);
    expect((float) $card->fresh()->balance)->toBe(400.0);
    expect($period->fresh()->sweep_accepted)->toBeTrue();
});

test('confirming a sweep twice rejects the second attempt and only transfers once', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'monthly_rate' => 20]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = EnvelopePeriod::factory()->for($envelope)->create(['target' => 100, 'sweep_offered_at' => now()]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $checking->id,
    ])->assertRedirect();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $checking->id,
    ])->assertSessionHasErrors();

    expect((float) $checking->fresh()->balance)->toBe(900.0);
    expect((float) $card->fresh()->balance)->toBe(400.0);
});

test('confirming a sweep with a liability account as the source is rejected', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $card = Account::factory()->for($user)->liability()->create();
    $debt = Debt::factory()->for($user)->create(['linked_account_id' => $card->id]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = EnvelopePeriod::factory()->for($envelope)->create(['target' => 100, 'sweep_offered_at' => now()]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $card->id,
    ])->assertSessionHasErrors('from_account_id');
});

test('confirming a sweep with no linked debt anywhere is rejected', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create();
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = EnvelopePeriod::factory()->for($envelope)->create(['target' => 100, 'sweep_offered_at' => now()]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $checking->id,
    ])->assertSessionHasErrors();
});

test('declining a sweep just records the decision with no transfer', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create();
    $period = EnvelopePeriod::factory()->for($envelope)->create();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/decline")->assertRedirect();

    expect($period->fresh()->sweep_accepted)->toBeFalse();
    expect(Transaction::count())->toBe(0);
});

test('confirming the extra payment transfers the leftover to the priority linked debt', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    (new LedgerService)->recordIncome($checking, 2000, 'Paycheck', now()->setDate(2026, 8, 1));
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'minimum_payment' => 200]);

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => 2026, 'month' => 8,
    ])->assertRedirect();

    // leftover = income (2000) - minimumsTotal (200) - bills (0) - envelopes (0) = 1800
    expect((float) $card->fresh()->balance)->toBe(500.0 - 1800.0);
});

test('confirming the extra payment twice for the same period rejects the second attempt and only transfers once', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    (new LedgerService)->recordIncome($checking, 2000, 'Paycheck', now()->setDate(2026, 8, 1));
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'minimum_payment' => 200]);

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => 2026, 'month' => 8,
    ])->assertRedirect();

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => 2026, 'month' => 8,
    ])->assertSessionHasErrors();

    expect((float) $card->fresh()->balance)->toBe(500.0 - 1800.0);
});

test('confirming the extra payment twice for a past month rejects the second attempt and only transfers once', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $pastMonth = now()->subMonthNoOverflow()->startOfMonth();
    $year = (int) $pastMonth->year;
    $month = (int) $pastMonth->month;

    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    (new LedgerService)->recordIncome($checking, 2000, 'Paycheck', $pastMonth->copy()->day(1));
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'minimum_payment' => 200]);

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => $year, 'month' => $month,
    ])->assertRedirect();

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => $year, 'month' => $month,
    ])->assertSessionHasErrors();

    expect((float) $card->fresh()->balance)->toBe(500.0 - 1800.0);
});

test('confirming the extra payment with nothing leftover is rejected', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create();
    $card = Account::factory()->for($user)->liability()->create();
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'minimum_payment' => 200]);

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => 2026, 'month' => 8,
    ])->assertSessionHasErrors();
});

test('a paid-off debt is excluded from minimums and is never the priority target', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $paidOffAccount = Account::factory()->for($user)->liability()->create(['balance' => 0]);
    Debt::factory()->for($user)->create(['name' => 'Paid off', 'balance' => 0, 'linked_account_id' => $paidOffAccount->id, 'monthly_rate' => 30]);
    $liveAccount = Account::factory()->for($user)->liability()->create(['balance' => 800]);
    $live = Debt::factory()->for($user)->create(['name' => 'Still owed', 'balance' => 800, 'linked_account_id' => $liveAccount->id, 'monthly_rate' => 10]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $props = $response->viewData('page')['props'];

    expect(collect($props['debtMinimums'])->pluck('name')->all())->toBe(['Still owed']);
    expect($props['priorityDebt']['id'])->toBe($live->id);
});

test('income dated the last day of the month counts toward that period leftover', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 500, 'Late paycheck', Carbon::create(2026, 8, 31));

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect((float) $response->viewData('page')['props']['leftover'])->toBe(500.0);
});

test('cannot confirm another user\'s sweep', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $envelope = Envelope::factory()->for($owner)->create();
    $period = EnvelopePeriod::factory()->for($envelope)->create(['sweep_offered_at' => now()]);
    $account = Account::factory()->for($intruder)->create();

    $this->actingAs($intruder)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $account->id,
    ])->assertForbidden();
});

test('cannot decline another user\'s sweep', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $envelope = Envelope::factory()->for($owner)->create();
    $period = EnvelopePeriod::factory()->for($envelope)->create();

    $this->actingAs($intruder)->post("/budget/envelope-periods/{$period->id}/sweep/decline")->assertForbidden();
});

test('cannot confirm a sweep for an envelope that was never offered one', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create();
    $card = Account::factory()->for($user)->liability()->create();
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = EnvelopePeriod::factory()->for($envelope)->create(['target' => 100, 'sweep_offered_at' => null]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $checking->id,
    ])->assertSessionHasErrors();
});

test('rejects an out-of-range month on the budget page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/budget?year=2026&month=13')->assertSessionHasErrors('month');
});

test('logging a payment for a linked debt transfers to its linked account', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    $debt = Debt::factory()->for($user)->create(['name' => 'Maya CC', 'balance' => 500, 'linked_account_id' => $card->id, 'minimum_payment' => 75]);

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id,
        'amount' => 75,
        'occurred_at' => '2026-08-15',
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(925.0);
    expect((float) $card->fresh()->balance)->toBe(425.0);
    expect((float) $debt->fresh()->balance)->toBe(500.0);

    $transaction = Transaction::firstOrFail();
    expect($transaction->type)->toBe('transfer');
    expect($transaction->from_account_id)->toBe($checking->id);
    expect($transaction->to_account_id)->toBe($card->id);
    expect($transaction->debt_id)->toBe($debt->id);
    expect((float) $transaction->amount)->toBe(75.0);
    expect($transaction->occurred_at->toDateString())->toBe('2026-08-15');
});

test('logging a payment for an unlinked debt records an expense and reduces its balance', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create(['name' => 'Car loan', 'balance' => 300, 'linked_account_id' => null, 'minimum_payment' => 300]);

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id,
        'amount' => 300,
        'occurred_at' => '2026-08-15',
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(700.0);
    expect((float) $debt->fresh()->balance)->toBe(0.0);

    $transaction = Transaction::firstOrFail();
    expect($transaction->type)->toBe('expense');
    expect($transaction->account_id)->toBe($checking->id);
    expect($transaction->debt_id)->toBe($debt->id);
    expect((float) $transaction->amount)->toBe(300.0);
});

test('an unlinked debt payment never drops the balance below zero', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 100, 'linked_account_id' => null]);

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id,
        'amount' => 150,
        'occurred_at' => '2026-08-15',
    ])->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(0.0);
});

test('logging a debt-minimum payment requires a positive amount and a valid date', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create();

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id,
        'amount' => 0,
        'occurred_at' => 'not-a-date',
    ])->assertSessionHasErrors(['amount', 'occurred_at']);
});

test('logging a debt-minimum payment with a liability account as the source is rejected', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    $debt = Debt::factory()->for($user)->create();

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $card->id,
        'amount' => 50,
        'occurred_at' => '2026-08-15',
    ])->assertSessionHasErrors('from_account_id');
});

test('cannot log a payment for another user\'s debt', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $debt = Debt::factory()->for($owner)->create();
    $account = Account::factory()->for($intruder)->create();

    $this->actingAs($intruder)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $account->id,
        'amount' => 50,
        'occurred_at' => '2026-08-15',
    ])->assertForbidden();
});

test('exposes a summary of income, debt minimums, bills, envelopes, and leftover', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['minimum_payment' => 100, 'balance' => 500]);
    Bill::factory()->for($user)->create(['expected_amount' => 50, 'active' => true]);
    Envelope::factory()->for($user)->create(['default_target' => 30, 'active' => true]);
    $account = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($account, 300, 'Salary', now());

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $summary = $response->viewData('page')['props']['summary'];

    expect($summary['income'])->toBe(300.0);
    expect($summary['debtMinimums'])->toBe(100.0);
    expect($summary['bills'])->toBe(50.0);
    expect($summary['envelopes'])->toBe(30.0);
    expect($summary['leftover'])->toBe(120.0);
});

test('flags an unpaid bill past its due day as overdue', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        Bill::factory()->for($user)->create(['name' => 'Electric', 'due_day' => 10, 'active' => true]);

        $response = $this->actingAs($user)->get('/budget')->assertOk();
        $notifications = $response->viewData('page')['props']['notifications'];

        expect($notifications)->toContain(['type' => 'bill_overdue', 'severity' => 'critical', 'subject' => 'Electric', 'days' => 5]);
    });
});

test('flags an unpaid bill due within the next 3 days as due soon', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        Bill::factory()->for($user)->create(['name' => 'Internet', 'due_day' => 17, 'active' => true]);

        $response = $this->actingAs($user)->get('/budget')->assertOk();
        $notifications = $response->viewData('page')['props']['notifications'];

        expect($notifications)->toContain(['type' => 'bill_due_soon', 'severity' => 'warning', 'subject' => 'Internet', 'days' => 2]);
    });
});

test('does not flag a bill that is already paid or not due soon', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        $paidBill = Bill::factory()->for($user)->create(['name' => 'Paid already', 'due_day' => 5, 'active' => true]);
        Bill::factory()->for($user)->create(['name' => 'Not due yet', 'due_day' => 28, 'active' => true]);

        $this->actingAs($user)->get('/budget');
        $billPeriod = BillPeriod::where('bill_id', $paidBill->id)->firstOrFail();
        $billPeriod->update(['paid_at' => now(), 'actual_amount' => $paidBill->expected_amount]);

        $response = $this->actingAs($user)->get('/budget')->assertOk();
        $subjects = collect($response->viewData('page')['props']['notifications'])->pluck('subject');

        expect($subjects->all())->toBe([]);
    });
});

test('flags an envelope that has been spent past its target', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        $envelope = Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 100, 'active' => true]);
        $account = Account::factory()->for($user)->create(['balance' => 1000]);

        $this->actingAs($user)->get('/budget');
        (new LedgerService)->recordExpense($account, 150, 'Overspent', now(), $envelope->id);

        $response = $this->actingAs($user)->get('/budget')->assertOk();
        $notifications = $response->viewData('page')['props']['notifications'];

        expect($notifications)->toContain(['type' => 'envelope_over_budget', 'severity' => 'warning', 'subject' => 'Groceries', 'amount' => 50.0]);
    });
});

test('flags an unpaid debt minimum past its due day as overdue, but not once paid', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        $debt = Debt::factory()->for($user)->create([
            'name' => 'Visa', 'due_day' => 10, 'minimum_payment' => 100, 'balance' => 500,
            'created_at' => Carbon::create(2026, 5, 1),
        ]);

        $overdueResponse = $this->actingAs($user)->get('/budget')->assertOk();
        expect($overdueResponse->viewData('page')['props']['notifications'])
            ->toContain(['type' => 'debt_minimum_overdue', 'severity' => 'critical', 'subject' => 'Visa', 'days' => 5]);

        $account = Account::factory()->for($user)->create(['balance' => 1000]);
        $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
            'from_account_id' => $account->id,
            'amount' => 100,
            'occurred_at' => now()->toDateString(),
        ])->assertRedirect();

        $paidResponse = $this->actingAs($user)->get('/budget')->assertOk();
        $subjects = collect($paidResponse->viewData('page')['props']['notifications'])->pluck('subject');

        expect($subjects->all())->not->toContain('Visa');
    });
});

test('does not flag a newly added debt as overdue until its first due day passes next month', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        Debt::factory()->for($user)->create(['name' => 'New Card', 'due_day' => 10, 'minimum_payment' => 100, 'balance' => 500]);

        $response = $this->actingAs($user)->get('/budget')->assertOk();
        $subjects = collect($response->viewData('page')['props']['notifications'])->pluck('subject');

        expect($subjects->all())->not->toContain('New Card');
    });
});

test('notifications are empty when viewing a period other than the current month', function () {
    $this->travelTo(Carbon::create(2026, 6, 15), function () {
        $user = User::factory()->create();
        Bill::factory()->for($user)->create(['due_day' => 1, 'active' => true]);

        $response = $this->actingAs($user)->get('/budget?year=2026&month=5')->assertOk();

        expect($response->viewData('page')['props']['notifications'])->toBe([]);
    });
});

// ── Redesign: safe to spend, the hand, sealed money ──────────────────────

test('safe to spend is what is left across envelopes, spread over the days still to come', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 20000]);
    $envelope = Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 3000]);
    (new LedgerService)->recordExpense($checking, 1000, 'Market', now(), $envelope->id);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];
    $daysRemaining = $props['safeToSpend']['daysRemaining'];

    expect($props['safeToSpend']['unspent'])->toBe(2000.0);
    expect($props['safeToSpend']['perDay'])->toBe(round(2000.0 / $daysRemaining, 2));
    expect($daysRemaining)->toBeGreaterThan(0);
});

test('an overspent envelope contributes nothing to safe to spend rather than a negative', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 20000]);
    $over = Envelope::factory()->for($user)->create(['name' => 'Eating out', 'default_target' => 500]);
    Envelope::factory()->for($user)->create(['name' => 'Transport', 'default_target' => 1000]);
    (new LedgerService)->recordExpense($checking, 900, 'Dinner', now(), $over->id);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['safeToSpend']['unspent'])->toBe(1000.0);
});

test('envelopes report what is left and whether it is keeping up with the calendar', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 15));

    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    $over = Envelope::factory()->for($user)->create(['name' => 'Eating out', 'default_target' => 500]);
    $untouched = Envelope::factory()->for($user)->create(['name' => 'Household', 'default_target' => 1000]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $over->id);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];
    $rows = collect($props['envelopes'])->keyBy('name');

    expect($rows['Eating out']['remaining'])->toBe(-120.0);
    expect($rows['Eating out']['status'])->toBe('over');
    expect($rows['Household']['remaining'])->toBe(1000.0);
    expect($rows['Household']['status'])->toBe('spare');
    expect($rows['Household']['envelope_id'])->toBe($untouched->id);

    Carbon::setTestNow();
});

test('sealed money counts unpaid bills and whatever is left of each debt minimum', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Rent', 'expected_amount' => 1200]);
    Debt::factory()->for($user)->create(['minimum_payment' => 300, 'balance' => 5000]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['sealedTotal'])->toBe(1500.0);
});

test('the planned debt payment is the minimum plus the standing extra', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 700]);
    $card = Account::factory()->for($user)->liability()->create(['balance' => 5000]);
    $debt = Debt::factory()->for($user)->create(['name' => 'BPI Card', 'minimum_payment' => 300, 'due_day' => 18, 'linked_account_id' => $card->id]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['plannedDebtPayment']['id'])->toBe($debt->id);
    expect($props['plannedDebtPayment']['amount'])->toBe(1000.0);
    expect($props['plannedDebtPayment']['minimum'])->toBe(300.0);
    expect($props['plannedDebtPayment']['due_day'])->toBe(18);
});

test('money already sent at a debt beyond its minimum comes out of the hand', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 3000, 'Paycheck', now()->startOfMonth());
    $debt = Debt::factory()->for($user)->create(['minimum_payment' => 200, 'balance' => 5000, 'linked_account_id' => null]);

    $before = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];
    expect((float) $before['leftover'])->toBe(2800.0);

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id, 'amount' => 900, 'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    $after = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect((float) $after['leftover'])->toBe(2100.0);
});

// ── Redesign: dealing the hand across envelopes ──────────────────────────

test('dealing the hand across envelopes raises each target for that month', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 3000, 'Paycheck', now()->startOfMonth());
    $groceries = Envelope::factory()->for($user)->create(['default_target' => 500]);
    $pets = Envelope::factory()->for($user)->create(['default_target' => 200]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $groceriesPeriod = EnvelopePeriod::where('envelope_id', $groceries->id)->firstOrFail();
    $petsPeriod = EnvelopePeriod::where('envelope_id', $pets->id)->firstOrFail();

    $this->actingAs($user)->post('/budget/deal', [
        'year' => now()->year,
        'month' => now()->month,
        'allocations' => [
            ['envelope_period_id' => $groceriesPeriod->id, 'amount' => 400],
            ['envelope_period_id' => $petsPeriod->id, 'amount' => 100],
        ],
    ])->assertRedirect();

    expect((float) $groceriesPeriod->fresh()->target)->toBe(900.0);
    expect((float) $petsPeriod->fresh()->target)->toBe(300.0);
});

test('dealing more than is in hand is rejected', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 500, 'Paycheck', now()->startOfMonth());
    $groceries = Envelope::factory()->for($user)->create(['default_target' => 100]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = EnvelopePeriod::where('envelope_id', $groceries->id)->firstOrFail();

    $this->actingAs($user)->post('/budget/deal', [
        'year' => now()->year,
        'month' => now()->month,
        'allocations' => [['envelope_period_id' => $period->id, 'amount' => 5000]],
    ])->assertSessionHasErrors();

    expect((float) $period->fresh()->target)->toBe(100.0);
});

test('cannot deal into another user\'s envelope', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 3000, 'Paycheck', now()->startOfMonth());
    $theirs = EnvelopePeriod::factory()->for(Envelope::factory()->for(User::factory()))->create([
        'year' => now()->year, 'month' => now()->month, 'target' => 100,
    ]);

    $this->actingAs($user)->post('/budget/deal', [
        'year' => now()->year,
        'month' => now()->month,
        'allocations' => [['envelope_period_id' => $theirs->id, 'amount' => 100]],
    ])->assertForbidden();
});

// ── Redesign: covering an overspend ──────────────────────────────────────

test('covering an overspend from the hand raises only the overspent envelope', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 3000, 'Paycheck', now()->startOfMonth());
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/cover", [
        'source' => 'hand', 'amount' => 120,
    ])->assertRedirect();

    expect((float) $period->fresh()->target)->toBe(620.0);
});

test('covering more than what is in hand transfers only what is available', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 550, 'Paycheck', now()->startOfMonth());
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/cover", [
        'source' => 'hand', 'amount' => 120,
    ])->assertRedirect();

    expect((float) $period->fresh()->target)->toBe(550.0);
});

test('covering from the hand when there is nothing in hand is rejected', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 500, 'Paycheck', now()->startOfMonth());
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/cover", [
        'source' => 'hand', 'amount' => 120,
    ])->assertSessionHasErrors('error');

    expect((float) $period->fresh()->target)->toBe(500.0);
});

test('covering an overspend from another envelope moves the target across', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    $household = Envelope::factory()->for($user)->create(['default_target' => 1000]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $overPeriod = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();
    $sparePeriod = EnvelopePeriod::where('envelope_id', $household->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$overPeriod->id}/cover", [
        'source' => 'envelope', 'amount' => 120, 'from_envelope_period_id' => $sparePeriod->id,
    ])->assertRedirect();

    expect((float) $overPeriod->fresh()->target)->toBe(620.0);
    expect((float) $sparePeriod->fresh()->target)->toBe(880.0);
});

test('covering from an envelope that lacks the spare transfers only what is available', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    $thin = Envelope::factory()->for($user)->create(['default_target' => 50]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $overPeriod = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();
    $thinPeriod = EnvelopePeriod::where('envelope_id', $thin->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$overPeriod->id}/cover", [
        'source' => 'envelope', 'amount' => 120, 'from_envelope_period_id' => $thinPeriod->id,
    ])->assertRedirect();

    expect((float) $overPeriod->fresh()->target)->toBe(550.0);
    expect((float) $thinPeriod->fresh()->target)->toBe(0.0);
});

test('covering from an envelope with nothing spare is rejected', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    $empty = Envelope::factory()->for($user)->create(['default_target' => 50]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);
    (new LedgerService)->recordExpense($checking, 50, 'Groceries', now(), $empty->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $overPeriod = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();
    $emptyPeriod = EnvelopePeriod::where('envelope_id', $empty->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$overPeriod->id}/cover", [
        'source' => 'envelope', 'amount' => 120, 'from_envelope_period_id' => $emptyPeriod->id,
    ])->assertSessionHasErrors('error');

    expect((float) $overPeriod->fresh()->target)->toBe(500.0);
    expect((float) $emptyPeriod->fresh()->target)->toBe(50.0);
});

test('covering from the debt payment lowers the standing monthly extra', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 900]);
    $checking = Account::factory()->for($user)->create();
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/cover", [
        'source' => 'debt', 'amount' => 120,
    ])->assertRedirect();

    expect((float) $period->fresh()->target)->toBe(620.0);
    expect((float) $user->debtFocusSettings()->first()->extra_monthly)->toBe(780.0);
});

test('covering more than the standing extra from the debt payment transfers only what is available', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 50]);
    $checking = Account::factory()->for($user)->create();
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/cover", [
        'source' => 'debt', 'amount' => 120,
    ])->assertRedirect();

    expect((float) $period->fresh()->target)->toBe(550.0);
    expect((float) $user->debtFocusSettings()->first()->extra_monthly)->toBe(0.0);
});

test('covering from the debt payment when there is no extra is rejected', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 0]);
    $checking = Account::factory()->for($user)->create();
    $eatingOut = Envelope::factory()->for($user)->create(['default_target' => 500]);
    (new LedgerService)->recordExpense($checking, 620, 'Dinner', now(), $eatingOut->id);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = EnvelopePeriod::where('envelope_id', $eatingOut->id)->firstOrFail();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/cover", [
        'source' => 'debt', 'amount' => 120,
    ])->assertSessionHasErrors();

    expect((float) $user->debtFocusSettings()->first()->extra_monthly)->toBe(0.0);
});

test('cannot cover another user\'s envelope', function () {
    $user = User::factory()->create();
    $theirs = EnvelopePeriod::factory()->for(Envelope::factory()->for(User::factory()))->create([
        'year' => now()->year, 'month' => now()->month, 'target' => 100,
    ]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$theirs->id}/cover", [
        'source' => 'hand', 'amount' => 10,
    ])->assertForbidden();
});

// ── Redesign: marking several bills paid at once ─────────────────────────

test('marking several bills paid records one expense each and stamps them paid', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 10000]);
    Bill::factory()->for($user)->create(['name' => 'Meralco', 'expected_amount' => 3850]);
    Bill::factory()->for($user)->create(['name' => 'Maynilad', 'expected_amount' => 780]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $periods = BillPeriod::with('bill')->get()->keyBy(fn (BillPeriod $period) => $period->bill->name);

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $checking->id,
        'payments' => [
            ['bill_period_id' => $periods['Meralco']->id, 'actual_amount' => 4120],
            ['bill_period_id' => $periods['Maynilad']->id, 'actual_amount' => 780],
        ],
    ])->assertRedirect();

    expect((float) $periods['Meralco']->fresh()->actual_amount)->toBe(4120.0);
    expect($periods['Meralco']->fresh()->paid_at)->not->toBeNull();
    expect((float) $periods['Maynilad']->fresh()->actual_amount)->toBe(780.0);
    expect((float) $checking->fresh()->balance)->toBe(10000.0 - 4900.0);
    expect(Transaction::where('type', 'expense')->count())->toBe(2);
});

test('a bill already marked paid cannot be paid again in a batch, and nothing in that batch lands', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 10000]);
    Bill::factory()->for($user)->create(['name' => 'Meralco', 'expected_amount' => 3850]);
    Bill::factory()->for($user)->create(['name' => 'Maynilad', 'expected_amount' => 780]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $periods = BillPeriod::with('bill')->get()->keyBy(fn (BillPeriod $period) => $period->bill->name);
    $periods['Meralco']->update(['paid_at' => now(), 'actual_amount' => 3850]);

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $checking->id,
        'payments' => [
            ['bill_period_id' => $periods['Maynilad']->id, 'actual_amount' => 780],
            ['bill_period_id' => $periods['Meralco']->id, 'actual_amount' => 4120],
        ],
    ])->assertSessionHasErrors();

    expect($periods['Maynilad']->fresh()->paid_at)->toBeNull();
    expect((float) $checking->fresh()->balance)->toBe(10000.0);
});

test('cannot batch-pay another user\'s bill', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 10000]);
    $theirs = BillPeriod::factory()->for(Bill::factory()->for(User::factory()))->create([
        'year' => now()->year, 'month' => now()->month, 'expected_amount' => 100,
    ]);

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $checking->id,
        'payments' => [['bill_period_id' => $theirs->id, 'actual_amount' => 100]],
    ])->assertForbidden();
});

// ── Redesign: sending a chosen slice of the hand at the debt ─────────────

test('the extra payment can send a chosen amount rather than the whole hand', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create(['balance' => 5000]);
    (new LedgerService)->recordIncome($checking, 2000, 'Paycheck', now()->setDate(2026, 8, 1));
    $card = Account::factory()->for($user)->liability()->create(['balance' => 5000]);
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'minimum_payment' => 200]);

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => 2026, 'month' => 8, 'amount' => 500,
    ])->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(4500.0);
});

test('the extra payment cannot send more than is in hand', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create(['balance' => 50000]);
    (new LedgerService)->recordIncome($checking, 2000, 'Paycheck', now()->setDate(2026, 8, 1));
    $card = Account::factory()->for($user)->liability()->create(['balance' => 5000]);
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'minimum_payment' => 200]);

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => 2026, 'month' => 8, 'amount' => 9000,
    ])->assertSessionHasErrors();

    expect((float) $card->fresh()->balance)->toBe(5000.0);
});

// ── Redesign: the move suggestion ────────────────────────────────────────

test('an envelope with spare is offered against the one running tightest', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 15));

    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    $tight = Envelope::factory()->for($user)->create(['name' => 'Pets', 'default_target' => 1000]);
    $spare = Envelope::factory()->for($user)->create(['name' => 'Household', 'default_target' => 3000]);
    (new LedgerService)->recordExpense($checking, 990, 'Vet', now(), $tight->id);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['moveSuggestion']['from_name'])->toBe('Household');
    expect($props['moveSuggestion']['to_name'])->toBe('Pets');
    expect($props['moveSuggestion']['spare'])->toBe(3000.0);
    expect($props['moveSuggestion']['from_id'])->toBe(EnvelopePeriod::where('envelope_id', $spare->id)->first()->id);

    Carbon::setTestNow();
});

test('no move is suggested when nothing is running tight', function () {
    Carbon::setTestNow(Carbon::create(2026, 9, 15));

    $user = User::factory()->create();
    Envelope::factory()->for($user)->create(['name' => 'Household', 'default_target' => 3000]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['moveSuggestion'])->toBeNull();

    Carbon::setTestNow();
});

test('a user with nothing set up gets a zeroed but well-formed budget', function () {
    $user = User::factory()->create();

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['envelopes'])->toBe([]);
    expect($props['bills'])->toBe([]);
    expect((float) $props['leftover'])->toBe(0.0);
    expect($props['safeToSpend']['unspent'])->toBe(0.0);
    expect($props['safeToSpend']['perDay'])->toBe(0.0);
    expect($props['safeToSpend']['daysRemaining'])->toBeGreaterThan(0);
    expect($props['sealedTotal'])->toBe(0.0);
    expect($props['plannedDebtPayment'])->toBeNull();
    expect($props['moveSuggestion'])->toBeNull();
    expect($props['priorityDebt'])->toBeNull();
    expect($props['summary']['income'])->toBe(0.0);
    expect($props['summary']['placed'])->toBe(0.0);
});

// ── 13f: recurring bills ─────────────────────────────────────────────────

test('a monthly bill lands in every month', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Rent', 'frequency' => 'monthly', 'expected_amount' => 12000]);

    foreach ([1, 2, 3] as $month) {
        $props = $this->actingAs($user)->get("/budget?year=2026&month={$month}")->assertOk()->viewData('page')['props'];

        expect(collect($props['bills'])->pluck('name')->all())->toBe(['Rent']);
    }
});

test('a quarterly bill only lands in the months its cycle reaches', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create([
        'name' => 'Insurance', 'frequency' => 'quarterly', 'anchor_month' => 2, 'expected_amount' => 4500,
    ]);

    $lands = [];

    foreach (range(1, 12) as $month) {
        $props = $this->actingAs($user)->get("/budget?year=2026&month={$month}")->assertOk()->viewData('page')['props'];

        if (collect($props['bills'])->isNotEmpty()) {
            $lands[] = $month;
        }
    }

    expect($lands)->toBe([2, 5, 8, 11]);
});

test('a yearly bill lands only in its anchor month', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create([
        'name' => 'Domain renewal', 'frequency' => 'yearly', 'anchor_month' => 7, 'expected_amount' => 900,
    ]);

    $july = $this->actingAs($user)->get('/budget?year=2026&month=7')->assertOk()->viewData('page')['props'];
    $august = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk()->viewData('page')['props'];

    expect(collect($july['bills'])->pluck('name')->all())->toBe(['Domain renewal']);
    expect($august['bills'])->toBe([]);
});

test('a quarterly cycle keeps its rhythm across a year boundary', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create([
        'name' => 'Insurance', 'frequency' => 'quarterly', 'anchor_month' => 11, 'expected_amount' => 4500,
    ]);

    $november = $this->actingAs($user)->get('/budget?year=2026&month=11')->assertOk()->viewData('page')['props'];
    $february = $this->actingAs($user)->get('/budget?year=2027&month=2')->assertOk()->viewData('page')['props'];
    $december = $this->actingAs($user)->get('/budget?year=2026&month=12')->assertOk()->viewData('page')['props'];

    expect(collect($november['bills'])->pluck('name')->all())->toBe(['Insurance']);
    expect(collect($february['bills'])->pluck('name')->all())->toBe(['Insurance']);
    expect($december['bills'])->toBe([]);
});

test('a bill whose amount varies sets its envelope from what it recently cost', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create([
        'name' => 'Meralco', 'frequency' => 'monthly', 'amount_varies' => true, 'expected_amount' => 3000,
    ]);

    BillPeriod::factory()->for($bill)->create(['year' => 2026, 'month' => 5, 'expected_amount' => 3000, 'actual_amount' => 3600]);
    BillPeriod::factory()->for($bill)->create(['year' => 2026, 'month' => 6, 'expected_amount' => 3000, 'actual_amount' => 4200]);
    BillPeriod::factory()->for($bill)->create(['year' => 2026, 'month' => 7, 'expected_amount' => 3000, 'actual_amount' => 3900]);

    $props = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk()->viewData('page')['props'];

    expect((float) $props['bills'][0]['expected_amount'])->toBe(3900.0);
});

test('a varying bill falls back to its stated amount until there is history', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['amount_varies' => true, 'expected_amount' => 3000, 'frequency' => 'monthly']);

    $props = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk()->viewData('page')['props'];

    expect((float) $props['bills'][0]['expected_amount'])->toBe(3000.0);
});

test('a varying bill ignores months after the one being budgeted', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['amount_varies' => true, 'expected_amount' => 3000, 'frequency' => 'monthly']);

    BillPeriod::factory()->for($bill)->create(['year' => 2026, 'month' => 5, 'expected_amount' => 3000, 'actual_amount' => 3600]);
    BillPeriod::factory()->for($bill)->create(['year' => 2026, 'month' => 9, 'expected_amount' => 3000, 'actual_amount' => 9000]);

    $props = $this->actingAs($user)->get('/budget?year=2026&month=6')->assertOk()->viewData('page')['props'];

    expect((float) $props['bills'][0]['expected_amount'])->toBe(3600.0);
});

test('a bill can be created with a recurrence and a varying amount', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/bills', [
        'name' => 'Petron Gasul',
        'expected_amount' => 1050,
        'frequency' => 'quarterly',
        'anchor_month' => 9,
        'amount_varies' => true,
        'due_day' => 9,
        'active' => true,
    ])->assertRedirect();

    $bill = Bill::where('user_id', $user->id)->firstOrFail();

    expect($bill->frequency)->toBe('quarterly');
    expect($bill->anchor_month)->toBe(9);
    expect($bill->amount_varies)->toBeTrue();
    expect($bill->due_day)->toBe(9);
});

test('an unrecognised frequency is rejected', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/bills', [
        'name' => 'Odd one', 'expected_amount' => 100, 'frequency' => 'fortnightly', 'active' => true,
    ])->assertSessionHasErrors('frequency');
});

test('a bill created without recurrence details is a plain monthly bill', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/bills', [
        'name' => 'Rent', 'expected_amount' => 12000, 'due_day' => 25, 'active' => true,
    ])->assertRedirect();

    $bill = Bill::where('user_id', $user->id)->firstOrFail();

    expect($bill->frequency)->toBe('monthly');
    expect($bill->amount_varies)->toBeFalse();
});

test('bill templates carry their recurrence to the page', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create([
        'name' => 'Insurance', 'frequency' => 'quarterly', 'anchor_month' => 3, 'amount_varies' => true,
    ]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['billTemplates'][0]['frequency'])->toBe('quarterly');
    expect($props['billTemplates'][0]['anchor_month'])->toBe(3);
    expect($props['billTemplates'][0]['amount_varies'])->toBeTrue();
});

// ── A bill remembers the account it is paid from ─────────────────────────

test('a bill can be created against the account it is paid from', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'name' => 'Maya']);

    $this->actingAs($user)->post('/budget/bills', [
        'name' => 'iCloud',
        'expected_amount' => 699,
        'paid_from_account_id' => $checking->id,
        'due_day' => 4,
        'active' => true,
    ])->assertRedirect();

    expect(Bill::where('user_id', $user->id)->firstOrFail()->paid_from_account_id)->toBe($checking->id);
});

test('a bill can be pointed at a liability account', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->create(['is_liability' => true]);

    $this->actingAs($user)->post('/budget/bills', [
        'name' => 'iCloud', 'expected_amount' => 699, 'paid_from_account_id' => $card->id, 'active' => true,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect(Bill::where('name', 'iCloud')->sole()->paid_from_account_id)->toBe($card->id);
});

test('a bill cannot be pointed at another user\'s account', function () {
    $user = User::factory()->create();
    $theirs = Account::factory()->for(User::factory())->create(['is_liability' => false]);

    $this->actingAs($user)->post('/budget/bills', [
        'name' => 'iCloud', 'expected_amount' => 699, 'paid_from_account_id' => $theirs->id, 'active' => true,
    ])->assertSessionHasErrors('paid_from_account_id');
});

test('the page carries each bill period\'s paying account so the sheet can default to it', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false]);
    Bill::factory()->for($user)->create(['name' => 'iCloud', 'paid_from_account_id' => $checking->id]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['bills'][0]['paid_from_account_id'])->toBe($checking->id);
    expect($props['billTemplates'][0]['paid_from_account_id'])->toBe($checking->id);
});

test('each bill in a batch comes out of its own account', function () {
    $user = User::factory()->create();
    $maya = Account::factory()->for($user)->create(['is_liability' => false, 'name' => 'Maya', 'balance' => 10000]);
    $gotyme = Account::factory()->for($user)->create(['is_liability' => false, 'name' => 'GoTyme', 'balance' => 10000]);
    Bill::factory()->for($user)->create(['name' => 'iCloud', 'expected_amount' => 699, 'paid_from_account_id' => $maya->id]);
    Bill::factory()->for($user)->create(['name' => 'Rent', 'expected_amount' => 12000, 'paid_from_account_id' => $gotyme->id]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $periods = BillPeriod::with('bill')->get()->keyBy(fn (BillPeriod $period) => $period->bill->name);

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $maya->id,
        'payments' => [
            ['bill_period_id' => $periods['iCloud']->id, 'actual_amount' => 699, 'from_account_id' => $maya->id],
            ['bill_period_id' => $periods['Rent']->id, 'actual_amount' => 12000, 'from_account_id' => $gotyme->id],
        ],
    ])->assertRedirect();

    expect((float) $maya->fresh()->balance)->toBe(10000.0 - 699.0);
    expect((float) $gotyme->fresh()->balance)->toBe(10000.0 - 12000.0);
});

test('a payment with no account of its own falls back to the one given for the batch', function () {
    $user = User::factory()->create();
    $maya = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    Bill::factory()->for($user)->create(['name' => 'iCloud', 'expected_amount' => 699, 'paid_from_account_id' => null]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = BillPeriod::firstOrFail();

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $maya->id,
        'payments' => [['bill_period_id' => $period->id, 'actual_amount' => 699]],
    ])->assertRedirect();

    expect((float) $maya->fresh()->balance)->toBe(10000.0 - 699.0);
});

test('a batch payment cannot name another user\'s account', function () {
    $user = User::factory()->create();
    $mine = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $theirs = Account::factory()->for(User::factory())->create(['is_liability' => false, 'balance' => 10000]);
    Bill::factory()->for($user)->create(['name' => 'iCloud', 'expected_amount' => 699]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = BillPeriod::firstOrFail();

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $mine->id,
        'payments' => [['bill_period_id' => $period->id, 'actual_amount' => 699, 'from_account_id' => $theirs->id]],
    ])->assertSessionHasErrors('payments.0.from_account_id');

    expect((float) $theirs->fresh()->balance)->toBe(10000.0);
});

test('deleting an account leaves its bills standing, just without a paying account', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false]);
    $bill = Bill::factory()->for($user)->create(['name' => 'iCloud', 'paid_from_account_id' => $checking->id]);

    $this->actingAs($user)->delete("/ledger/accounts/{$checking->id}")->assertRedirect();

    expect($bill->fresh())->not->toBeNull();
    expect($bill->fresh()->paid_from_account_id)->toBeNull();
});

test('a fully settled month still reports what it settled, so it reads differently from an empty one', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 50000]);
    $bill = Bill::factory()->for($user)->create(['name' => 'iCloud', 'expected_amount' => 699, 'due_day' => 4]);
    $debt = Debt::factory()->for($user)->create(['name' => 'Maya Loan', 'balance' => 35000, 'minimum_payment' => 6299.01, 'due_day' => 11, 'linked_account_id' => null]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = BillPeriod::where('bill_id', $bill->id)->firstOrFail();

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $checking->id,
        'payments' => [['bill_period_id' => $period->id, 'actual_amount' => 699]],
    ])->assertRedirect();

    $this->actingAs($user)->post("/budget/debt-minimums/{$debt->id}/pay", [
        'from_account_id' => $checking->id, 'amount' => 7562.51, 'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    // Nothing is still waiting for a date...
    expect($props['sealedTotal'])->toBe(0.0);
    // ...but the month is not empty: both are still listed, marked as settled.
    expect($props['bills'])->toHaveCount(1);
    expect($props['bills'][0]['paid_at'])->not->toBeNull();
    expect($props['debtMinimums'])->toHaveCount(1);
    expect($props['debtMinimums'][0]['paid_this_period'])
        ->toBeGreaterThanOrEqual($props['debtMinimums'][0]['minimum_payment']);
});

test('a month with nothing set up reports nothing to settle at all', function () {
    $user = User::factory()->create();

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['sealedTotal'])->toBe(0.0);
    expect($props['bills'])->toBe([]);
    expect($props['debtMinimums'])->toBe([]);
});

// ── Undoing a bill marked paid ───────────────────────────────────────────

test('marking a bill paid ties the expense it writes to that bill', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    Bill::factory()->for($user)->create(['name' => 'Rent', 'expected_amount' => 12000]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = BillPeriod::firstOrFail();

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $checking->id,
        'payments' => [['bill_period_id' => $period->id, 'actual_amount' => 12000]],
    ])->assertRedirect();

    expect(Transaction::where('bill_period_id', $period->id)->count())->toBe(1);
});

test('un-marking a bill puts the money back and returns it to waiting', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 30000]);
    Bill::factory()->for($user)->create(['name' => 'Rent', 'expected_amount' => 26666.66, 'due_day' => 25]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = BillPeriod::firstOrFail();

    $this->actingAs($user)->post('/budget/bill-periods/pay', [
        'from_account_id' => $checking->id,
        'payments' => [['bill_period_id' => $period->id, 'actual_amount' => 26666.66]],
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(30000.0 - 26666.66);

    $this->actingAs($user)->delete("/budget/bill-periods/{$period->id}/pay")->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(30000.0);
    expect($period->fresh()->paid_at)->toBeNull();
    expect($period->fresh()->actual_amount)->toBeNull();
    expect(Transaction::where('bill_period_id', $period->id)->count())->toBe(0);

    // And it is waiting for its date again.
    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];
    expect($props['sealedTotal'])->toBe(26666.66);
});

test('un-marking a bill that was never paid is rejected', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['expected_amount' => 500]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = BillPeriod::firstOrFail();

    $this->actingAs($user)->delete("/budget/bill-periods/{$period->id}/pay")->assertSessionHasErrors();
});

test('cannot un-mark another user\'s bill', function () {
    $user = User::factory()->create();
    $theirs = BillPeriod::factory()->for(Bill::factory()->for(User::factory()))->create([
        'year' => now()->year, 'month' => now()->month, 'expected_amount' => 500, 'paid_at' => now(),
    ]);

    $this->actingAs($user)->delete("/budget/bill-periods/{$theirs->id}/pay")->assertForbidden();

    expect($theirs->fresh()->paid_at)->not->toBeNull();
});

test('a bill paid before entries were linked can still be un-marked', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    Bill::factory()->for($user)->create(['expected_amount' => 699]);

    $this->actingAs($user)->get('/budget')->assertOk();
    $period = BillPeriod::firstOrFail();
    // An older payment: stamped paid, with an expense that names it but no link.
    (new LedgerService)->recordExpense($checking, 699, 'Bill: legacy');
    $period->update(['actual_amount' => 699, 'paid_at' => now()]);

    $this->actingAs($user)->delete("/budget/bill-periods/{$period->id}/pay")->assertRedirect();

    expect($period->fresh()->paid_at)->toBeNull();
    // The untraceable entry is left alone rather than guessed at.
    expect((float) $checking->fresh()->balance)->toBe(10000.0 - 699.0);
});

// ── Safe to spend is capped by real money ────────────────────────────────

test('safe to spend falls back to the cash actually in the accounts when the plan asks for more', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 1200]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 5000]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];
    $daysRemaining = $props['safeToSpend']['daysRemaining'];

    expect($props['safeToSpend']['unspent'])->toBe(1200.0);
    expect($props['safeToSpend']['perDay'])->toBe(round(1200.0 / $daysRemaining, 2));
    expect($props['safeToSpend']['planned'])->toBe(5000.0);
    expect($props['safeToSpend']['cashAvailable'])->toBe(1200.0);
    expect($props['safeToSpend']['limitedByCash'])->toBeTrue();
    expect($props['safeToSpend']['cashKnown'])->toBeTrue();
});

test('safe to spend stays on the plan when the accounts hold more than it asks for', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 9000]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 5000]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['safeToSpend']['unspent'])->toBe(5000.0);
    expect($props['safeToSpend']['planned'])->toBe(5000.0);
    expect($props['safeToSpend']['cashAvailable'])->toBe(9000.0);
    expect($props['safeToSpend']['limitedByCash'])->toBeFalse();
});

test('unpaid bills and debt minimums are taken out of the cash before it backs the allowance', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 5000]);
    Bill::factory()->for($user)->create(['name' => 'Rent', 'frequency' => 'monthly', 'expected_amount' => 2000]);
    Debt::factory()->for($user)->create(['name' => 'Card', 'balance' => 10000, 'minimum_payment' => 500]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 5000]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['safeToSpend']['cashAvailable'])->toBe(2500.0);
    expect($props['safeToSpend']['unspent'])->toBe(2500.0);
});

test('a paid bill stops claiming cash from the allowance', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 5000]);
    $bill = Bill::factory()->for($user)->create(['name' => 'Rent', 'frequency' => 'monthly', 'expected_amount' => 2000]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 5000]);

    $this->actingAs($user)->get('/budget')->assertOk();
    BillPeriod::where('bill_id', $bill->id)->update(['paid_at' => now()]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['safeToSpend']['cashAvailable'])->toBe(5000.0);
});

test('a user with no asset accounts is told the allowance is unknown rather than shown a plan figure', function () {
    $user = User::factory()->create();
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 5000]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['safeToSpend']['cashKnown'])->toBeFalse();
    expect($props['safeToSpend']['cashAvailable'])->toBeNull();
    expect($props['safeToSpend']['unspent'])->toBe(0.0);
    expect($props['safeToSpend']['perDay'])->toBe(0.0);
    expect($props['safeToSpend']['planned'])->toBe(5000.0);
});

test('a credit card is not counted as cash backing the allowance', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->liability()->create(['balance' => 9000]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 5000]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['safeToSpend']['cashKnown'])->toBeFalse();
    expect($props['safeToSpend']['unspent'])->toBe(0.0);
});

test('cash swallowed whole by this month\'s bills leaves nothing safe to spend', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 1000]);
    Bill::factory()->for($user)->create(['name' => 'Rent', 'frequency' => 'monthly', 'expected_amount' => 4000]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 5000]);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect($props['safeToSpend']['cashAvailable'])->toBe(0.0);
    expect($props['safeToSpend']['unspent'])->toBe(0.0);
    expect($props['safeToSpend']['cashKnown'])->toBeTrue();
});

test('raising a bill amount re-prices the current month\'s unpaid period', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['name' => 'Google One', 'expected_amount' => 1]);

    // Viewing the month materialises the period snapshot at the old amount.
    $this->actingAs($user)->get('/budget')->assertOk();
    expect((float) BillPeriod::where('bill_id', $bill->id)->sole()->expected_amount)->toBe(1.0);

    $this->actingAs($user)->patch(route('budget.bills.update', $bill), [
        'name' => 'Google One',
        'expected_amount' => 599,
        'due_day' => $bill->due_day,
        'active' => true,
    ])->assertRedirect();

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect((float) $props['bills'][0]['expected_amount'])->toBe(599.0);
});

test('re-pricing a bill leaves a period that is already paid alone', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 1]);
    $period = BillPeriod::factory()->for($bill)->create([
        'year' => (int) now()->year,
        'month' => (int) now()->month,
        'expected_amount' => 1,
        'paid_at' => now(),
    ]);

    $this->actingAs($user)->patch(route('budget.bills.update', $bill), [
        'name' => $bill->name,
        'expected_amount' => 599,
        'due_day' => $bill->due_day,
        'active' => true,
    ])->assertRedirect();

    expect((float) $period->fresh()->expected_amount)->toBe(1.0);
});

test('re-pricing a bill does not rewrite what past months were owed', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 1]);
    $lastMonth = now()->subMonthNoOverflow();
    $period = BillPeriod::factory()->for($bill)->create([
        'year' => (int) $lastMonth->year,
        'month' => (int) $lastMonth->month,
        'expected_amount' => 1,
        'paid_at' => null,
    ]);

    $this->actingAs($user)->patch(route('budget.bills.update', $bill), [
        'name' => $bill->name,
        'expected_amount' => 599,
        'due_day' => $bill->due_day,
        'active' => true,
    ])->assertRedirect();

    expect((float) $period->fresh()->expected_amount)->toBe(1.0);
});

test('a credit card is offered as somewhere a bill can be paid from', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'GoTyme']);
    Account::factory()->for($user)->liability()->create(['name' => 'HSBC CC']);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect(collect($props['billPaymentAccounts'])->pluck('name')->all())
        ->toEqualCanonicalizing(['GoTyme', 'HSBC CC']);
});

test('the cash-only account list stays free of credit cards', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'GoTyme']);
    Account::factory()->for($user)->liability()->create(['name' => 'HSBC CC']);

    $props = $this->actingAs($user)->get('/budget')->assertOk()->viewData('page')['props'];

    expect(collect($props['assetAccounts'])->pluck('name')->all())->toBe(['GoTyme']);
});

test('paying a bill with a credit card grows what is owed on the card', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create(['balance' => 1000]);
    $bill = Bill::factory()->for($user)->create(['name' => 'Google One', 'expected_amount' => 599]);
    $period = BillPeriod::factory()->for($bill)->create(['expected_amount' => 599, 'paid_at' => null]);

    $this->actingAs($user)->post(route('budget.bill-periods.pay', $period), [
        'actual_amount' => 599,
        'from_account_id' => $card->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect((float) $card->fresh()->balance)->toBe(1599.0);
    expect((float) $period->fresh()->actual_amount)->toBe(599.0);
});

test('a debt linked to the card rises with a bill charged to it', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create(['balance' => 1000]);
    Debt::factory()->for($user)->create(['name' => 'HSBC CC', 'linked_account_id' => $card->id]);
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 599]);
    $period = BillPeriod::factory()->for($bill)->create(['expected_amount' => 599, 'paid_at' => null]);

    $this->actingAs($user)->post(route('budget.bill-periods.pay', $period), [
        'actual_amount' => 599,
        'from_account_id' => $card->id,
    ])->assertRedirect()->assertSessionHasNoErrors();

    $debt = app(BudgetService::class)->liveDebts($user->fresh())
        ->firstWhere('name', 'HSBC CC');

    expect((float) $card->fresh()->balance)->toBe(1599.0);
    expect((float) $debt->balance)->toBe(1599.0);
});

test('unmarking a bill paid by card takes the charge back off the card', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create(['balance' => 1000]);
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 599]);
    $period = BillPeriod::factory()->for($bill)->create(['expected_amount' => 599, 'paid_at' => null]);

    $this->actingAs($user)->post(route('budget.bill-periods.pay', $period), [
        'actual_amount' => 599,
        'from_account_id' => $card->id,
    ])->assertRedirect();

    $this->actingAs($user)->delete(route('budget.bill-periods.unpay', $period))->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(1000.0);
    expect($period->fresh()->paid_at)->toBeNull();
});

test('several bills can be settled on a credit card at once', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create(['balance' => 0]);
    $first = BillPeriod::factory()->for(Bill::factory()->for($user)->create())->create(['paid_at' => null]);
    $second = BillPeriod::factory()->for(Bill::factory()->for($user)->create())->create(['paid_at' => null]);

    $this->actingAs($user)->post(route('budget.bill-periods.pay-many'), [
        'from_account_id' => $card->id,
        'payments' => [
            ['bill_period_id' => $first->id, 'actual_amount' => 100],
            ['bill_period_id' => $second->id, 'actual_amount' => 250],
        ],
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect((float) $card->fresh()->balance)->toBe(350.0);
});

test('an envelope sweep still refuses to draw from a credit card', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 500]);
    $lastMonth = now()->subMonthNoOverflow();
    $period = EnvelopePeriod::factory()->for($envelope)->create([
        'year' => (int) $lastMonth->year,
        'month' => (int) $lastMonth->month,
        'target' => 500,
        'sweep_offered_at' => now(),
        'sweep_accepted' => null,
    ]);

    $this->actingAs($user)->post(route('budget.envelope-periods.sweep.confirm', $period), [
        'from_account_id' => $card->id,
    ])->assertSessionHasErrors('from_account_id');
});

test('an extra debt payment still refuses to draw from a credit card', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    $linked = Account::factory()->for($user)->liability()->create(['balance' => 5000]);
    Debt::factory()->for($user)->create(['linked_account_id' => $linked->id]);

    $this->actingAs($user)->post(route('budget.extra-payment.confirm'), [
        'amount' => 100,
        'year' => (int) now()->year,
        'month' => (int) now()->month,
        'from_account_id' => $card->id,
    ])->assertSessionHasErrors('from_account_id');
});
