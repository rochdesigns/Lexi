<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListAccounts;
use App\Mcp\Tools\Money\ListBills;
use App\Mcp\Tools\Money\ListDebts;
use App\Mcp\Tools\Money\ListGoals;
use App\Mcp\Tools\Money\ListIncomeSources;
use App\Models\User;

/**
 * The positive assertion exists so an empty response cannot pass as "isolated".
 * User B's amounts are far from anything user A's data can add up to, so a
 * leaked value (or an aggregate computed across both users) would show.
 */
test('no tool returns another user\'s data', function (string $tool): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    seedMarkedMoney($mine, 'AAA-MARK');
    $theirAmounts = seedMarkedMoney($theirs, 'BBB-MARK', 98_765_432);

    $response = MoneyServer::actingAs($mine)->tool($tool)
        ->assertOk()
        ->assertSee('AAA-MARK')
        ->assertDontSee('BBB-MARK');

    foreach ($theirAmounts as $name => $amount) {
        $response->assertDontSee((string) $amount);
    }
})->with(MoneyServer::TOOLS);

/**
 * Catches what the checks above cannot: an aggregate (a cash total, a monthly
 * obligation) computed across users contains no single value of the other
 * user's, but it does change the owner's answer when the other user exists.
 */
test('a tool\'s answer for a user is unchanged by another user\'s data', function (string $tool): void {
    $mine = User::factory()->create();
    seedMarkedMoney($mine, 'AAA-MARK');

    $structured = function () use ($mine, $tool): array {
        $captured = [];

        MoneyServer::actingAs($mine)->tool($tool)->assertOk()->assertStructuredContent(function ($json) use (&$captured): bool {
            $captured = $json->etc()->toArray();

            return true;
        });

        return $captured;
    };

    $alone = $structured();

    seedMarkedMoney(User::factory()->create(), 'BBB-MARK', 98_765_432);

    expect($alone)->not->toBeEmpty()
        ->and($structured())->toBe($alone);
})->with(MoneyServer::TOOLS);

/**
 * Proves the amount assertions above can fail: the same numbers do render in
 * the owner's own responses.
 */
test('a user\'s own amounts are visible to them', function (string $amountName, string $tool): void {
    $user = User::factory()->create();
    $amounts = seedMarkedMoney($user, 'BBB-MARK', 98_765_432);

    MoneyServer::actingAs($user)->tool($tool)->assertSee((string) $amounts[$amountName]);
})->with([
    'account balance' => ['accountBalance', ListAccounts::class],
    'bill amount' => ['billAmount', ListBills::class],
    'income amount' => ['incomeAmount', ListIncomeSources::class],
    'receipt amount' => ['receiptAmount', ListIncomeSources::class],
    'goal target' => ['goalTarget', ListGoals::class],
    'goal contribution' => ['goalContribution', ListGoals::class],
    'loan balance' => ['loanBalance', ListDebts::class],
    'payment amount' => ['paymentAmount', ListDebts::class],
    'card limit' => ['cardLimit', ListDebts::class],
    'card balance' => ['cardBalance', ListDebts::class],
    'card minimum floor' => ['cardMinimumFloor', ListDebts::class],
]);
