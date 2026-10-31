<?php

use App\Models\Money\Bill;
use App\Models\Money\CustomCategory;
use App\Models\Money\IncomeSource;
use App\Models\Money\SpendingLimit;
use App\Models\Money\Transaction;
use App\Models\Money\TransactionCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Inertia\Testing\AssertableInertia as Assert;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

/** Safe to spend as the Overview works it out. */
function overviewSafeToSpend(User $user): int
{
    $safe = null;

    test()->actingAs($user)->get('/money')->assertInertia(function (Assert $page) use (&$safe): void {
        $safe = $page->toArray()['props']['numbers']['safeToSpend'];
    });

    return $safe;
}

test('the plan lists every expense category with its limit and spending', function (): void {
    CustomCategory::factory()->for($this->user)->create(['name' => 'Coffee']);
    SpendingLimit::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 800_000]);
    Transaction::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 700_000, 'date' => '2026-10-02']);

    $this->actingAs($this->user)->get('/money/plan')->assertInertia(fn (Assert $page) => $page
        ->component('Money/Plan')
        ->has('spendingLimits', count(TransactionCategory::expenseValues()) + 1)
        ->where('spendingLimits.1', [
            'category' => 'groceries', 'customCategoryId' => null, 'categoryName' => null,
            'limit' => 800_000, 'spent' => 700_000, 'left' => 100_000, 'pace' => 387_096,
            'status' => 'close', 'aheadOfPace' => true,
        ])
        ->where('spendingLimits.'.count(TransactionCategory::expenseValues()).'.categoryName', 'Coffee'));
});

test('the plan notes when limits add up to more than safe to spend', function (): void {
    IncomeSource::factory()->for($this->user)->create(['amount' => 3_000_000, 'pay_day_1' => 30]);
    Bill::factory()->for($this->user)->create(['amount' => 1_000_000, 'due_day' => 5]);
    $safe = overviewSafeToSpend($this->user);
    SpendingLimit::factory()->for($this->user)->create(['category' => 'food', 'amount' => $safe]);
    $transport = SpendingLimit::factory()->for($this->user)->create(['category' => 'transport', 'amount' => 500_000]);

    $this->actingAs($this->user)->get('/money/plan')->assertInertia(fn (Assert $page) => $page
        ->where('limitsOverSafeToSpend', 500_000));

    $transport->delete();

    $this->actingAs($this->user)->get('/money/plan')->assertInertia(fn (Assert $page) => $page
        ->where('limitsOverSafeToSpend', 0));
});

test('the overview lists only limits that are close or over, over first', function (): void {
    foreach (['food' => 100_000, 'groceries' => 500_000, 'transport' => 200_000, 'home' => 300_000] as $category => $limit) {
        SpendingLimit::factory()->for($this->user)->create(['category' => $category, 'amount' => $limit]);
    }
    Transaction::factory()->for($this->user)->create(['category' => 'food', 'amount' => 90_000, 'date' => '2026-10-02']);
    Transaction::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 100_000, 'date' => '2026-10-02']);
    Transaction::factory()->for($this->user)->create(['category' => 'transport', 'amount' => 250_000, 'date' => '2026-10-02']);
    Transaction::factory()->for($this->user)->create(['category' => 'shopping', 'amount' => 999_000, 'date' => '2026-10-02']);

    $this->actingAs($this->user)->get('/money')->assertInertia(fn (Assert $page) => $page
        ->component('Money/Overview')
        ->has('limitAlerts', 2)
        ->where('limitAlerts.0.category', 'transport')
        ->where('limitAlerts.0.status', 'over')
        ->where('limitAlerts.1.category', 'food')
        ->where('limitAlerts.1.status', 'close'));
});
