<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\Debt;
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
});

/** A loan billing 5,000 on the 20th and rent of 1,500 on the 25th. */
function seedDues(User $user): void
{
    $loan = Debt::factory()->for($user)->create(['name' => 'Bank loan', 'due_day' => 20]);
    PaymentPlan::factory()->for($loan)->create(['principal' => 3_000_000, 'monthly_amount' => 500_000, 'total_months' => 6, 'start_month' => '2026-10-01']);
    Bill::factory()->for($user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
}

function shortfalls(User $user, int $days = 30): array
{
    return app(Projection::class)->shortfalls(
        $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get(),
        $user->bills()->get(),
        $user->incomeSources()->get(),
        $user->accounts()->get(),
        Carbon::today(),
        $days,
    );
}

test('only the part that cannot be covered is short', function (): void {
    seedDues($this->user);
    Account::factory()->for($this->user)->create(['balance' => 600_000]);

    $result = shortfalls($this->user, 14);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toMatchArray(['name' => 'Rent', 'date' => '2026-10-25', 'short' => 50_000]);
});

test('nothing is flagged when the cash covers every due', function (): void {
    seedDues($this->user);
    Account::factory()->for($this->user)->create(['balance' => 650_000]);

    expect(shortfalls($this->user, 14))->toBe([]);
});

test('a payday before a due covers it', function (): void {
    seedDues($this->user);
    Account::factory()->for($this->user)->create(['balance' => 100_000]);
    IncomeSource::factory()->for($this->user)->create(['amount' => 1_000_000, 'pay_day_1' => 18]);

    expect(shortfalls($this->user, 14))->toBe([]);
});

test('a payday after a due does not help it or any due before the payday', function (): void {
    seedDues($this->user);
    Account::factory()->for($this->user)->create(['balance' => 400_000]);
    IncomeSource::factory()->for($this->user)->create(['amount' => 1_000_000, 'pay_day_1' => 28]);

    $result = shortfalls($this->user, 14);

    expect(array_column($result, 'name'))->toBe(['Bank loan', 'Rent'])
        ->and(array_column($result, 'short'))->toBe([100_000, 150_000]);
});

test('a payday on the due date counts first', function (): void {
    seedDues($this->user);
    Account::factory()->for($this->user)->create(['balance' => 0]);
    IncomeSource::factory()->for($this->user)->create(['amount' => 650_000, 'pay_day_1' => 20]);

    expect(shortfalls($this->user, 14))->toBe([]);
});

test('once the balance is negative later dues are short in full', function (): void {
    seedDues($this->user);
    Account::factory()->for($this->user)->create(['balance' => 100_000]);

    $result = shortfalls($this->user, 14);

    expect(array_column($result, 'short'))->toBe([400_000, 150_000]);
});

test('a bill due early next month is checked when it falls in the window', function (): void {
    Bill::factory()->for($this->user)->create(['name' => 'Gym', 'amount' => 100_000, 'due_day' => 10]);
    Account::factory()->for($this->user)->create(['balance' => 50_000]);

    expect(shortfalls($this->user, 14))->toBe([])
        ->and(shortfalls($this->user))->toBe([
            ['kind' => 'bill', 'name' => 'Gym', 'amount' => 100_000, 'date' => '2026-11-10', 'short' => 50_000],
        ]);
});

test('accounts kept aside are not counted as cash', function (): void {
    seedDues($this->user);
    Account::factory()->for($this->user)->create(['balance' => 100_000]);
    Account::factory()->for($this->user)->notSpendable()->create(['balance' => 9_000_000]);

    expect(shortfalls($this->user, 14))->toHaveCount(2);
});

test('without any account nothing is flagged', function (): void {
    seedDues($this->user);

    expect(shortfalls($this->user))->toBe([]);
});

test('the overview passes the shortfalls to the page', function (): void {
    seedDues($this->user);
    Account::factory()->for($this->user)->create(['balance' => 600_000]);

    $this->actingAs($this->user)->get('/money')->assertInertia(fn ($page) => $page
        ->where('shortfalls.0.name', 'Rent')
        ->where('shortfalls.0.short', 50_000));
});
