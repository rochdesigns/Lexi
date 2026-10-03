<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

dataset('money pages', [
    'overview' => ['/money', 'Money/Overview'],
    'debts' => ['/money/debts', 'Money/Debts'],
    'calendar' => ['/money/calendar', 'Money/Calendar'],
    'transactions' => ['/money/transactions', 'Money/Transactions'],
    'plan' => ['/money/plan', 'Money/Plan'],
]);

test('money pages render for an authenticated user', function (string $url, string $component): void {
    $this->actingAs(User::factory()->create())
        ->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component($component));
})->with('money pages');

test('money pages redirect guests to login', function (string $url): void {
    $this->get($url)->assertRedirect('/login');
})->with(array_column(iterator_to_array(new ArrayIterator([
    ['/money'], ['/money/debts'], ['/money/calendar'], ['/money/transactions'], ['/money/plan'],
])), 0));

test('the retired money routes are gone', function (string $url): void {
    $this->actingAs(User::factory()->create())->get($url)->assertNotFound();
})->with(['/budget', '/ledger', '/payoff-plan', '/forecast']);
