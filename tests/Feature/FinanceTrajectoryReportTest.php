<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\DebtPayment;
use App\Models\Goal;
use App\Models\RecurringBill;
use App\Models\Setting;
use App\Models\Transaction;
use App\Models\User;
use App\Services\TrajectoryReportService;
use Carbon\Carbon;

test('settings store financial independence planning fields', function () {
    $user = User::factory()->create();

    $setting = Setting::create([
        'user_id' => $user->id,
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'fi_number' => 25000000,
        'fi_expected_return_rate' => 7.5,
        'emergency_fund_target_months' => 4,
        'investment_monthly_contribution_target' => 15000,
    ]);

    $fresh = $setting->fresh();

    expect((float) $fresh->fi_number)->toBe(25000000.0)
        ->and((float) $fresh->fi_expected_return_rate)->toBe(7.5)
        ->and($fresh->emergency_fund_target_months)->toBe(4)
        ->and((float) $fresh->investment_monthly_contribution_target)->toBe(15000.0);
});

test('financial independence fields fall back to sensible defaults', function () {
    $user = User::factory()->create();

    $setting = Setting::create(['user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30])->fresh();

    expect($setting->fi_number)->toBeNull()
        ->and((float) $setting->fi_expected_return_rate)->toBe(6.0)
        ->and($setting->emergency_fund_target_months)->toBe(6)
        ->and($setting->investment_monthly_contribution_target)->toBeNull();
});

test('accounts store cost basis and emergency fund flag', function () {
    $user = User::factory()->create();

    $investment = Account::factory()->investment()->for($user)->create([
        'balance' => 120000,
        'cost_basis' => 100000,
    ])->fresh();

    $emergency = Account::factory()->emergencyFund()->for($user)->create()->fresh();

    expect((float) $investment->cost_basis)->toBe(100000.0)
        ->and($investment->type)->toBe('investment')
        ->and($investment->is_emergency_fund)->toBeFalse()
        ->and($emergency->is_emergency_fund)->toBeTrue()
        ->and($emergency->type)->toBe('cash');
});

test('a transaction can be linked to a debt and is unlinked when the debt is deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'debt_id' => $debt->id,
        'type' => 'expense',
        'amount' => 500,
    ]);

    expect($transaction->fresh()->debt->id)->toBe($debt->id)
        ->and($debt->transactions()->pluck('id')->all())->toBe([$transaction->id]);

    $debt->delete();

    expect($transaction->fresh()->debt_id)->toBeNull();
});

test('debt payments record the principal and interest split and cascade with their transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create();
    $transaction = Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'debt_id' => $debt->id,
    ]);

    $payment = \App\Models\DebtPayment::create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'amount' => 5000,
        'principal_amount' => 4200,
        'interest_amount' => 800,
        'paid_at' => '2026-08-01',
    ]);

    expect((float) $payment->fresh()->principal_amount)->toBe(4200.0)
        ->and($payment->fresh()->paid_at->toDateString())->toBe('2026-08-01')
        ->and($debt->debtPayments()->count())->toBe(1)
        ->and($payment->transaction->id)->toBe($transaction->id);

    $transaction->delete();

    expect(\App\Models\DebtPayment::find($payment->id))->toBeNull();
});

test('balance snapshots are unique per owner per day', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = \App\Models\Debt::factory()->for($user)->create();

    \App\Models\AccountBalanceSnapshot::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'balance' => 1200.50,
        'snapshot_date' => '2026-08-01',
    ]);

    \App\Models\DebtBalanceSnapshot::create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'balance' => 90000,
        'snapshot_date' => '2026-08-01',
    ]);

    expect($account->balanceSnapshots()->count())->toBe(1)
        ->and((float) $account->balanceSnapshots()->first()->balance)->toBe(1200.50)
        ->and($debt->balanceSnapshots()->count())->toBe(1);

    expect(fn () => \App\Models\AccountBalanceSnapshot::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'balance' => 999,
        'snapshot_date' => '2026-08-01',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});

test('net worth reports the current figure and a monthly snapshot trend', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);
    $debt = Debt::factory()->for($user)->create(['balance' => 4000]);

    foreach ([['2026-07-31', 8000], ['2026-08-05', 10000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    foreach ([['2026-07-31', 5000], ['2026-08-05', 4000]] as [$date, $balance]) {
        DebtBalanceSnapshot::create([
            'user_id' => $user->id, 'debt_id' => $debt->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeTrue()
        ->and($result['current'])->toBe(6000.0)
        ->and($result['accountTotal'])->toBe(10000.0)
        ->and($result['debtTotal'])->toBe(4000.0)
        ->and($result['trend'])->toHaveCount(2)
        ->and($result['trend'][0])->toBe(['month' => '2026-07', 'label' => 'Jul 2026', 'value' => 3000.0])
        ->and($result['trend'][1]['value'])->toBe(6000.0)
        ->and($result['changeAmount'])->toBe(3000.0)
        ->and($result['changePct'])->toBe(100.0);

    Carbon::setTestNow();
});

test('net worth excludes credit accounts that are linked to a debt', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);
    $card = Account::factory()->for($user)->create(['balance' => 3000, 'type' => 'credit']);
    Debt::factory()->for($user)->create(['account_id' => $card->id, 'balance' => 3000]);

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['accountTotal'])->toBe(10000.0)
        ->and($result['current'])->toBe(7000.0);

    Carbon::setTestNow();
});

test('net worth is unavailable without at least two months of snapshots but still reports the headline', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);

    AccountBalanceSnapshot::create([
        'user_id' => $user->id, 'account_id' => $account->id,
        'balance' => 10000, 'snapshot_date' => '2026-08-05',
    ]);

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString()
        ->and($result['current'])->toBe(10000.0)
        ->and($result['trend'])->toBe([]);

    Carbon::setTestNow();
});

test('net worth omits a month from the trend when the user has debts but no debt snapshot for that month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 7000, 'type' => 'cash']);
    $debt = Debt::factory()->for($user)->create(['balance' => 1500]);

    foreach ([['2026-06-30', 5000], ['2026-07-31', 6000], ['2026-08-05', 7000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    // Debt snapshots exist for June and August but NOT July — simulating a month
    // where RecordFinanceSnapshot's backfill declined to write debt history
    // because it didn't reconcile within tolerance (Task 9). July's debt balance
    // is unknown, not zero, so July must be dropped from the trend rather than
    // computed as if the debt didn't exist that month.
    foreach ([['2026-06-30', 1000], ['2026-08-05', 1500]] as [$date, $balance]) {
        DebtBalanceSnapshot::create([
            'user_id' => $user->id, 'debt_id' => $debt->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeTrue()
        ->and($result['trend'])->toHaveCount(2)
        ->and(collect($result['trend'])->pluck('month')->all())->toBe(['2026-06', '2026-08'])
        ->and($result['trend'][0]['value'])->toBe(4000.0)
        ->and($result['trend'][1]['value'])->toBe(5500.0);

    Carbon::setTestNow();
});

test('savings rate reports the current month and a six month trend', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $book = function (string $type, float $amount, string $date) use ($user, $account) {
        \App\Models\Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'type' => $type, 'amount' => $amount, 'transacted_at' => $date,
        ]);
    };

    $book('income', 50000, '2026-08-02');
    $book('expense', 30000, '2026-08-03');
    $book('income', 40000, '2026-07-02');
    $book('expense', 40000, '2026-07-03');

    $result = app(TrajectoryReportService::class)->savingsRate($user);

    expect($result['available'])->toBeTrue()
        ->and($result['income'])->toBe(50000.0)
        ->and($result['expenses'])->toBe(30000.0)
        ->and($result['net'])->toBe(20000.0)
        ->and($result['rate'])->toBe(40.0)
        ->and($result['trend'])->toHaveCount(6)
        ->and($result['trend'][5])->toBe(['month' => '2026-08', 'label' => 'Aug 2026', 'income' => 50000.0, 'expenses' => 30000.0, 'rate' => 40.0])
        ->and($result['trend'][4]['rate'])->toBe(0.0)
        ->and($result['trend'][0]['rate'])->toBeNull()
        ->and($result['averageRate'])->toBe(20.0);

    Carbon::setTestNow();
});

test('savings rate is null rather than zero when there is no income this month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 1000, 'transacted_at' => '2026-08-02',
    ]);

    $result = app(TrajectoryReportService::class)->savingsRate($user);

    expect($result['available'])->toBeTrue()
        ->and($result['rate'])->toBeNull()
        ->and($result['expenses'])->toBe(1000.0);

    Carbon::setTestNow();
});

test('savings rate is unavailable with no transactions in the window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();

    $result = app(TrajectoryReportService::class)->savingsRate($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();

    Carbon::setTestNow();
});

test('debt summary sorts by interest rate and projects a debt free date', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Card', 'balance' => 10000, 'per_month' => 1000, 'interest_rate' => 18, 'interest_rate_period' => 'annual']);
    Debt::factory()->for($user)->create(['name' => 'Loan', 'balance' => 3000, 'per_month' => 1000, 'interest_rate' => 6, 'interest_rate_period' => 'annual']);
    Debt::factory()->for($user)->create(['name' => 'Friend', 'balance' => 500, 'per_month' => 500, 'interest_rate' => null]);

    $result = app(TrajectoryReportService::class)->debtSummary($user);

    expect($result['available'])->toBeTrue()
        ->and($result['totalRemaining'])->toBe(13500.0)
        ->and($result['totalMonthly'])->toBe(2500.0)
        ->and(array_column($result['debts'], 'name'))->toBe(['Card', 'Loan', 'Friend'])
        ->and($result['debts'][0]['interestRate'])->toBe(18.0)
        ->and($result['debts'][2]['interestRate'])->toBeNull()
        ->and($result['onTrack'])->toBeTrue()
        ->and($result['debtFreeMonths'])->toBeInt()
        ->and($result['debtFreeDate'])->toBeString()
        ->and($result['extraMonthly'])->toBe(0.0);

    Carbon::setTestNow();
});

test('an extra monthly payment shortens the debt free date avalanche style', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'A', 'balance' => 10000, 'per_month' => 1000, 'interest_rate' => null]);
    Debt::factory()->for($user)->create(['name' => 'B', 'balance' => 3000, 'per_month' => 1000, 'interest_rate' => null]);

    $service = app(TrajectoryReportService::class);

    expect($service->debtSummary($user)['debtFreeMonths'])->toBe(10);

    $boosted = $service->debtSummary($user, 1000.0);

    expect($boosted['extraMonthly'])->toBe(1000.0)
        ->and($boosted['debtFreeMonths'])->toBe(5)
        ->and($boosted['baselineDebtFreeMonths'])->toBe(10)
        ->and($boosted['monthsSaved'])->toBe(5);

    Carbon::setTestNow();
});

test('a debt whose payment cannot cover its interest is reported as not on track', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 100000, 'per_month' => 100, 'interest_rate' => 24, 'interest_rate_period' => 'annual']);

    $result = app(TrajectoryReportService::class)->debtSummary($user);

    expect($result['onTrack'])->toBeFalse()
        ->and($result['debtFreeMonths'])->toBeNull()
        ->and($result['debtFreeDate'])->toBeNull()
        ->and($result['debts'][0]['months'])->toBeNull();

    Carbon::setTestNow();
});

test('debt summary reports interest bled this month and year to date with a monthly split trend', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 5000, 'interest_rate' => null]);

    foreach ([
        ['2026-08-01', 5000, 4200, 800],
        ['2026-03-01', 5000, 4500, 500],
        ['2025-12-01', 5000, 4001, 999],
    ] as [$date, $amount, $principal, $interest]) {
        DebtPayment::factory()->create([
            'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id,
            'amount' => $amount, 'principal_amount' => $principal, 'interest_amount' => $interest,
            'paid_at' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->debtSummary($user);

    expect($result['interestThisMonth'])->toBe(800.0)
        ->and($result['interestYearToDate'])->toBe(1300.0)
        ->and($result['principalInterestTrend'])->toHaveCount(12)
        ->and($result['principalInterestTrend'][11])->toBe(['month' => '2026-08', 'label' => 'Aug 2026', 'principal' => 4200.0, 'interest' => 800.0]);

    Carbon::setTestNow();
});

test('debt to income uses recorded payments and falls back to scheduled payments', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 4000, 'interest_rate' => null]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'income', 'amount' => 50000, 'transacted_at' => '2026-08-01',
    ]);

    $estimated = app(TrajectoryReportService::class)->debtSummary($user)['debtToIncome'];

    expect($estimated['isEstimated'])->toBeTrue()
        ->and($estimated['monthlyDebtPayments'])->toBe(4000.0)
        ->and($estimated['ratio'])->toBe(8.0);

    DebtPayment::factory()->create([
        'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id,
        'amount' => 5000, 'principal_amount' => 5000, 'interest_amount' => 0, 'paid_at' => '2026-08-02',
    ]);

    $actual = app(TrajectoryReportService::class)->debtSummary($user)['debtToIncome'];

    expect($actual['isEstimated'])->toBeFalse()
        ->and($actual['monthlyDebtPayments'])->toBe(5000.0)
        ->and($actual['ratio'])->toBe(10.0)
        ->and($actual['trend'])->toHaveCount(6);

    Carbon::setTestNow();
});

test('debt summary is unavailable with no outstanding debts', function () {
    $user = User::factory()->create();

    $result = app(TrajectoryReportService::class)->debtSummary($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();
});

test('an extra payment targets the highest rate debt, not the lowest, among genuinely different rates', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'High', 'balance' => 1000, 'per_month' => 200, 'interest_rate' => 5, 'interest_rate_period' => 'monthly']);
    Debt::factory()->for($user)->create(['name' => 'Mid', 'balance' => 1000, 'per_month' => 200, 'interest_rate' => 1, 'interest_rate_period' => 'monthly']);
    Debt::factory()->for($user)->create(['name' => 'None', 'balance' => 1000, 'per_month' => 200, 'interest_rate' => null]);

    $service = app(TrajectoryReportService::class);

    // Baseline: with no extra, High and Mid (the two interest-bearing debts) both
    // take 6 months since neither is favored — the 200/mo payment barely outpaces
    // interest either way. This confirms the divergence below comes from the extra,
    // not from some incidental effect of the rates alone.
    $baseline = $service->debtSummary($user);
    expect(array_column($baseline['debts'], 'months'))->toBe([6, 6, 5]);

    // With a 200/mo extra, avalanche targeting must send it to High (5%/mo) first.
    // High clears in 3 months instead of 6. Once High clears, the extra cascades to
    // Mid (1%/mo, the next-highest active rate), so Mid also finishes early (5
    // instead of 6). If the comparator direction were reversed — sending the extra
    // to the *lowest*-rate active debt instead — the extra would go to None first,
    // then cascade to Mid, leaving High untouched at its baseline 6 months and
    // clearing None in 3. That is the mirror image of the correct result below, so
    // asserting High=3 (not 6) and None=5 (not 3) fails outright under a reversed
    // comparator.
    $boosted = $service->debtSummary($user, 200.0);

    expect(array_column($boosted['debts'], 'name'))->toBe(['High', 'Mid', 'None'])
        ->and($boosted['debts'][0]['months'])->toBe(3)
        ->and($boosted['debts'][1]['months'])->toBe(5)
        ->and($boosted['debts'][2]['months'])->toBe(5)
        ->and($boosted['debtFreeMonths'])->toBe(5);

    Carbon::setTestNow();
});

test('a cleared debt does not redistribute its freed monthly payment to the remaining debt', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Small', 'balance' => 2000, 'per_month' => 1000, 'interest_rate' => null]);
    Debt::factory()->for($user)->create(['name' => 'Big', 'balance' => 9000, 'per_month' => 500, 'interest_rate' => null]);

    $result = app(TrajectoryReportService::class)->debtSummary($user, 500.0);

    // Small (the avalanche target, tied at rate 0 but first by input order) receives
    // the extra for months 1-2 and clears in month 2, leaving Big at balance 8,000
    // (9,000 - 500/mo for 2 months, unaffected by the extra). From month 3 on, Big
    // is the sole active debt and — per the documented simplification — receives
    // only its own per_month (500) plus the explicit extra (500) = 1,000/mo, NOT
    // Small's freed 1,000/mo on top of that. Correct (no redistribution): Big's
    // remaining 8,000 balance / 1,000 per month takes 8 more months, clearing at
    // month 10 — so debtFreeMonths = 10.
    // If Small's freed per_month were (incorrectly) folded into Big's payment once
    // Small cleared, Big would pay 2,000/mo from month 3 and clear at month 6
    // instead — a different, smaller debtFreeMonths. Asserting 10 (not 6) fails
    // outright under that hypothesis.
    expect(array_column($result['debts'], 'name'))->toBe(['Small', 'Big'])
        ->and($result['debts'][0]['months'])->toBe(2)
        ->and($result['debts'][1]['months'])->toBe(10)
        ->and($result['debtFreeMonths'])->toBe(10);

    Carbon::setTestNow();
});

test('savings summary reports emergency fund coverage against the configured target', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create(['user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30, 'emergency_fund_target_months' => 6]);

    Account::factory()->emergencyFund()->for($user)->create(['balance' => 90000]);
    $spending = Account::factory()->for($user)->create(['type' => 'cash']);

    foreach (['2026-08-02', '2026-07-02', '2026-06-02'] as $date) {
        \App\Models\Transaction::factory()->for($user)->create([
            'account_id' => $spending->id, 'type' => 'expense', 'amount' => 30000, 'transacted_at' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->savingsSummary($user);

    expect($result['available'])->toBeTrue()
        ->and($result['emergencyFund']['available'])->toBeTrue()
        ->and($result['emergencyFund']['balance'])->toBe(90000.0)
        ->and($result['emergencyFund']['avgMonthlyExpenses'])->toBe(30000.0)
        ->and($result['emergencyFund']['monthsCovered'])->toBe(3.0)
        ->and($result['emergencyFund']['targetMonths'])->toBe(6)
        ->and($result['emergencyFund']['progressPct'])->toBe(50.0);

    Carbon::setTestNow();
});

test('savings summary marks the emergency fund unavailable when no account is flagged', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 50000]);

    $result = app(TrajectoryReportService::class)->savingsSummary($user);

    expect($result['emergencyFund']['available'])->toBeFalse()
        ->and($result['emergencyFund']['reason'])->toBeString();

    Carbon::setTestNow();
});

test('savings summary computes the monthly contribution each dated goal requires', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();

    Goal::factory()->for($user)->create([
        'name' => 'Japan trip', 'target' => 100000, 'saved' => 25000,
        'per_month' => 10000, 'by_date' => '2026-12-31',
    ]);
    Goal::factory()->for($user)->create([
        'name' => 'Someday', 'target' => 50000, 'saved' => 1000, 'per_month' => 2000, 'by_date' => null,
    ]);
    Goal::factory()->for($user)->create(['name' => 'Done', 'target' => 1000, 'saved' => 1000, 'per_month' => 0]);

    $goals = app(TrajectoryReportService::class)->savingsSummary($user)['goals'];

    expect($goals)->toHaveCount(2);

    $japan = collect($goals)->firstWhere('name', 'Japan trip');
    $someday = collect($goals)->firstWhere('name', 'Someday');

    // 2026-08-05 -> 2026-12-31 is 148 days; ceil(148 / 30.4375) = 5 months.
    expect($japan['monthsRemaining'])->toBe(5)
        ->and($japan['requiredMonthly'])->toBe(15000.0)
        ->and($japan['onTrack'])->toBeFalse()
        ->and($japan['progressPct'])->toBe(25.0)
        ->and($someday['requiredMonthly'])->toBeNull()
        ->and($someday['onTrack'])->toBeNull();

    Carbon::setTestNow();
});

test('savings summary reports the lowest cash balance reached each month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 6000]);
    $investment = Account::factory()->investment()->for($user)->create(['balance' => 500000]);

    foreach ([['2026-07-10', 5000], ['2026-07-20', 3000], ['2026-08-01', 8000], ['2026-08-05', 6000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $cash->id, 'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    AccountBalanceSnapshot::create([
        'user_id' => $user->id, 'account_id' => $investment->id, 'balance' => 500000, 'snapshot_date' => '2026-08-05',
    ]);

    $buffer = app(TrajectoryReportService::class)->savingsSummary($user)['cashBuffer'];

    expect($buffer['available'])->toBeTrue()
        ->and($buffer['trend'])->toBe([
            ['month' => '2026-07', 'label' => 'Jul 2026', 'value' => 3000.0],
            ['month' => '2026-08', 'label' => 'Aug 2026', 'value' => 6000.0],
        ]);

    Carbon::setTestNow();
});

test('savings summary is unavailable with no emergency fund, goals or snapshots', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();

    $result = app(TrajectoryReportService::class)->savingsSummary($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString()
        ->and($result['goals'])->toBe([]);

    Carbon::setTestNow();
});

test('investments summary reports value, cost basis and allocation per vehicle', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'investment_monthly_contribution_target' => 15000,
    ]);

    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 100000]);
    $indexFund = Account::factory()->investment()->for($user)->create(['name' => 'Index Fund', 'balance' => 300000, 'cost_basis' => 250000]);
    Account::factory()->investment()->for($user)->create(['name' => 'Bonds', 'balance' => 200000, 'cost_basis' => 180000]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $cash->id, 'to_account_id' => $indexFund->id,
        'type' => 'transfer', 'amount' => 20000, 'transacted_at' => '2026-08-03',
    ]);

    $result = app(TrajectoryReportService::class)->investmentsSummary($user);

    expect($result['available'])->toBeTrue()
        ->and($result['totalValue'])->toBe(500000.0)
        ->and($result['totalCostBasis'])->toBe(430000.0)
        ->and($result['costBasisComplete'])->toBeTrue()
        ->and($result['gainLoss'])->toBe(70000.0)
        ->and($result['gainLossPct'])->toBe(16.3)
        ->and($result['contributionsThisMonth'])->toBe(20000.0)
        ->and($result['contributionTarget'])->toBe(15000.0)
        ->and($result['contributionProgressPct'])->toBe(133.3)
        ->and($result['vehicles'])->toHaveCount(2)
        ->and(collect($result['vehicles'])->firstWhere('name', 'Index Fund')['allocationPct'])->toBe(60.0)
        ->and(collect($result['vehicles'])->firstWhere('name', 'Bonds')['allocationPct'])->toBe(40.0);

    Carbon::setTestNow();
});

test('investments summary withholds gain when any cost basis is missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->investment()->for($user)->create(['balance' => 300000, 'cost_basis' => 250000]);
    Account::factory()->investment()->for($user)->create(['balance' => 200000, 'cost_basis' => null]);

    $result = app(TrajectoryReportService::class)->investmentsSummary($user);

    expect($result['costBasisComplete'])->toBeFalse()
        ->and($result['gainLoss'])->toBeNull()
        ->and($result['gainLossPct'])->toBeNull()
        ->and($result['totalValue'])->toBe(500000.0);

    Carbon::setTestNow();
});

test('fire progress projects years to financial independence', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'fi_number' => 1000000, 'fi_expected_return_rate' => 0,
    ]);

    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 100000]);
    $fund = Account::factory()->investment()->for($user)->create(['balance' => 500000, 'cost_basis' => 500000]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $cash->id, 'to_account_id' => $fund->id,
        'type' => 'transfer', 'amount' => 20000, 'transacted_at' => '2026-08-03',
    ]);

    $fire = app(TrajectoryReportService::class)->investmentsSummary($user)['fire'];

    // 20000 over the trailing 3 months = 6666.67/month = 80000.04/year.
    // 500000 + 80000.04t >= 1,000,000 -> t = 7.
    expect($fire['available'])->toBeTrue()
        ->and($fire['fiNumber'])->toBe(1000000.0)
        ->and($fire['progressPct'])->toBe(50.0)
        ->and($fire['avgMonthlyContribution'])->toBe(6666.67)
        ->and($fire['yearsToFi'])->toBe(7);

    Carbon::setTestNow();
});

test('fire progress projects years to financial independence with compounding returns', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'fi_number' => 150000, 'fi_expected_return_rate' => 6,
    ]);
    Account::factory()->investment()->for($user)->create(['balance' => 100000, 'cost_basis' => 100000]);

    $fire = app(TrajectoryReportService::class)->investmentsSummary($user)['fire'];

    // No transfers into the investment account in the trailing 3 months, so the
    // average monthly contribution — and therefore the annuity contribution C — is 0.
    // FV(t) reduces to pure compounding: FV(t) = P(1+r)^t = 100000 * 1.06^t.
    // 1.06^6 = 1.418519112256      -> FV(6) = 141,851.91 <  150,000.
    // 1.06^7 = 1.50363025899136    -> FV(7) = 150,363.03 >= 150,000.
    // First year the target is reached is t = 7.
    expect($fire['available'])->toBeTrue()
        ->and($fire['fiNumber'])->toBe(150000.0)
        ->and($fire['expectedReturnRate'])->toBe(6.0)
        ->and($fire['avgMonthlyContribution'])->toBe(0.0)
        ->and($fire['progressPct'])->toBe(66.7)
        ->and($fire['yearsToFi'])->toBe(7);

    Carbon::setTestNow();
});

test('fire progress returns zero years when already at the fi number', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'fi_number' => 500000, 'fi_expected_return_rate' => 0,
    ]);
    Account::factory()->investment()->for($user)->create(['balance' => 500000, 'cost_basis' => 500000]);

    $fire = app(TrajectoryReportService::class)->investmentsSummary($user)['fire'];

    expect($fire['available'])->toBeTrue()
        ->and($fire['fiNumber'])->toBe(500000.0)
        ->and($fire['progressPct'])->toBe(100.0)
        ->and($fire['yearsToFi'])->toBe(0);

    Carbon::setTestNow();
});

test('fire progress is null years away when contributions never reach the target', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'fi_number' => 100000000, 'fi_expected_return_rate' => 0,
    ]);
    Account::factory()->investment()->for($user)->create(['balance' => 1000, 'cost_basis' => 1000]);

    $fire = app(TrajectoryReportService::class)->investmentsSummary($user)['fire'];

    expect($fire['available'])->toBeTrue()
        ->and($fire['yearsToFi'])->toBeNull();

    Carbon::setTestNow();
});

test('fire progress is unavailable without a configured fi number', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->investment()->for($user)->create(['balance' => 1000, 'cost_basis' => 1000]);

    $fire = app(TrajectoryReportService::class)->investmentsSummary($user)['fire'];

    expect($fire['available'])->toBeFalse()
        ->and($fire['reason'])->toBeString();

    Carbon::setTestNow();
});

test('investments summary is unavailable with no investment accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['type' => 'cash']);

    $result = app(TrajectoryReportService::class)->investmentsSummary($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();
});

test('monthly flow reports income, expenses and budget usage for the calendar month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $categories = collect([
        ['name' => 'Food', 'limit_amount' => 5000, 'spend' => 7500],
        ['name' => 'Transport', 'limit_amount' => 2000, 'spend' => 4000],
        ['name' => 'Fun', 'limit_amount' => 1000, 'spend' => 3000],
        ['name' => 'Misc', 'limit_amount' => 500, 'spend' => 625],
        ['name' => 'Unbudgeted', 'limit_amount' => 0, 'spend' => 900],
    ])->map(function (array $row) use ($user, $account) {
        $category = BudgetCategory::factory()->for($user)->create([
            'name' => $row['name'], 'limit_amount' => $row['limit_amount'],
        ]);

        \App\Models\Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'budget_category_id' => $category->id,
            'type' => 'expense', 'amount' => $row['spend'], 'transacted_at' => '2026-08-03',
        ]);

        return $category;
    });

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'income', 'amount' => 50000, 'transacted_at' => '2026-08-01',
    ]);

    $result = app(TrajectoryReportService::class)->monthlyFlow($user);

    expect($result['available'])->toBeTrue()
        ->and($result['monthLabel'])->toBe('August 2026')
        ->and($result['income'])->toBe(50000.0)
        ->and($result['expenses'])->toBe(16025.0)
        ->and($result['net'])->toBe(33975.0)
        ->and($result['budgetTotal'])->toBe(8500.0)
        ->and($result['budgetUsedPct'])->toBe(188.5)
        ->and(array_column($result['overBudget']['top'], 'name'))->toBe(['Fun', 'Transport', 'Food'])
        ->and($result['overBudget']['top'][0]['overPct'])->toBe(200.0)
        ->and($result['overBudget']['top'][0]['overAmount'])->toBe(2000.0)
        ->and($result['overBudget']['remainingCount'])->toBe(1)
        ->and($result['overBudget']['remainingTotal'])->toBe(125.0)
        ->and($categories)->toHaveCount(5);

    Carbon::setTestNow();
});

test('monthly flow splits spending into fixed and variable', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 5000, 'interest_rate' => null]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 16025, 'transacted_at' => '2026-08-03',
    ]);

    RecurringBill::factory()->for($user)->create(['amount' => 2000, 'last_paid_at' => '2026-08-01']);
    RecurringBill::factory()->for($user)->create(['amount' => 999, 'last_paid_at' => '2026-07-01']);

    DebtPayment::factory()->create([
        'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id,
        'amount' => 5000, 'principal_amount' => 5000, 'interest_amount' => 0, 'paid_at' => '2026-08-02',
    ]);

    $split = app(TrajectoryReportService::class)->monthlyFlow($user)['fixedVsVariable'];

    expect($split['fixed'])->toBe(7000.0)
        ->and($split['variable'])->toBe(9025.0)
        ->and($split['fixedPct'])->toBe(43.7);

    Carbon::setTestNow();
});

test('monthly flow is unavailable with no activity and no budget', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();

    $result = app(TrajectoryReportService::class)->monthlyFlow($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();

    Carbon::setTestNow();
});

test('signature metric nets investment gain against debt interest each month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 50000]);
    $fund = Account::factory()->investment()->for($user)->create(['balance' => 112000, 'cost_basis' => 100000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 5000, 'interest_rate' => null]);

    foreach ([['2026-07-01', 100000], ['2026-07-31', 110000], ['2026-08-01', 110000], ['2026-08-05', 112000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $fund->id, 'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $cash->id, 'to_account_id' => $fund->id,
        'type' => 'transfer', 'amount' => 4000, 'transacted_at' => '2026-07-15',
    ]);

    foreach ([['2026-07-05', 1000], ['2026-08-02', 3000]] as [$date, $interest]) {
        DebtPayment::factory()->create([
            'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $cash->id,
            'amount' => 5000 + $interest, 'principal_amount' => 5000, 'interest_amount' => $interest,
            'paid_at' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->signatureMetric($user);

    expect($result['available'])->toBeTrue()
        ->and($result['trend'])->toHaveCount(2)
        ->and($result['trend'][0])->toBe([
            'month' => '2026-07', 'label' => 'Jul 2026',
            'value' => 5000.0, 'investmentGain' => 6000.0, 'debtInterest' => 1000.0,
        ])
        ->and($result['trend'][1]['value'])->toBe(-1000.0)
        ->and($result['currentValue'])->toBe(-1000.0)
        ->and($result['crossedZeroMonth'])->toBeNull();

    Carbon::setTestNow();
});

test('signature metric reports the month it first crossed above zero', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $cash = Account::factory()->for($user)->create(['type' => 'cash']);
    $fund = Account::factory()->investment()->for($user)->create(['balance' => 110000, 'cost_basis' => 100000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 5000, 'interest_rate' => null]);

    foreach ([['2026-06-01', 100000], ['2026-06-30', 100500], ['2026-07-01', 100500], ['2026-07-31', 110000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $fund->id, 'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    foreach ([['2026-06-05', 2000], ['2026-07-05', 1000]] as [$date, $interest]) {
        DebtPayment::factory()->create([
            'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $cash->id,
            'amount' => 5000 + $interest, 'principal_amount' => 5000, 'interest_amount' => $interest,
            'paid_at' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->signatureMetric($user);

    expect($result['trend'][0]['value'])->toBe(-1500.0)
        ->and($result['trend'][1]['value'])->toBe(8500.0)
        ->and($result['crossedZeroMonth'])->toBe('2026-07')
        ->and($result['crossedZeroLabel'])->toBe('Jul 2026');

    Carbon::setTestNow();
});

test('signature metric is unavailable without investment snapshots', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->investment()->for($user)->create(['balance' => 1000, 'cost_basis' => 1000]);

    $result = app(TrajectoryReportService::class)->signatureMetric($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();

    Carbon::setTestNow();
});

test('guest is redirected from the trajectory page', function () {
    $this->get(route('trajectory'))->assertRedirect(route('login'));
});

test('trajectory page renders every report section', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 10000]);
    Debt::factory()->for($user)->create(['balance' => 5000, 'per_month' => 1000, 'interest_rate' => null]);

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->component('Trajectory')
            ->where('netWorth.current', 5000)
            ->where('debt.available', true)
            ->where('extraMonthly', 0)
            ->has('savingsRate')
            ->has('savings')
            ->has('investments')
            ->has('monthlyFlow')
            ->has('signature')
        );

    Carbon::setTestNow();
});

test('trajectory page applies an extra monthly payment to the debt projection', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Debt::factory()->for($user)->create(['balance' => 10000, 'per_month' => 1000, 'interest_rate' => null]);

    $this->actingAs($user)
        ->get(route('trajectory', ['extra_monthly' => 1000]))
        ->assertInertia(fn ($page) => $page
            ->where('extraMonthly', 1000)
            ->where('debt.debtFreeMonths', 5)
            ->where('debt.baselineDebtFreeMonths', 10)
            ->where('debt.monthsSaved', 5)
        );

    Carbon::setTestNow();
});

test('trajectory page rejects a negative extra monthly payment', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('trajectory', ['extra_monthly' => -50]))
        ->assertSessionHasErrors('extra_monthly');
});

test('trajectory page passes a renderable net worth trend to the front end', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $account = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 10000]);

    foreach ([['2026-07-31', 8000], ['2026-08-05', 10000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->component('Trajectory')
            ->where('netWorth.available', true)
            ->has('netWorth.trend', 2)
            ->where('netWorth.trend.0.label', 'Jul 2026')
            // Inertia's assertInertia round-trips props through json_encode/json_decode
            // (AssertableInertia::fromTestResponse), and PHP's default JSON encoding
            // collapses whole-number floats (25.0 -> "25"), which decodes back as an
            // int. assertSame() is strict, so asserting the float 25.0 here would fail
            // on type alone even though the computed value is correct. Assert the int
            // that the wire format actually delivers.
            ->where('netWorth.changePct', 25)
        );

    Carbon::setTestNow();
});

test('trajectory page exposes every field the debt section renders', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create(['name' => 'Card', 'balance' => 10000, 'per_month' => 1000, 'interest_rate' => 18]);

    DebtPayment::factory()->create([
        'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id,
        'amount' => 1000, 'principal_amount' => 850, 'interest_amount' => 150, 'paid_at' => '2026-08-02',
    ]);

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->where('debt.available', true)
            ->where('debt.debts.0.name', 'Card')
            ->where('debt.debts.0.interestRate', 18)
            ->where('debt.interestThisMonth', 150)
            ->where('debt.interestYearToDate', 150)
            ->has('debt.principalInterestTrend', 12)
            ->has('debt.debtToIncome.trend', 6)
            ->has('debt.debtFreeDate')
        );

    Carbon::setTestNow();
});

test('trajectory page exposes every field the savings and investment sections render', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'fi_number' => 25000000, 'fi_expected_return_rate' => 6,
        'emergency_fund_target_months' => 6, 'investment_monthly_contribution_target' => 15000,
    ]);

    Account::factory()->emergencyFund()->for($user)->create(['balance' => 90000]);
    Account::factory()->investment()->for($user)->create(['name' => 'Index Fund', 'balance' => 300000, 'cost_basis' => 250000]);
    Goal::factory()->for($user)->create(['name' => 'Japan trip', 'target' => 100000, 'saved' => 25000, 'per_month' => 10000, 'by_date' => '2026-12-31']);

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->where('savings.emergencyFund.available', true)
            ->where('savings.emergencyFund.targetMonths', 6)
            ->where('savings.goals.0.name', 'Japan trip')
            ->where('savings.goals.0.requiredMonthly', 15000)
            ->where('savings.cashBuffer.available', false)
            ->where('investments.available', true)
            ->where('investments.vehicles.0.name', 'Index Fund')
            ->where('investments.vehicles.0.allocationPct', 100)
            ->where('investments.contributionTarget', 15000)
            ->where('investments.fire.available', true)
            ->where('investments.fire.fiNumber', 25000000)
        );

    Carbon::setTestNow();
});

test('trajectory page exposes every field the flow and signature sections render', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $account = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 40000]);
    $category = BudgetCategory::factory()->for($user)->create(['name' => 'Food', 'limit_amount' => 5000]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'income', 'amount' => 50000, 'transacted_at' => '2026-08-01',
    ]);
    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'budget_category_id' => $category->id,
        'type' => 'expense', 'amount' => 7500, 'transacted_at' => '2026-08-03',
    ]);

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->where('savingsRate.available', true)
            // Whole-number floats collapse to ints across the JSON round-trip (see the
            // note on the netWorth.changePct assertion above) — assert the int 85 that
            // the wire format actually delivers, not the float 85.0.
            ->where('savingsRate.rate', 85)
            ->has('savingsRate.trend', 6)
            ->where('monthlyFlow.available', true)
            ->where('monthlyFlow.monthLabel', 'August 2026')
            ->where('monthlyFlow.overBudget.top.0.name', 'Food')
            ->where('monthlyFlow.fixedVsVariable.variable', 7500)
            ->where('signature.available', false)
        );

    Carbon::setTestNow();
});
