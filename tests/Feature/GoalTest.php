<?php

use App\Models\Account;
use App\Models\Goal;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('guest is redirected from goals', function (): void {
    $this->post('/logout');
    $this->get('/goals')->assertRedirect('/login');
});

test('goals page renders with hero aggregates', function (): void {
    Goal::factory()->for($this->user)->create(['saved' => 6200, 'target' => 9000, 'per_month' => 300, 'by_date' => null]);
    Goal::factory()->for($this->user)->create(['saved' => 1450, 'target' => 4000, 'per_month' => 200, 'by_date' => null]);

    $this->get('/goals')
        ->assertInertia(fn ($page) => $page
            ->component('Goals')
            ->where('goalTotal', 7650)
            ->where('goalTarget', 13000)
            ->where('goalPerMonth', 500)
            ->has('goals', 2)
        );
});

test('goals are sorted by by_date then name, with nulls last', function (): void {
    Goal::factory()->for($this->user)->create(['name' => 'No date', 'by_date' => null]);
    Goal::factory()->for($this->user)->create(['name' => 'Later', 'by_date' => '2027-06-01']);
    Goal::factory()->for($this->user)->create(['name' => 'Sooner', 'by_date' => '2026-12-01']);

    $this->get('/goals')
        ->assertInertia(fn ($page) => $page
            ->where('goals.0.name', 'Sooner')
            ->where('goals.1.name', 'Later')
            ->where('goals.2.name', 'No date')
        );
});

test('store creates a goal with auto position', function (): void {
    Goal::factory()->for($this->user)->create(['position' => 1]);

    $this->post('/goals', [
        'name' => 'Japan trip',
        'icon' => 'flight',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'saved' => 1450,
        'target' => 4000,
        'per_month' => 200,
        'by_date' => '2027-04-01',
    ])->assertRedirect();

    $goal = $this->user->goals()->where('name', 'Japan trip')->first();
    expect($goal->position)->toBe(2);
    expect((float) $goal->target)->toBe(4000.0);
});

test('store validates required fields', function (): void {
    $this->post('/goals', [])->assertSessionHasErrors(['name', 'icon', 'color', 'bg', 'target', 'per_month']);
});

test('store validates color hex format', function (): void {
    $this->post('/goals', [
        'name' => 'Test',
        'icon' => 'flight',
        'color' => 'not-a-hex',
        'bg' => '#dcebf5',
        'saved' => 0,
        'target' => 100,
        'per_month' => 10,
    ])->assertSessionHasErrors('color');
});

test('update edits a goal', function (): void {
    $goal = Goal::factory()->for($this->user)->create(['name' => 'Old name']);

    $this->patch("/goals/{$goal->id}", [
        'name' => 'New name',
        'icon' => 'flight',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'saved' => 100,
        'target' => 200,
        'per_month' => 20,
        'by_date' => null,
    ])->assertRedirect();

    expect($goal->fresh()->name)->toBe('New name');
});

test('update returns 403 for another user\'s goal', function (): void {
    $other = User::factory()->create();
    $goal = Goal::factory()->for($other)->create();

    $this->patch("/goals/{$goal->id}", [
        'name' => 'Hacked',
        'icon' => 'flight',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'saved' => 0,
        'target' => 100,
        'per_month' => 10,
    ])->assertForbidden();
});

test('top-up creates a transaction, debits account, and increments saved', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 5000]);
    $goal = Goal::factory()->for($this->user)->create(['saved' => 500]);

    $this->post("/goals/{$goal->id}/top-up", [
        'amount' => 250,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-23',
    ])->assertRedirect();

    expect((float) $goal->fresh()->saved)->toBe(750.0);
    expect((float) $account->fresh()->balance)->toBe(4750.0);

    $tx = Transaction::where('goal_id', $goal->id)->first();
    expect($tx)->not->toBeNull();
    expect($tx->type)->toBe('expense');
    expect((float) $tx->amount)->toBe(250.0);
    expect($tx->account_id)->toBe($account->id);
});

test('top-up validates required fields', function (): void {
    $goal = Goal::factory()->for($this->user)->create();

    $this->post("/goals/{$goal->id}/top-up", [])
        ->assertSessionHasErrors(['amount', 'account_id', 'transacted_at']);
});

test('top-up validates amount is positive', function (): void {
    $account = Account::factory()->for($this->user)->create();
    $goal = Goal::factory()->for($this->user)->create();

    $this->post("/goals/{$goal->id}/top-up", [
        'amount' => -10,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-23',
    ])->assertSessionHasErrors('amount');
});

test('top-up returns 403 for another user\'s goal', function (): void {
    $other = User::factory()->create();
    $account = Account::factory()->for($this->user)->create();
    $goal = Goal::factory()->for($other)->create(['saved' => 0]);

    $this->post("/goals/{$goal->id}/top-up", [
        'amount' => 100,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-23',
    ])->assertForbidden();

    expect((float) $goal->fresh()->saved)->toBe(0.0);
});

test('deleting a goal top-up transaction reverses the saved amount', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 4750]);
    $goal = Goal::factory()->for($this->user)->create(['saved' => 750]);
    $tx = Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'goal_id' => $goal->id,
        'type' => 'expense',
        'amount' => 250,
        'transacted_at' => '2026-07-23',
    ]);

    $this->delete("/transactions/{$tx->id}")->assertRedirect();

    expect((float) $goal->fresh()->saved)->toBe(500.0);
    expect((float) $account->fresh()->balance)->toBe(5000.0);
});

test('goals page includes accounts prop', function (): void {
    Account::factory()->for($this->user)->create(['name' => 'BDO Savings']);

    $this->get('/goals')
        ->assertInertia(fn ($page) => $page
            ->component('Goals')
            ->has('accounts', 1, fn ($a) => $a->where('name', 'BDO Savings')->etc())
        );
});

test('destroy deletes a goal', function (): void {
    $goal = Goal::factory()->for($this->user)->create();

    $this->delete("/goals/{$goal->id}")->assertRedirect();

    expect(Goal::find($goal->id))->toBeNull();
});

test('destroy returns 403 for another user\'s goal', function (): void {
    $other = User::factory()->create();
    $goal = Goal::factory()->for($other)->create();

    $this->delete("/goals/{$goal->id}")->assertForbidden();

    expect(Goal::find($goal->id))->not->toBeNull();
});
