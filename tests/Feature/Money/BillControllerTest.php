<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\BillFrequency;
use App\Models\Money\Debt;
use App\Models\Money\DebtType;
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

test('a quarterly bill needs a first due month', function (): void {
    $payload = ['name' => 'Water', 'amount' => 3000, 'frequency' => 'quarterly', 'due_day' => 10, 'autopay' => false];

    $this->actingAs($this->user)->post('/money/bills', $payload)->assertSessionHasErrors('due_month');
    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'due_month' => 2])->assertSessionHasNoErrors();

    $bill = $this->user->bills()->sole();
    expect($bill->frequency)->toBe(BillFrequency::Quarterly)->and($bill->due_month)->toBe(2);
});

test('a bill can be charged to one of my credit cards', function (): void {
    $card = Debt::factory()->for($this->user)->create(['type' => DebtType::CreditCard]);
    $payload = ['name' => 'Netflix', 'amount' => 549, 'frequency' => 'monthly', 'due_day' => 8, 'autopay' => true];

    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'debt_id' => $card->id])->assertSessionHasNoErrors();

    expect($this->user->bills()->sole()->debt_id)->toBe($card->id);
});

test('a bill cannot name a card that is not mine, not a card, or an account as well', function (): void {
    $payload = ['name' => 'Netflix', 'amount' => 549, 'frequency' => 'monthly', 'due_day' => 8, 'autopay' => true];
    $theirCard = Debt::factory()->for(User::factory())->create(['type' => DebtType::CreditCard]);
    $loan = Debt::factory()->for($this->user)->create(['type' => DebtType::Loan]);
    $card = Debt::factory()->for($this->user)->create(['type' => DebtType::CreditCard]);
    $account = Account::factory()->for($this->user)->create();

    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'debt_id' => $theirCard->id])->assertSessionHasErrors('debt_id');
    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'debt_id' => $loan->id])->assertSessionHasErrors('debt_id');
    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'debt_id' => $card->id, 'account_id' => $account->id])->assertSessionHasErrors();

    expect($this->user->bills()->count())->toBe(0);
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

test('a bill added from the plan page returns there and shows under yearly bills', function (): void {
    $this->actingAs($this->user)
        ->from('/money/plan')
        ->post('/money/bills', ['name' => 'Water', 'amount' => 3000, 'frequency' => 'quarterly', 'due_day' => 10, 'due_month' => 2, 'autopay' => false])
        ->assertRedirect('/money/plan');

    $this->actingAs($this->user)->get('/money/plan')->assertInertia(fn ($page) => $page
        ->has('yearlyBills', 1)
        ->where('yearlyBills.0.name', 'Water')
        ->where('yearlyBills.0.monthlySetAside', 100_000));
});
