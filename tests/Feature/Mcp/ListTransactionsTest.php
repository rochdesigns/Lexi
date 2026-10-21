<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListTransactions;
use App\Models\Money\Account;
use App\Models\Money\CustomCategory;
use App\Models\Money\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

test('it lists the current month\'s entries, newest first, with custom category names', function (): void {
    Carbon::setTestNow('2026-10-21');
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $category = CustomCategory::factory()->for($user)->create(['name' => 'Pets']);
    $older = Transaction::factory()->for($user)->create(['account_id' => $account->id, 'date' => '2026-10-02']);
    $newer = Transaction::factory()->for($user)->create(['account_id' => $account->id, 'date' => '2026-10-20', 'category' => 'other', 'custom_category_id' => $category->id]);
    Transaction::factory()->for($user)->create(['account_id' => $account->id, 'date' => '2026-09-30']);

    MoneyServer::actingAs($user)->tool(ListTransactions::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('month', '2026-10')
            ->has('transactions', 2)
            ->where('transactions.0.id', $newer->id)
            ->where('transactions.0.customCategoryName', 'Pets')
            ->where('transactions.1.id', $older->id)
            ->etc());
});

test('it lists another month when asked', function (): void {
    $user = User::factory()->create();
    $transaction = Transaction::factory()->for($user)->create(['account_id' => Account::factory()->for($user), 'date' => '2026-09-30']);

    MoneyServer::actingAs($user)->tool(ListTransactions::class, ['month' => '2026-09'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('transactions', 1)
            ->where('transactions.0.id', $transaction->id)
            ->etc());
});

test('a bad month is rejected', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListTransactions::class, ['month' => '2026-13'])
        ->assertHasErrors();
});
