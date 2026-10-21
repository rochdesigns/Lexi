<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListCategories;
use App\Models\Money\CustomCategory;
use App\Models\User;

test('it lists the built-in expense categories and the user\'s own', function (): void {
    $user = User::factory()->create();
    $category = CustomCategory::factory()->for($user)->create(['name' => 'Pets']);
    CustomCategory::factory()->create(['name' => 'Someone else\'s']);

    MoneyServer::actingAs($user)->tool(ListCategories::class)
        ->assertOk()
        ->assertDontSee('Someone else')
        ->assertStructuredContent(fn ($json) => $json
            ->where('builtInCategories', ['food', 'groceries', 'transport', 'shopping', 'health', 'fun', 'home', 'other'])
            ->where('customCategories', [['id' => $category->id, 'name' => 'Pets']])
            ->etc());
});
