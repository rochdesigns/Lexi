<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListIncomeSources;
use App\Models\Money\IncomeSource;
use App\Models\Money\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-15')));

test('it lists income sources with pay days and recent receipts', function (): void {
    $user = User::factory()->create();
    $salary = IncomeSource::factory()->for($user)->semiMonthly(15, 30)->create(['name' => 'Salary', 'amount' => 2_000_000]);
    Transaction::factory()->for($user)->income()->create(['income_source_id' => $salary->id, 'amount' => 2_000_000, 'date' => '2026-10-13']);
    Transaction::factory()->for($user)->income()->create(['income_source_id' => $salary->id, 'amount' => 2_000_000, 'date' => '2026-08-01']);

    MoneyServer::actingAs($user)->tool(ListIncomeSources::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('incomeSources', 1)
            ->has('incomeSources.0', fn ($source) => $source
                ->where('name', 'Salary')
                ->where('amount', 2_000_000)
                ->where('schedule', 'semi_monthly')
                ->where('payDays', [15, 30])
                ->has('recentReceipts', 1)
                ->has('recentReceipts.0', fn ($receipt) => $receipt->where('date', '2026-10-13')->where('amount', 2_000_000))
                ->etc())
            ->etc());
});

test('a user with no income sources gets an empty list', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListIncomeSources::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('incomeSources', [])->etc());
});
