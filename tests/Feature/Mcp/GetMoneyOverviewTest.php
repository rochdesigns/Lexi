<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\GetMoneyOverview;
use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\CardBalanceSnapshot;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
use App\Models\Money\IncomeSource;
use App\Models\Money\InterestType;
use App\Models\Money\PaymentPlan;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

function seedOverviewMonth(User $user): void
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

/**
 * The same month plus a thin spendable account, a tuition bill bigger than the cash and the 15th's pay, and a card that has carried a
 * balance for three months: the month's dues outrun the cash (shortfalls), and
 * the card trips the carried-balance alert and recovery mode. The card has no
 * interest and a 5% minimum, so recovery is easy to work out by hand:
 * 1,200,000 carried, minimum towards it 60,000.
 */
function seedOverviewWithCarriedCard(User $user): Debt
{
    seedOverviewMonth($user);

    Account::factory()->for($user)->create(['name' => 'Wallet', 'balance' => 300_000]);
    Bill::factory()->for($user)->create(['name' => 'Tuition', 'amount' => 2_000_000, 'due_day' => 18]);

    $card = Debt::factory()->for($user)->create([
        'name' => 'Visa',
        'type' => DebtType::CreditCard,
        'interest_type' => InterestType::Revolving,
        'monthly_interest_rate' => 0,
        'due_day' => 28,
    ]);
    CardDetail::factory()->create([
        'debt_id' => $card->id,
        'credit_limit' => 5_000_000,
        'min_payment_percent' => 5,
        'min_payment_floor' => 50_000,
        'revolving_balance' => 1_200_000,
    ]);

    foreach (['2026-08' => 1_000_000, '2026-09' => 1_100_000] as $month => $balance) {
        CardBalanceSnapshot::create(['debt_id' => $card->id, 'month' => $month, 'revolving_balance' => $balance]);
    }

    return $card;
}

test('the headline numbers match the Overview page for the same data', function (): void {
    seedOverviewWithCarriedCard($this->user);

    $expected = null;
    $this->actingAs($this->user)->get('/money')->assertInertia(function ($page) use (&$expected): void {
        $expected = $page->toArray()['props'];
    });

    expect($expected['cashAvailable'])->toBe(300_000)
        ->and($expected['shortfalls'])->not->toBeEmpty()
        ->and($expected['carryAlerts'])->not->toBeEmpty()
        ->and($expected['recovery'])->not->toBeNull();

    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('numbers', $expected['numbers'])
            ->where('recovery', [
                'carryover' => $expected['recovery']['carryover'],
                'months' => $expected['recovery']['months'],
                'extraPerMonth' => $expected['recovery']['extra'],
            ])
            ->where('cashAvailable', $expected['cashAvailable'])
            ->where('shortfalls', $expected['shortfalls'])
            ->where('upcoming', $expected['upcoming'])
            ->where('carryAlerts', $expected['carryAlerts'])
            ->etc());
});

test('the safe-to-spend figure matches the hand calculation', function (): void {
    seedOverviewMonth($this->user);

    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('numbers.safeToSpend', 4_041_666)
            ->where('numbers.perDay', 237_745)
            ->etc());
});

test('a brand-new user gets zeroed numbers, not an error', function (): void {
    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('numbers.income', 0)
            ->where('numbers.safeToSpend', 0)
            ->where('upcoming', [])
            ->where('recovery', null)
            ->etc());
});

test('a carried card balance is reported with the recovery plan chosen by the user', function (): void {
    $card = seedOverviewWithCarriedCard($this->user);
    $this->user->setting()->updateOrCreate([], ['recovery_months' => 6]);

    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('carryAlerts', [['debtId' => $card->id, 'name' => 'Visa', 'monthsCarried' => 3, 'balance' => 1_200_000]])
            // 1,200,000 over 6 months with no interest is 200,000 a month; the
            // 5% minimum (60,000) already pays part of that, leaving 140,000.
            ->where('recovery', ['carryover' => 1_200_000, 'months' => 6, 'extraPerMonth' => 140_000])
            ->etc());
});

test('recovery has no extra to set aside until the user picks a number of months', function (): void {
    seedOverviewWithCarriedCard($this->user);

    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('recovery', ['carryover' => 1_200_000, 'months' => null, 'extraPerMonth' => 0])
            ->etc());
});
