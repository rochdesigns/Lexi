<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListBills;
use App\Models\Money\Bill;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-15')));

test('it lists bills with their next due date', function (): void {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25, 'autopay' => true]);
    Bill::factory()->for($user)->annual(3)->create(['name' => 'Insurance', 'amount' => 600_000, 'due_day' => 10]);

    MoneyServer::actingAs($user)->tool(ListBills::class)
        ->assertOk()
        ->assertSee(['Rent', 'Insurance'])
        ->assertStructuredContent(fn ($json) => $json
            ->has('bills', 2)
            ->has('bills.1', fn ($bill) => $bill
                ->where('name', 'Rent')
                ->where('amount', 150_000)
                ->where('frequency', 'monthly')
                ->where('dueDay', 25)
                ->where('autopay', true)
                ->where('nextDueDate', '2026-10-25')
                ->etc())
            ->etc());
});

test('a user with no bills gets an empty list', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListBills::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('bills', [])->etc());
});
