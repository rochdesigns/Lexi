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
use Carbon\Carbon;

beforeEach(function () {
    // Mid-month, so "what is left of this month" is a real question, and clear
    // of a month end where addMonths() would skid off a short month.
    Carbon::setTestNow(Carbon::create(2026, 1, 15, 9));
});

afterEach(function () {
    Carbon::setTestNow();
});

function forecastUser(?float $income = 5000.0, float $cash = 10000.0): User
{
    $user = User::factory()->create();

    Settings::factory()->for($user)->create([
        'strategy' => 'avalanche',
        'extra_monthly' => 0,
        'expected_monthly_income' => $income,
    ]);

    if ($cash > 0) {
        Account::factory()->for($user)->create(['is_liability' => false, 'balance' => $cash]);
    }

    return $user;
}

function forecastProps(User $user): array
{
    return test()->actingAs($user)->get('/forecast')->assertOk()->viewData('page')['props'];
}

test('guests are redirected to login', function () {
    $this->get('/forecast')->assertRedirect('/login');
});

test('asks for the income figure before projecting anything', function () {
    $user = forecastUser(income: null);

    $props = forecastProps($user);

    expect($props['expectedMonthlyIncome'])->toBeNull();
    expect($props['months'])->toBe([]);
    expect($props['tightMonth'])->toBeNull();
});

test('suggests the average of recent months when income has never been answered', function () {
    $user = forecastUser(income: null);
    $account = $user->debtFocusAccounts()->first();

    foreach ([[2025, 12, 4000], [2025, 11, 5000]] as [$year, $month, $amount]) {
        Transaction::factory()->for($user)->create([
            'account_id' => $account->id,
            'type' => 'income',
            'amount' => $amount,
            'occurred_at' => Carbon::create($year, $month, 5),
        ]);
    }

    expect(forecastProps($user)['suggestedMonthlyIncome'])->toBe(4500.0);
});

test('projects twelve months and carries the running balance forward', function () {
    $user = forecastUser(income: 5000.0, cash: 1000.0);

    $props = forecastProps($user);

    expect($props['months'])->toHaveCount(12);
    expect($props['openingBalance'])->toBe(1000.0);
    expect($props['months'][0]['year'])->toBe(2026);
    expect($props['months'][0]['month'])->toBe(1);
    expect($props['months'][11]['month'])->toBe(12);
    // Nothing going out, so each month adds the whole expected income.
    expect($props['months'][0]['balance'])->toBe(6000.0);
    expect($props['months'][1]['balance'])->toBe(11000.0);
    expect($props['closingBalance'])->toBe(61000.0);
    expect($props['tightMonth'])->toBeNull();
});

test('flags the first month the running balance goes under', function () {
    $user = forecastUser(income: 1000.0, cash: 2500.0);

    Envelope::factory()->for($user)->create(['default_target' => 2000, 'active' => true]);

    // -1000 a month against 2500 on hand: still up after Jan and Feb, under in March.
    $props = forecastProps($user);

    expect($props['tightMonth']['year'])->toBe(2026);
    expect($props['tightMonth']['month'])->toBe(3);
    expect($props['tightMonth']['shortfall'])->toBe(500.0);
});

test('drops a debt minimum from the outflow once the debt is paid off', function () {
    $user = forecastUser();

    Debt::factory()->for($user)->create([
        'balance' => 300, 'minimum_payment' => 100, 'monthly_rate' => null, 'debt_type' => 'installment',
    ]);

    $months = forecastProps($user)['months'];

    expect($months[0]['debt'])->toBe(100.0);
    expect($months[2]['debt'])->toBe(100.0);
    expect($months[3]['debt'])->toBe(0.0);
});

test('lands a yearly bill only in its anchor month', function () {
    $user = forecastUser();

    Bill::factory()->for($user)->create([
        'expected_amount' => 1200,
        'frequency' => 'yearly',
        'anchor_month' => 6,
        'amount_varies' => false,
        'active' => true,
    ]);

    $months = collect(forecastProps($user)['months'])->keyBy('month');

    expect($months[6]['bills'])->toBe(1200.0);
    expect($months[5]['bills'])->toBe(0.0);
    expect($months[7]['bills'])->toBe(0.0);
});

test('creates no period rows for the months it looks at', function () {
    $user = forecastUser();
    Bill::factory()->for($user)->create(['expected_amount' => 100, 'active' => true]);
    Envelope::factory()->for($user)->create(['default_target' => 200, 'active' => true]);

    forecastProps($user);

    expect(BillPeriod::count())->toBe(0);
    expect(EnvelopePeriod::count())->toBe(0);
});

test('the current month honours an amount edited on the budget page', function () {
    $user = forecastUser();
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 100, 'active' => true]);

    BillPeriod::factory()->for($bill)->create([
        'year' => 2026, 'month' => 1, 'expected_amount' => 175, 'paid_at' => null,
    ]);

    $months = forecastProps($user)['months'];

    expect($months[0]['bills'])->toBe(175.0);
    // Later months have no row of their own and fall back to the template.
    expect($months[1]['bills'])->toBe(100.0);
});

test('the current month counts only what is still to happen', function () {
    $user = forecastUser(income: 5000.0, cash: 10000.0);
    $account = $user->debtFocusAccounts()->first();

    // Half the pay has landed already.
    Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'income', 'amount' => 2000,
        'occurred_at' => Carbon::create(2026, 1, 3),
    ]);

    // A bill already paid, and one still outstanding.
    $paid = Bill::factory()->for($user)->create(['expected_amount' => 400, 'active' => true]);
    $unpaid = Bill::factory()->for($user)->create(['expected_amount' => 300, 'active' => true]);
    BillPeriod::factory()->for($paid)->create([
        'year' => 2026, 'month' => 1, 'expected_amount' => 400, 'paid_at' => Carbon::create(2026, 1, 10),
    ]);
    BillPeriod::factory()->for($unpaid)->create([
        'year' => 2026, 'month' => 1, 'expected_amount' => 300, 'paid_at' => null,
    ]);

    // An envelope half spent.
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 1000, 'active' => true]);
    EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 1, 'target' => 1000]);
    Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 600,
        'envelope_id' => $envelope->id, 'occurred_at' => Carbon::create(2026, 1, 8),
    ]);

    $months = forecastProps($user)['months'];

    expect($months[0]['income'])->toBe(3000.0);
    expect($months[0]['bills'])->toBe(300.0);
    expect($months[0]['envelopes'])->toBe(400.0);

    // Next month is the whole of everything again.
    expect($months[1]['income'])->toBe(5000.0);
    expect($months[1]['bills'])->toBe(700.0);
    expect($months[1]['envelopes'])->toBe(1000.0);
});

test('the current month nets off debt money already sent', function () {
    $user = forecastUser();
    $account = $user->debtFocusAccounts()->first();

    $debt = Debt::factory()->for($user)->create([
        'balance' => 5000, 'minimum_payment' => 250, 'monthly_rate' => null, 'debt_type' => 'installment',
    ]);

    Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 100,
        'debt_id' => $debt->id, 'occurred_at' => Carbon::create(2026, 1, 6),
    ]);

    $months = forecastProps($user)['months'];

    expect($months[0]['debt'])->toBe(150.0);
    expect($months[1]['debt'])->toBe(250.0);
});

test('leaves the running balance unanswered when there is no asset account', function () {
    $user = forecastUser(income: 1000.0, cash: 0.0);
    Envelope::factory()->for($user)->create(['default_target' => 1500, 'active' => true]);

    $props = forecastProps($user);

    expect($props['openingBalance'])->toBeNull();
    expect($props['closingBalance'])->toBeNull();
    expect($props['months'][0]['balance'])->toBeNull();
    // Falls back to the first month that fails to cover itself.
    expect($props['months'][0]['net'])->toBe(-500.0);
    expect($props['tightMonth']['month'])->toBe(1);
    expect($props['tightMonth']['shortfall'])->toBe(500.0);
});

test('only ever reads the signed-in user\'s own data', function () {
    $user = forecastUser(income: 1000.0, cash: 500.0);
    $stranger = forecastUser(income: 9000.0, cash: 90000.0);

    Envelope::factory()->for($stranger)->create(['default_target' => 8000, 'active' => true]);

    $props = forecastProps($user);

    expect($props['openingBalance'])->toBe(500.0);
    expect($props['months'][0]['envelopes'])->toBe(0.0);
});

test('saves the expected income figure', function () {
    $user = forecastUser(income: null);

    $this->actingAs($user)
        ->patch('/forecast/income', ['expected_monthly_income' => 4200])
        ->assertRedirect();

    expect((float) $user->debtFocusSettings()->first()->expected_monthly_income)->toBe(4200.0);
});

test('rejects a negative income figure', function () {
    $user = forecastUser(income: null);

    $this->actingAs($user)
        ->patch('/forecast/income', ['expected_monthly_income' => -10])
        ->assertSessionHasErrors('expected_monthly_income');
});
