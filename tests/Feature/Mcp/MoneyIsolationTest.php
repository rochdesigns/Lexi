<?php

use App\Mcp\Servers\MoneyServer;
use App\Models\User;

test('no tool returns another user\'s data', function (string $tool): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    seedMarkedMoney($mine, 'AAA-MARK');
    seedMarkedMoney($theirs, 'BBB-MARK');

    MoneyServer::actingAs($mine)->tool($tool)
        ->assertOk()
        ->assertDontSee('BBB-MARK');
})->with(MoneyServer::TOOLS);
