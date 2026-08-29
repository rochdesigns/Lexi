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
    Debt::factory()->for($user)->create(['name' => 'Maya CC', 'minimum_payment' => 75]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $minimums = $response->viewData('page')['props']['debtMinimums'];

    expect($minimums)->toHaveCount(1);
    expect($minimums[0]['name'])->toBe('Maya CC');
    expect((float) $minimums[0]['minimum_payment'])->toBe(75.0);
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

test('leftover never goes negative when assignments exceed income', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['minimum_payment' => 5000]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect((float) $response->viewData('page')['props']['leftover'])->toBe(0.0);
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
    $bill = Bill::factory()->for($user)->create();
    $period = BillPeriod::factory()->for($bill)->create(['actual_amount' => null, 'paid_at' => null]);

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 62.5,
    ])->assertRedirect();

    $period->refresh();
    expect((float) $period->actual_amount)->toBe(62.5);
    expect($period->paid_at)->not->toBeNull();
});

test('marking a bill paid requires a positive actual amount', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create();
    $period = BillPeriod::factory()->for($bill)->create();

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 0,
    ])->assertSessionHasErrors('actual_amount');
});

test('cannot mark another user\'s bill period paid', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $bill = Bill::factory()->for($owner)->create();
    $period = BillPeriod::factory()->for($bill)->create();

    $this->actingAs($intruder)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 10,
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
    ])->assertStatus(422);

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
    ])->assertStatus(422);
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
    ])->assertStatus(422);

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
    ])->assertStatus(422);

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
    ])->assertStatus(422);
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
    ])->assertStatus(422);
});

test('rejects an out-of-range month on the budget page', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get('/budget?year=2026&month=13')->assertSessionHasErrors('month');
});
