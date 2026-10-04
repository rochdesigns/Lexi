<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListGoals;
use App\Models\Money\Account;
use App\Models\Money\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-15')));

test('it lists the buffer and the sinking funds with progress', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->buffer(3)->create(['saved' => 100_000]);
    Goal::factory()->for($user)->create(['name' => 'New laptop', 'target_amount' => 5_000_000, 'saved' => 1_000_000, 'monthly_contribution' => 200_000]);

    MoneyServer::actingAs($user)->tool(ListGoals::class)
        ->assertOk()
        ->assertSee(['Emergency buffer', 'New laptop'])
        ->assertStructuredContent(fn ($json) => $json
            ->has('goals', 2)
            ->where('goals.0.kind', 'buffer')
            ->where('goals.1.name', 'New laptop')
            ->where('goals.1.targetAmount', 5_000_000)
            ->where('goals.1.saved', 1_000_000)
            ->where('goals.1.remaining', 4_000_000)
            ->where('goals.1.percent', 20)
            ->where('goals.1.monthsToGo', 20)
            ->etc());
});

test('a user with no goals gets an empty list', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListGoals::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('goals', [])->etc());
});

test('a goal linked to an account reports the account balance as saved', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Savings pot', 'balance' => 750_000]);
    Goal::factory()->for($user)->create([
        'name' => 'Trip',
        'target_amount' => 1_500_000,
        'saved' => 0,
        'monthly_contribution' => 250_000,
        'account_id' => $account->id,
    ]);

    MoneyServer::actingAs($user)->tool(ListGoals::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('goals.0', fn ($goal) => $goal
                ->where('accountName', 'Savings pot')
                ->where('saved', 750_000)
                ->where('remaining', 750_000)
                ->where('percent', 50)
                ->etc())
            ->etc());
});
