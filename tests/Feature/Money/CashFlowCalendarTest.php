<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\IncomeSource;
use App\Models\Money\InterestType;
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

function calendarFor(User $user, string $month): array
{
    return app(Projection::class)->calendar(
        $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get(),
        $user->bills()->get(),
        $user->incomeSources()->get(),
        $user->accounts()->get(),
        Carbon::today(),
        $month,
    );
}

/** @return array<string, list<string>> names by date */
function namesByDay(array $calendar): array
{
    return collect($calendar['days'])->mapWithKeys(fn (array $day): array => [$day['date'] => array_column($day['items'], 'name')])->all();
}

test('the month shows paydays, bills and debt dues on their days', function (): void {
    IncomeSource::factory()->for($this->user)->semiMonthly(15, 30)->create(['name' => 'Salary', 'amount' => 1_000_000]);
    Bill::factory()->for($this->user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
    $loan = Debt::factory()->for($this->user)->create(['name' => 'Bank loan', 'due_day' => 20]);
    PaymentPlan::factory()->for($loan)->create(['principal' => 3_000_000, 'monthly_amount' => 500_000, 'total_months' => 6, 'start_month' => '2026-10-01']);

    expect(namesByDay(calendarFor($this->user, '2026-10')))->toBe([
        '2026-10-15' => ['Salary'],
        '2026-10-20' => ['Bank loan'],
        '2026-10-25' => ['Rent'],
        '2026-10-30' => ['Salary'],
    ]);
});

test('days before today are empty', function (): void {
    Bill::factory()->for($this->user)->create(['name' => 'Internet', 'due_day' => 5]);

    expect(calendarFor($this->user, '2026-10')['days'])->toBe([])
        ->and(array_keys(namesByDay(calendarFor($this->user, '2026-11'))))->toBe(['2026-11-05']);
});

test('a loan is due every month it still bills', function (): void {
    $loan = Debt::factory()->for($this->user)->create(['name' => 'Bank loan', 'due_day' => 20]);
    PaymentPlan::factory()->for($loan)->create(['principal' => 1_500_000, 'monthly_amount' => 500_000, 'total_months' => 3, 'start_month' => '2026-10-01']);

    $dues = fn (string $month): array => collect(calendarFor($this->user, $month)['days'])->pluck('items.0.amount', 'date')->all();

    expect($dues('2026-10'))->toBe(['2026-10-20' => 500_000])
        ->and($dues('2026-11'))->toBe(['2026-11-20' => 500_000])
        ->and($dues('2026-12'))->toBe(['2026-12-20' => 500_000])
        ->and($dues('2027-01'))->toBe([]);
});

test('a card shows its real minimum first then only the installments it bills', function (): void {
    $card = Debt::factory()->for($this->user)->create(['name' => 'Platinum', 'type' => DebtType::CreditCard, 'interest_type' => InterestType::Revolving, 'monthly_interest_rate' => 3.5, 'due_day' => 28]);
    CardDetail::factory()->for($card)->create(['statement_day' => 10, 'min_payment_percent' => 5, 'min_payment_floor' => 0, 'revolving_balance' => 200_000]);
    PaymentPlan::factory()->for($card)->create(['principal' => 1_200_000, 'monthly_amount' => 100_000, 'total_months' => 12, 'start_month' => '2026-10-01']);

    $dues = fn (string $month): array => collect(calendarFor($this->user, $month)['days'])->pluck('items.0.amount', 'date')->all();

    // October's statement closed on the 10th: 5% of 2,000.00 is the 100.00 minimum.
    expect($dues('2026-10'))->toBe(['2026-10-28' => 10_000])
        ->and($dues('2026-11'))->toBe(['2026-11-28' => 100_000])
        ->and($dues('2026-12'))->toBe(['2026-12-28' => 100_000]);
});

test('a due the cash cannot cover is flagged short with the next payday', function (): void {
    Account::factory()->for($this->user)->create(['balance' => 100_000]);
    Bill::factory()->for($this->user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
    IncomeSource::factory()->for($this->user)->create(['name' => 'Salary', 'amount' => 1_000_000, 'pay_day_1' => 30]);

    $calendar = calendarFor($this->user, '2026-10');

    expect($calendar['gaps'])->toBe([
        ['kind' => 'bill', 'name' => 'Rent', 'amount' => 150_000, 'date' => '2026-10-25', 'short' => 50_000, 'nextPayday' => '2026-10-30'],
    ])->and($calendar['days'][0]['items'][0]['short'])->toBe(50_000);
});

test('a payday before the due closes the gap', function (): void {
    Account::factory()->for($this->user)->create(['balance' => 100_000]);
    Bill::factory()->for($this->user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
    IncomeSource::factory()->for($this->user)->create(['amount' => 1_000_000, 'pay_day_1' => 20]);

    expect(calendarFor($this->user, '2026-10')['gaps'])->toBe([]);
});

test('a gap in one month carries into the next', function (): void {
    Account::factory()->for($this->user)->create(['balance' => 100_000]);
    Bill::factory()->for($this->user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
    IncomeSource::factory()->for($this->user)->create(['name' => 'Salary', 'amount' => 10_000, 'pay_day_1' => 28]);

    // October's rent leaves the balance at -500.00 and the 100.00 payday on the 28th only brings it to -400.00,
    // so November's rent is short in full. Starting November from today's 1,000.00 it would be short by only 500.00.
    $november = calendarFor($this->user, '2026-11')['gaps'];

    expect($november)->toHaveCount(1)
        ->and($november[0])->toMatchArray(['name' => 'Rent', 'date' => '2026-11-25', 'short' => 150_000]);
});

test('without an account nothing is flagged', function (): void {
    Bill::factory()->for($this->user)->create(['amount' => 150_000, 'due_day' => 25]);

    expect(calendarFor($this->user, '2026-10')['gaps'])->toBe([]);
});

test('there is no next payday without income', function (): void {
    Account::factory()->for($this->user)->create(['balance' => 0]);
    Bill::factory()->for($this->user)->create(['amount' => 150_000, 'due_day' => 25]);

    expect(calendarFor($this->user, '2026-10')['gaps'][0]['nextPayday'])->toBeNull();
});

test('the calendar page opens on this month and passes the data', function (): void {
    Bill::factory()->for($this->user)->create(['name' => 'Rent', 'due_day' => 25]);

    $this->actingAs($this->user)->get('/money/calendar')->assertOk()->assertInertia(fn ($page) => $page
        ->component('Money/Calendar')
        ->where('month', '2026-10')
        ->where('minMonth', '2026-10')
        ->where('maxMonth', '2027-09')
        ->where('today', '2026-10-15')
        ->where('hasAccounts', false)
        ->where('days.0.date', '2026-10-25')
        ->where('days.0.items.0.name', 'Rent'));
});

test('the calendar page follows the month in the address within the range', function (): void {
    $month = fn (string $query): string => $this->actingAs($this->user)->get("/money/calendar{$query}")->viewData('page')['props']['month'];

    expect($month('?month=2026-12'))->toBe('2026-12')
        ->and($month('?month=2027-09'))->toBe('2027-09')
        ->and($month('?month=2027-10'))->toBe('2026-10')
        ->and($month('?month=2026-09'))->toBe('2026-10')
        ->and($month('?month=nonsense'))->toBe('2026-10')
        ->and($month('?month=2026-13'))->toBe('2026-10');
});

test('the calendar only shows my own money', function (): void {
    Bill::factory()->create(['name' => 'Someone else', 'due_day' => 25]);

    $this->actingAs($this->user)->get('/money/calendar')->assertInertia(fn ($page) => $page->has('days', 0));
});
