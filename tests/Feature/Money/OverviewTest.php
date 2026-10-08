<?php

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

/**
 * A hand-calculated October 2026:
 *  income      30,000 monthly + 10,000 paid twice  = 50,000.00
 *  expenses    1,500 + 2,500 monthly bills         =  4,000.00
 *  set-aside   6,000 / 12 + ceil(1,000 / 12)       =    583.34
 *  debt        loan billing 5,000 this month       =  5,000.00
 *  safe        50,000 - 4,000 - 583.34 - 5,000     = 40,416.66
 *  per day     17 days left (15th to 31st)         =  2,377.45
 */
function seedTestMonth(User $user): void
{
    IncomeSource::factory()->for($user)->create(['amount' => 3_000_000, 'pay_day_1' => 30]);
    IncomeSource::factory()->for($user)->semiMonthly(15, 30)->create(['amount' => 1_000_000]);

    Bill::factory()->for($user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
    Bill::factory()->for($user)->create(['name' => 'Internet', 'amount' => 250_000, 'due_day' => 5]);
    Bill::factory()->for($user)->annual(3)->create(['name' => 'Insurance', 'amount' => 600_000]);
    Bill::factory()->for($user)->annual(10)->create(['name' => 'Domain', 'amount' => 100_000, 'due_day' => 28]);

    $loan = Debt::factory()->for($user)->create(['name' => 'Bank loan', 'due_day' => 20]);
    PaymentPlan::factory()->for($loan)->create(['principal' => 3_000_000, 'monthly_amount' => 500_000, 'total_months' => 6, 'start_month' => '2026-10-01']);
}

test('the five numbers match a hand calculation for a test month', function (): void {
    seedTestMonth($this->user);

    $this->actingAs($this->user)
        ->get('/money')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Money/Overview')
            ->where('numbers.income', 5_000_000)
            ->where('numbers.expenses', 400_000)
            ->where('numbers.setAside', 58_334)
            ->where('numbers.debtPayment', 500_000)
            ->where('numbers.safeToSpend', 4_041_666)
            ->where('numbers.perDay', 237_745));
});

test('safe to spend can go negative but the daily amount never does', function (): void {
    Bill::factory()->for($this->user)->create(['amount' => 100_000]);

    $overview = app(Projection::class)->overview(collect(), $this->user->bills()->get(), collect(), Carbon::today());

    expect($overview['safeToSpend'])->toBe(-100_000)->and($overview['perDay'])->toBe(0);
});

test('the buffer contribution comes off safe to spend', function (): void {
    IncomeSource::factory()->for($this->user)->create(['amount' => 1_000_000]);

    $overview = app(Projection::class)->overview(collect(), collect(), $this->user->incomeSources()->get(), Carbon::today(), 250_000);

    expect($overview['safeToSpend'])->toBe(750_000);
});

test('upcoming leaves out dues beyond the window and past pay days', function (): void {
    seedTestMonth($this->user);

    $names = collect(app(Projection::class)->upcoming(
        $this->user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get(),
        $this->user->bills()->get(),
        $this->user->incomeSources()->get(),
        Carbon::today(),
    ))->map(fn (array $item): string => $item['date'].' '.$item['kind'].' '.$item['name']);

    expect($names->all())->toContain('2026-10-20 debt Bank loan', '2026-10-25 bill Rent', '2026-10-28 bill Domain')
        ->and($names->contains(fn (string $line): bool => str_contains($line, 'Internet') || str_contains($line, 'Insurance')))->toBeFalse()
        ->and($names->filter(fn (string $line): bool => str_contains($line, 'income'))->all())->toHaveCount(1)
        ->and($names->first())->toStartWith('2026-10-15 income');
});

test('an annual bill whose date passed rolls to next year', function (): void {
    $bill = Bill::factory()->for($this->user)->annual(3)->create(['due_day' => 10]);

    expect(app(Projection::class)->nextBillDate($bill, Carbon::today())->toDateString())->toBe('2027-03-10');
});

test('a quarterly bill repeats every three months from its first month', function (): void {
    $bill = Bill::factory()->for($this->user)->quarterly(2)->create(['due_day' => 10]);
    $projection = app(Projection::class);

    expect($projection->nextBillDate($bill, Carbon::today())->toDateString())->toBe('2026-11-10')
        ->and($projection->nextBillDate($bill, Carbon::parse('2026-11-11'))->toDateString())->toBe('2027-02-10');

    $dates = collect($projection->upcoming(collect(), collect([$bill]), collect(), Carbon::today(), 120))->pluck('date');
    expect($dates->all())->toBe(['2026-11-10', '2027-02-10']);
});

test('a quarterly bill is set aside at a third a month', function (): void {
    $bills = collect([Bill::factory()->for($this->user)->quarterly(1)->create(['amount' => 100_000])]);

    expect(app(Projection::class)->annualSetAside($bills))->toBe(33_334);
});

test('a bill on day 31 lands on the last day of a short month', function (): void {
    $bill = Bill::factory()->for($this->user)->create(['due_day' => 31]);

    expect(app(Projection::class)->nextBillDate($bill, Carbon::parse('2026-11-05'))->toDateString())->toBe('2026-11-30');
});

test('the overview only counts my own data', function (): void {
    Bill::factory()->create(['amount' => 999_900]);
    IncomeSource::factory()->create(['amount' => 999_900]);

    $this->actingAs($this->user)->get('/money')->assertInertia(fn ($page) => $page
        ->where('numbers.income', 0)
        ->where('numbers.expenses', 0)
        ->has('bills', 0)
        ->has('incomeSources', 0));
});
