<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\GetMoneyOverview;
use App\Models\Money\Bill;
use App\Models\Money\Debt;
use App\Models\Money\IncomeSource;
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

test('the headline numbers match the Overview page for the same data', function (): void {
    seedOverviewMonth($this->user);

    $expected = null;
    $this->actingAs($this->user)->get('/money')->assertInertia(function ($page) use (&$expected): void {
        $expected = $page->toArray()['props'];
    });

    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('numbers', $expected['numbers'])
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
