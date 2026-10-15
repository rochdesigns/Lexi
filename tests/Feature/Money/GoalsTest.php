<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\Debt;
use App\Models\Money\Goal;
use App\Models\Money\IncomeSource;
use App\Models\Money\PaymentPlan;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
    $this->projection = new Projection;
});

/**
 * Monthly bills 4,000.00, a yearly bill of 1,000.00 set aside at 83.34, and a
 * loan billing 5,000.00 in October and November only: 9,083.34 a month in
 * October and 4,083.34 once the loan has rolled off.
 */
function seedObligations(User $user): void
{
    Bill::factory()->for($user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
    Bill::factory()->for($user)->create(['name' => 'Internet', 'amount' => 250_000, 'due_day' => 5]);
    Bill::factory()->for($user)->annual(3)->create(['name' => 'Domain', 'amount' => 100_000]);
    $loan = Debt::factory()->for($user)->create(['name' => 'Bank loan', 'due_day' => 20]);
    PaymentPlan::factory()->for($loan)->create(['principal' => 1_000_000, 'monthly_amount' => 500_000, 'total_months' => 2, 'start_month' => '2026-10-01']);
}

function obligationsAt(User $user, string $date): int
{
    return (new Projection)->monthlyObligations(
        $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get(),
        $user->bills()->get(),
        Carbon::parse($date),
    );
}

test('a buffer is months of obligations and shrinks as installments roll off', function (): void {
    seedObligations($this->user);
    $buffer = Goal::factory()->for($this->user)->buffer(3)->create();

    expect(obligationsAt($this->user, '2026-10-15'))->toBe(908_334)
        ->and($this->projection->goalTarget($buffer, obligationsAt($this->user, '2026-10-15')))->toBe(2_725_002)
        ->and(obligationsAt($this->user, '2026-12-15'))->toBe(408_334)
        ->and($this->projection->goalTarget($buffer, obligationsAt($this->user, '2026-12-15')))->toBe(1_225_002);
});

test('buffer progress shows the percent, the months to go and the month it is reached', function (): void {
    $buffer = Goal::factory()->for($this->user)->buffer(3)->create(['saved' => 1_000_000, 'monthly_contribution' => 200_000]);

    expect($this->projection->goalProgress($buffer, 2_725_002, Carbon::today()))->toBe([
        'saved' => 1_000_000,
        'target' => 2_725_002,
        'remaining' => 1_725_002,
        'percent' => 36,
        'monthsToGo' => 9,
        'finishMonth' => '2027-06',
        'suggestedContribution' => null,
    ]);
});

test('a goal that has reached its target is done', function (): void {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 1_000_000, 'saved' => 1_500_000, 'target_date' => '2026-12-15']);

    expect($this->projection->goalProgress($goal, 1_000_000, Carbon::today()))->toMatchArray(['remaining' => 0, 'percent' => 100, 'monthsToGo' => 0, 'finishMonth' => null, 'suggestedContribution' => null]);
});

test('with nothing going in there is no finish month', function (): void {
    $goal = Goal::factory()->for($this->user)->create(['target_amount' => 1_000_000, 'monthly_contribution' => 0]);

    expect($this->projection->goalProgress($goal, 1_000_000, Carbon::today()))->toMatchArray(['monthsToGo' => null, 'finishMonth' => null]);
});

test('a sinking fund with a date suggests the monthly amount that finishes it in time', function (): void {
    $suggest = fn (?string $date): ?int => $this->projection->goalProgress(
        Goal::factory()->for($this->user)->create(['target_amount' => 1_200_000, 'saved' => 300_000, 'target_date' => $date]),
        1_200_000,
        Carbon::today(),
    )['suggestedContribution'];

    // 9,000.00 still to save. October and November are the months before a December date.
    expect($suggest('2026-12-15'))->toBe(450_000)
        ->and($suggest('2026-10-30'))->toBe(900_000)
        ->and($suggest('2026-01-01'))->toBe(900_000)
        ->and($suggest('2027-10-01'))->toBe(75_000)
        ->and($suggest(null))->toBeNull();
});

test('a goal linked to an account uses its balance', function (): void {
    $account = Account::factory()->for($this->user)->notSpendable()->create(['balance' => 250_000]);
    $goal = Goal::factory()->for($this->user)->create(['saved' => 5_000_000, 'account_id' => $account->id]);

    expect($this->projection->goalSaved($goal->load('account')))->toBe(250_000);

    $account->update(['balance' => -10_000]);

    expect($this->projection->goalSaved($goal->fresh('account')))->toBe(0);
});

test('contributions are split between the buffer and the funds, and finished goals stop', function (): void {
    seedObligations($this->user);
    Goal::factory()->for($this->user)->buffer(3)->create(['monthly_contribution' => 200_000]);
    Goal::factory()->for($this->user)->create(['monthly_contribution' => 100_000]);
    Goal::factory()->for($this->user)->create(['monthly_contribution' => 50_000, 'target_amount' => 1_000_000, 'saved' => 1_000_000]);

    $totals = fn () => $this->projection->goalContributions(
        $this->user->goals()->with('account')->get(),
        $this->user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get(),
        $this->user->bills()->get(),
        Carbon::today(),
    );

    expect($totals())->toBe(['buffer' => 200_000, 'sinking' => 100_000]);

    $this->user->goals()->where('kind', 'buffer')->update(['saved' => 99_000_000]);

    expect($totals())->toBe(['buffer' => 0, 'sinking' => 100_000]);
});

test('goal contributions come off safe to spend and show on the overview and today', function (): void {
    seedObligations($this->user);
    IncomeSource::factory()->for($this->user)->create(['amount' => 5_000_000]);
    Goal::factory()->for($this->user)->buffer(3)->create(['monthly_contribution' => 200_000]);
    Goal::factory()->for($this->user)->create(['monthly_contribution' => 100_000]);

    // 50,000.00 less 4,000.00 bills, 1,083.34 set aside (83.34 yearly, 1,000.00 funds), 5,000.00 loan and 2,000.00 buffer.
    $this->actingAs($this->user)->get('/money')->assertInertia(fn ($page) => $page
        ->where('numbers.setAside', 108_334)
        ->where('numbers.bufferContribution', 200_000)
        ->where('numbers.safeToSpend', 3_791_666));
    $this->actingAs($this->user->fresh())->get('/today')->assertInertia(fn ($page) => $page->where('moneySummary.safeToSpend', 3_791_666));
});

test('the plan page shows the buffer, the funds, the yearly bills and my accounts', function (): void {
    seedObligations($this->user);
    $account = Account::factory()->for($this->user)->notSpendable()->create(['name' => 'Savings', 'balance' => 500_000]);
    Goal::factory()->for($this->user)->buffer(3)->create(['monthly_contribution' => 200_000, 'account_id' => $account->id]);
    Goal::factory()->for($this->user)->create(['name' => 'Japan trip', 'target_amount' => 1_200_000, 'monthly_contribution' => 100_000, 'target_date' => '2026-12-15']);
    Goal::factory()->create(['name' => 'Someone else']);

    $this->actingAs($this->user)->get('/money/plan')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Money/Plan')
        ->where('obligations', 908_334)
        ->where('obligationBreakdown.bills.total', 400_000)
        ->where('obligationBreakdown.bills.items', [['name' => 'Internet', 'amount' => 250_000], ['name' => 'Rent', 'amount' => 150_000]])
        ->where('obligationBreakdown.yearlyBills.total', 8_334)
        ->where('obligationBreakdown.yearlyBills.items', [['name' => 'Domain', 'amount' => 8_334]])
        ->where('obligationBreakdown.debtPayments.total', 500_000)
        ->has('obligationBreakdown.debtPayments.items', 1)
        ->where('buffer.target', 2_725_002)
        ->where('buffer.saved', 500_000)
        ->where('buffer.accountName', 'Savings')
        ->has('goals', 1)
        ->where('goals.0.name', 'Japan trip')
        ->where('goals.0.suggestedContribution', 600_000)
        ->has('yearlyBills', 1, fn ($bill) => $bill
            ->where('id', Bill::where('name', 'Domain')->value('id'))
            ->where('name', 'Domain')->where('amount', 100_000)->where('frequency', 'annual')
            ->where('dueMonth', 3)->where('monthlySetAside', 8_334)
            ->has('dueDay')->has('autopay')->has('accountId')->has('debtId'))
        ->has('accounts', 1));
});

test('the plan page works with nothing set up', function (): void {
    $this->actingAs($this->user)->get('/money/plan')->assertOk()->assertInertia(fn ($page) => $page
        ->where('buffer', null)->has('goals', 0)->has('yearlyBills', 0)->where('obligations', 0));
});

function bufferPayload(array $overrides = []): array
{
    return ['kind' => 'buffer', 'name' => '', 'monthly_contribution' => 2000, 'saved' => 1500.5, 'buffer_months' => 3, ...$overrides];
}

function fundPayload(array $overrides = []): array
{
    return ['kind' => 'sinking', 'name' => 'Japan trip', 'monthly_contribution' => 1000, 'saved' => 0, 'target_amount' => 12000, 'target_date' => '2026-12-15', ...$overrides];
}

test('I can set up a buffer and it gets a default name', function (): void {
    $this->actingAs($this->user)->post('/money/goals', bufferPayload())->assertRedirect();

    expect($this->user->goals()->sole()->only(['name', 'monthly_contribution', 'saved', 'buffer_months', 'target_amount']))->toBe([
        'name' => 'Emergency buffer', 'monthly_contribution' => 200_000, 'saved' => 150_050, 'buffer_months' => 3, 'target_amount' => null,
    ]);
});

test('there can only be one buffer', function (): void {
    Goal::factory()->for($this->user)->buffer()->create();

    $this->actingAs($this->user)->post('/money/goals', bufferPayload())->assertSessionHasErrors('kind');
    expect($this->user->goals()->count())->toBe(1);
});

test('a buffer needs a number of months from the list and no target amount', function (): void {
    $this->actingAs($this->user)->post('/money/goals', bufferPayload(['buffer_months' => null]))->assertSessionHasErrors('buffer_months');
    $this->actingAs($this->user)->post('/money/goals', bufferPayload(['buffer_months' => 7]))->assertSessionHasErrors('buffer_months');
    $this->actingAs($this->user)->post('/money/goals', bufferPayload(['target_amount' => 100]))->assertSessionHasErrors('target_amount');
    expect($this->user->goals()->count())->toBe(0);
});

test('I can add a sinking fund with a target and a date', function (): void {
    $this->actingAs($this->user)->post('/money/goals', fundPayload())->assertRedirect();

    $goal = $this->user->goals()->sole();
    expect($goal->only(['name', 'target_amount', 'monthly_contribution']))->toBe(['name' => 'Japan trip', 'target_amount' => 1_200_000, 'monthly_contribution' => 100_000])
        ->and($goal->target_date->toDateString())->toBe('2026-12-15');
});

test('a sinking fund needs a name and a target, and the date is optional', function (): void {
    $this->actingAs($this->user)->post('/money/goals', fundPayload(['name' => '']))->assertSessionHasErrors('name');
    $this->actingAs($this->user)->post('/money/goals', fundPayload(['target_amount' => 0]))->assertSessionHasErrors('target_amount');
    $this->actingAs($this->user)->post('/money/goals', fundPayload(['buffer_months' => 3]))->assertSessionHasErrors('buffer_months');
    $this->actingAs($this->user)->post('/money/goals', fundPayload(['target_date' => null]))->assertSessionHasNoErrors();
});

test('linking an account uses its balance and ignores the typed amount', function (): void {
    $account = Account::factory()->for($this->user)->create();

    $this->actingAs($this->user)->post('/money/goals', fundPayload(['saved' => 999, 'account_id' => $account->id]))->assertRedirect();

    expect($this->user->goals()->sole()->only(['saved', 'account_id']))->toBe(['saved' => 0, 'account_id' => $account->id]);
});

test('I can only link my own account', function (): void {
    $theirs = Account::factory()->create();

    $this->actingAs($this->user)->post('/money/goals', fundPayload(['account_id' => $theirs->id]))->assertSessionHasErrors('account_id');
});

test('I can update a goal but not change its kind', function (): void {
    $goal = Goal::factory()->for($this->user)->create();

    $this->actingAs($this->user)->patch("/money/goals/{$goal->id}", fundPayload(['kind' => 'buffer', 'name' => 'Bigger trip', 'target_amount' => 20000]))->assertRedirect();

    expect($goal->fresh()->only(['kind', 'name', 'target_amount']))->toMatchArray(['name' => 'Bigger trip', 'target_amount' => 2_000_000])
        ->and($goal->fresh()->kind->value)->toBe('sinking');
});

test('I can delete a goal', function (): void {
    $goal = Goal::factory()->for($this->user)->create();

    $this->actingAs($this->user)->delete("/money/goals/{$goal->id}")->assertRedirect();

    expect(Goal::count())->toBe(0);
});

test('I cannot change someone else\'s goal', function (): void {
    $goal = Goal::factory()->create();

    $this->actingAs($this->user)->patch("/money/goals/{$goal->id}", fundPayload())->assertForbidden();
    $this->actingAs($this->user)->delete("/money/goals/{$goal->id}")->assertForbidden();
});

test('guests cannot add goals', function (): void {
    $this->post('/money/goals', [])->assertRedirect('/login');
});
