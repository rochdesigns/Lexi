<?php

use App\Models\Account;
use App\Models\SinkingFund;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('guest is redirected from set-asides', function (): void {
    $this->post('/logout');
    $this->get('/set-asides')->assertRedirect('/login');
});

test('set-asides page renders with hero aggregates', function (): void {
    SinkingFund::factory()->for($this->user)->create(['saved' => 640, 'target' => 960, 'per_month' => 80, 'due_date' => null]);
    SinkingFund::factory()->for($this->user)->create(['saved' => 145, 'target' => 290, 'per_month' => 24, 'due_date' => null]);

    $this->get('/set-asides')
        ->assertInertia(fn ($page) => $page
            ->component('SetAsides')
            ->where('saTotal', 785)
            ->where('saTarget', 1250)
            ->where('saPerMonth', 104)
            ->has('funds', 2)
        );
});

test('funds are sorted by due_date then name, with nulls last', function (): void {
    SinkingFund::factory()->for($this->user)->create(['name' => 'No date', 'due_date' => null]);
    SinkingFund::factory()->for($this->user)->create(['name' => 'Later', 'due_date' => '2027-06-01']);
    SinkingFund::factory()->for($this->user)->create(['name' => 'Sooner', 'due_date' => '2026-12-01']);

    $this->get('/set-asides')
        ->assertInertia(fn ($page) => $page
            ->where('funds.0.name', 'Sooner')
            ->where('funds.1.name', 'Later')
            ->where('funds.2.name', 'No date')
        );
});

test('store creates a fund with auto position', function (): void {
    SinkingFund::factory()->for($this->user)->create(['position' => 1]);

    $this->post('/sinking-funds', [
        'name' => 'Car insurance',
        'icon' => 'directions_car',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'saved' => 640,
        'target' => 960,
        'per_month' => 80,
        'due_date' => '2027-02-01',
    ])->assertRedirect();

    $fund = $this->user->sinkingFunds()->where('name', 'Car insurance')->first();
    expect($fund->position)->toBe(2);
    expect((float) $fund->target)->toBe(960.0);
});

test('store validates required fields', function (): void {
    $this->post('/sinking-funds', [])->assertSessionHasErrors(['name', 'icon', 'color', 'bg', 'target', 'per_month']);
});

test('store validates color hex format', function (): void {
    $this->post('/sinking-funds', [
        'name' => 'Test',
        'icon' => 'shield',
        'color' => 'not-a-hex',
        'bg' => '#dcebf5',
        'saved' => 0,
        'target' => 100,
        'per_month' => 10,
    ])->assertSessionHasErrors('color');
});

test('update edits a fund', function (): void {
    $fund = SinkingFund::factory()->for($this->user)->create(['name' => 'Old name']);

    $this->patch("/sinking-funds/{$fund->id}", [
        'name' => 'New name',
        'icon' => 'shield',
        'color' => '#2f7d5b',
        'bg' => '#dff3e6',
        'saved' => 100,
        'target' => 200,
        'per_month' => 20,
        'due_date' => null,
    ])->assertRedirect();

    expect($fund->fresh()->name)->toBe('New name');
});

test('update returns 403 for another user\'s fund', function (): void {
    $other = User::factory()->create();
    $fund = SinkingFund::factory()->for($other)->create();

    $this->patch("/sinking-funds/{$fund->id}", [
        'name' => 'Hacked',
        'icon' => 'shield',
        'color' => '#2f7d5b',
        'bg' => '#dff3e6',
        'saved' => 0,
        'target' => 100,
        'per_month' => 10,
    ])->assertForbidden();
});

test('destroy deletes a fund', function (): void {
    $fund = SinkingFund::factory()->for($this->user)->create();

    $this->delete("/sinking-funds/{$fund->id}")->assertRedirect();

    expect(SinkingFund::find($fund->id))->toBeNull();
});

test('destroy returns 403 for another user\'s fund', function (): void {
    $other = User::factory()->create();
    $fund = SinkingFund::factory()->for($other)->create();

    $this->delete("/sinking-funds/{$fund->id}")->assertForbidden();

    expect(SinkingFund::find($fund->id))->not->toBeNull();
});

test('set-asides page auto-syncs per_month to required amount based on due_date', function (): void {
    $dueDate = now()->addMonths(4)->startOfMonth()->toDateString();
    $fund = SinkingFund::factory()->for($this->user)->create([
        'saved' => 0,
        'target' => 4000,
        'per_month' => 500,
        'due_date' => $dueDate,
    ]);

    $this->get('/set-asides')->assertInertia(fn ($page) => $page
        ->has('funds.0.months_left')
        ->where('funds.0.per_month', fn ($v) => $v !== 500.0)
    );

    expect((float) $fund->fresh()->per_month)->not->toBe(500.0);
});

test('set-asides page includes accounts prop', function (): void {
    Account::factory()->for($this->user)->create(['name' => 'Checking']);

    $this->get('/set-asides')
        ->assertInertia(fn ($page) => $page
            ->component('SetAsides')
            ->has('accounts', 1, fn ($a) => $a->where('name', 'Checking')->etc())
        );
});

test('sinkingFunds shared prop includes the user\'s funds', function (): void {
    $fund = SinkingFund::factory()->for($this->user)->create(['name' => 'Vacation']);

    $this->get('/set-asides')
        ->assertInertia(fn ($page) => $page
            ->has('sinkingFunds', 1, fn ($f) => $f->where('id', $fund->id)->where('name', 'Vacation')->etc())
        );
});

test('set-asides page includes fund payments', function (): void {
    $account = Account::factory()->for($this->user)->create();
    $fund = SinkingFund::factory()->for($this->user)->create(['saved' => 8000, 'target' => 12000]);
    Transaction::factory()->create([
        'user_id' => $this->user->id,
        'account_id' => $account->id,
        'sinking_fund_id' => $fund->id,
        'type' => 'expense',
        'amount' => 4000,
    ]);

    $this->get('/set-asides')
        ->assertInertia(fn ($page) => $page
            ->has('funds.0.payments', 1, fn ($p) => $p->where('amount', 4000)->etc())
        );
});
