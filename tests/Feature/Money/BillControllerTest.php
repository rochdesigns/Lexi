<?php

use App\Models\Money\Bill;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->user = User::factory()->create();
});

test('I can add a monthly bill and the due month is ignored', function (): void {
    $this->actingAs($this->user)
        ->post('/money/bills', ['name' => 'Rent', 'amount' => 15000, 'frequency' => 'monthly', 'due_day' => 5, 'due_month' => 3, 'autopay' => true])
        ->assertRedirect();

    $bill = $this->user->bills()->sole();
    expect($bill->amount)->toBe(1_500_000)->and($bill->due_month)->toBeNull()->and($bill->autopay)->toBeTrue();
});

test('an annual bill needs a due month', function (): void {
    $payload = ['name' => 'Insurance', 'amount' => 6000, 'frequency' => 'annual', 'due_day' => 10, 'autopay' => false];

    $this->actingAs($this->user)->post('/money/bills', $payload)->assertSessionHasErrors('due_month');
    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'due_month' => 13])->assertSessionHasErrors('due_month');
    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'due_month' => 3])->assertSessionHasNoErrors();

    expect($this->user->bills()->sole()->due_month)->toBe(3);
});

test('I can update and delete my bill', function (): void {
    $bill = Bill::factory()->for($this->user)->create();

    $this->actingAs($this->user)
        ->patch("/money/bills/{$bill->id}", ['name' => 'Power', 'amount' => 2500, 'frequency' => 'monthly', 'due_day' => 12, 'autopay' => false])
        ->assertRedirect();
    expect($bill->fresh()->only(['name', 'amount', 'due_day']))->toBe(['name' => 'Power', 'amount' => 250_000, 'due_day' => 12]);

    $this->actingAs($this->user)->delete("/money/bills/{$bill->id}")->assertRedirect();
    expect(Bill::count())->toBe(0);
});

test('I cannot change someone else\'s bill', function (): void {
    $bill = Bill::factory()->create();

    $this->actingAs($this->user)->patch("/money/bills/{$bill->id}", ['name' => 'x', 'amount' => 1, 'frequency' => 'monthly', 'due_day' => 1, 'autopay' => false])->assertForbidden();
    $this->actingAs($this->user)->delete("/money/bills/{$bill->id}")->assertForbidden();
});

test('guests cannot add bills', function (): void {
    $this->post('/money/bills', [])->assertRedirect('/login');
});
