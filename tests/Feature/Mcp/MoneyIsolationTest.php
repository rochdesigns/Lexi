<?php

use App\Mcp\Servers\MoneyServer;
use App\Models\User;

/**
 * The positive assertion exists so an empty response cannot pass as "isolated".
 */
test('no tool returns another user\'s data', function (string $tool): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    seedMarkedMoney($mine, 'AAA-MARK');
    seedMarkedMoney($theirs, 'BBB-MARK');

    MoneyServer::actingAs($mine)->tool($tool)
        ->assertOk()
        ->assertSee('AAA-MARK')
        ->assertDontSee('BBB-MARK');
})->with(MoneyServer::TOOLS);
