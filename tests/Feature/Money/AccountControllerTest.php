<?php

use App\Models\Money\Account;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('I can add an account with its balance in pesos', function (): void {
    $this->actingAs($this->user)
        ->post('/money/accounts', ['name' => 'BPI Savings', 'type' => 'bank', 'balance' => 12500.75, 'is_spendable' => false])
        ->assertRedirect();

    $account = $this->user->accounts()->sole();
    expect($account->balance)->toBe(1_250_075)->and($account->is_spendable)->toBeFalse();
});

test('an account needs a known type and a balance that is not negative', function (): void {
    $payload = ['name' => 'GCash', 'type' => 'ewallet', 'balance' => 100, 'is_spendable' => true];

    $this->actingAs($this->user)->post('/money/accounts', [...$payload, 'type' => 'crypto'])->assertSessionHasErrors('type');
    $this->actingAs($this->user)->post('/money/accounts', [...$payload, 'balance' => -1])->assertSessionHasErrors('balance');
    $this->actingAs($this->user)->post('/money/accounts', [...$payload, 'balance' => null])->assertSessionHasErrors('balance');
    expect(Account::count())->toBe(0);
});

test('I can update and delete my account', function (): void {
    $account = Account::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch("/money/accounts/{$account->id}", ['name' => 'Wallet', 'type' => 'cash', 'balance' => 300, 'is_spendable' => true])
        ->assertRedirect();
    expect($account->fresh()->only(['name', 'balance']))->toBe(['name' => 'Wallet', 'balance' => 30_000]);

    $this->actingAs($this->user)->delete("/money/accounts/{$account->id}")->assertRedirect();
    expect(Account::count())->toBe(0);
});

test('I cannot change someone else\'s account', function (): void {
    $account = Account::factory()->create();

    $this->actingAs($this->user)->patch("/money/accounts/{$account->id}", ['name' => 'x', 'type' => 'cash', 'balance' => 1, 'is_spendable' => true])->assertForbidden();
    $this->actingAs($this->user)->delete("/money/accounts/{$account->id}")->assertForbidden();
});

test('guests cannot add accounts', function (): void {
    $this->post('/money/accounts', [])->assertRedirect('/login');
});

test('on hand counts spendable accounts only', function (): void {
    Account::factory()->for($this->user)->create(['name' => 'A', 'balance' => 1_000_000]);
    Account::factory()->for($this->user)->create(['name' => 'B', 'balance' => 250_000]);
    Account::factory()->for($this->user)->notSpendable()->create(['name' => 'C', 'balance' => 9_000_000]);
    Account::factory()->create(['balance' => 5_000_000]);

    expect(app(Projection::class)->cashAvailable($this->user->accounts()->get()))->toBe(1_250_000);

    $this->actingAs($this->user)->get('/money')->assertInertia(fn ($page) => $page
        ->where('cashAvailable', 1_250_000)
        ->has('accounts', 3)
        ->where('accounts.2.isSpendable', false));
});
