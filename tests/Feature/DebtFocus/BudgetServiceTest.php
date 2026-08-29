<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\User;
use App\Services\DebtFocus\BudgetService;
use App\Services\DebtFocus\LedgerService;
use Carbon\Carbon;

test('periodFor lazily creates a bill period snapshotting the template amount', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 120]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['bills'])->toHaveCount(1);
    expect((float) $period['bills']->first()->expected_amount)->toBe(120.0);
    expect(BillPeriod::where('bill_id', $bill->id)->where('year', 2026)->where('month', 8)->exists())->toBeTrue();
});

test('periodFor does not recreate an existing period row', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create();
    $existing = BillPeriod::factory()->for($bill)->create(['year' => 2026, 'month' => 8, 'actual_amount' => 50]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['bills']->first()->id)->toBe($existing->id);
    expect((float) $period['bills']->first()->actual_amount)->toBe(50.0);
});

test('periodFor skips inactive bills and envelopes', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['active' => false]);
    Envelope::factory()->for($user)->create(['active' => false]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['bills'])->toHaveCount(0);
    expect($period['envelopes'])->toHaveCount(0);
});

test('a template edit does not change an already-created period snapshot', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 100]);
    (new BudgetService)->periodFor($user, 2026, 8);

    $bill->update(['expected_amount' => 200]);
    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect((float) $period['bills']->first()->expected_amount)->toBe(100.0);
});

test('spentForPeriod sums expense transactions tagged to the envelope within the period', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create();
    $period = EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 8]);
    $ledger = new LedgerService;
    $ledger->recordExpense($account, 30, 'Groceries', now()->setDate(2026, 8, 5), $envelope->id);
    $ledger->recordExpense($account, 20, 'Snacks', now()->setDate(2026, 8, 15), $envelope->id);
    $ledger->recordExpense($account, 999, 'Other month', now()->setDate(2026, 7, 15), $envelope->id);

    $spent = (new BudgetService)->spentForPeriod($period);

    expect($spent)->toBe(50.0);
});

test('spentForPeriod includes an expense dated the last day of the month', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create();
    $period = EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 8]);
    (new LedgerService)->recordExpense($account, 25, 'Last day', Carbon::create(2026, 8, 31), $envelope->id);

    $spent = (new BudgetService)->spentForPeriod($period);

    expect($spent)->toBe(25.0);
});

test('opening a new month offers a sweep for an underspent, unoffered prior-month envelope', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 100]);
    $priorPeriod = EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 7, 'target' => 100]);
    (new LedgerService)->recordExpense($account, 40, 'Groceries', now()->setDate(2026, 7, 10), $envelope->id);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['sweepCandidates'])->toHaveCount(1);
    expect($period['sweepCandidates']->first()->id)->toBe($priorPeriod->id);
    expect($priorPeriod->fresh()->sweep_offered_at)->not->toBeNull();
});

test('an envelope that was fully spent is not offered as a sweep candidate', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 7, 'target' => 100]);
    (new LedgerService)->recordExpense($account, 100, 'All of it', now()->setDate(2026, 7, 10), $envelope->id);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['sweepCandidates'])->toHaveCount(0);
});

test('an already-offered but still-unanswered sweep keeps appearing on later visits', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 7, 'target' => 100, 'sweep_offered_at' => now()]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['sweepCandidates'])->toHaveCount(1);
});

test('revisiting the same month does not re-run sweep detection or re-stamp the offer', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $priorPeriod = EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 7, 'target' => 100]);
    (new LedgerService)->recordExpense($account, 40, 'Groceries', now()->setDate(2026, 7, 10), $envelope->id);

    $first = (new BudgetService)->periodFor($user, 2026, 8);
    $firstOfferedAt = $priorPeriod->fresh()->sweep_offered_at;

    $second = (new BudgetService)->periodFor($user, 2026, 8);
    $secondOfferedAt = $priorPeriod->fresh()->sweep_offered_at;

    expect($first['sweepCandidates'])->toHaveCount(1);
    expect($second['sweepCandidates'])->toHaveCount(1);
    expect($secondOfferedAt->equalTo($firstOfferedAt))->toBeTrue();
});

test('a sweep candidate stops appearing once it has been answered', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    EnvelopePeriod::factory()->for($envelope)->create([
        'year' => 2026, 'month' => 7, 'target' => 100,
        'sweep_offered_at' => now(), 'sweep_accepted' => false,
    ]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['sweepCandidates'])->toHaveCount(0);
});

test('periodBounds returns the first and last date of the given month', function () {
    [$start, $end] = (new BudgetService)->periodBounds(2026, 2);

    expect($start)->toBe('2026-02-01');
    expect($end)->toBe('2026-02-28');
});
