<?php

use App\Mcp\Servers\MoneyServer;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

dataset('money tools', MoneyServer::TOOLS);

test('the server exposes exactly the reviewed read-only tools', function (): void {
    $allowed = [
        'get_money_overview',
        'list_accounts',
        'list_bills',
        'list_debts',
        'list_goals',
        'list_income_sources',
    ];

    $names = collect(MoneyServer::TOOLS)
        ->map(fn (string $tool): string => (new $tool)->name())
        ->sort()
        ->values()
        ->all();

    expect($names)->toBe($allowed);
});

test('every tool is annotated read-only', function (string $tool): void {
    expect((new ReflectionClass($tool))->getAttributes(IsReadOnly::class))->not->toBeEmpty();
    expect((new $tool)->toArray()['annotations'])->toMatchArray(['readOnlyHint' => true]);
})->with('money tools');

test('no tool takes any argument, in particular no user or ownership argument', function (string $tool): void {
    $properties = array_keys((array) ((new $tool)->toArray()['inputSchema']['properties'] ?? []));

    expect($properties)->toBe([]);
})->with('money tools');
