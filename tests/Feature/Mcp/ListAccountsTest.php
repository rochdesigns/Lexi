<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListAccounts;
use App\Models\Money\Account;
use App\Models\User;

test('it lists the user\'s accounts with balances', function (): void {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'BDO Savings', 'balance' => 250_000]);
    Account::factory()->for($user)->notSpendable()->create(['name' => 'Rainy Day', 'balance' => 900_000]);

    MoneyServer::actingAs($user)->tool(ListAccounts::class)
        ->assertOk()
        ->assertSee(['BDO Savings', 'Rainy Day'])
        ->assertStructuredContent(fn ($json) => $json
            ->where('currency', 'PHP')
            ->where('cashAvailable', 250_000)
            ->has('accounts', 2)
            ->has('accounts.0', fn ($account) => $account
                ->where('name', 'BDO Savings')
                ->where('balance', 250_000)
                ->where('isSpendable', true)
                ->etc())
            ->etc());
});

test('a user with no accounts gets an empty list, not an error', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListAccounts::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('accounts', [])
            ->where('cashAvailable', 0)
            ->etc());
});
