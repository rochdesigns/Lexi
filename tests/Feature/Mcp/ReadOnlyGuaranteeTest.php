<?php

use App\Mcp\Servers\MoneyServer;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

dataset('money tools', MoneyServer::TOOLS);

test('the server exposes exactly the reviewed read-only tools', function (): void {
    $allowed = [
        'get_money_overview',
        'list_accounts',
        'list_bills',
        'list_categories',
        'list_debts',
        'list_goals',
        'list_income_sources',
        'list_transactions',
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

test('no tool takes an argument beyond the reviewed ones, in particular no user or ownership argument', function (string $tool): void {
    $tool = new $tool;
    $properties = array_keys((array) ($tool->toArray()['inputSchema']['properties'] ?? []));

    expect($properties)->toBe(['list_transactions' => ['month']][$tool->name()] ?? []);
})->with('money tools');
