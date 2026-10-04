<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListGoals;
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
            ->etc());
});

test('a user with no goals gets an empty list', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListGoals::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('goals', [])->etc());
});
