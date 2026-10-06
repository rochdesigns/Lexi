<?php

use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\Transaction;
use App\Models\User;
use App\Services\Money\Projection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
    $this->account = Account::factory()->for($this->user)->create(['balance' => 1_000_000]);
});

function billPayment(array $overrides = []): array
{
    return ['date' => '2026-10-20', 'amount' => 1500, 'category' => 'home', ...$overrides];
}

test('I can set the account a bill is paid from', function (): void {
    $payload = ['name' => 'Rent', 'amount' => 15000, 'frequency' => 'monthly', 'due_day' => 25, 'autopay' => false];

    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'account_id' => $this->account->id])->assertRedirect();

    expect($this->user->bills()->sole()->account_id)->toBe($this->account->id);
});

test('a bill cannot be paid from someone else\'s account', function (): void {
    $payload = ['name' => 'Rent', 'amount' => 15000, 'frequency' => 'monthly', 'due_day' => 25, 'autopay' => false];

    $this->actingAs($this->user)->post('/money/bills', [...$payload, 'account_id' => Account::factory()->create()->id])->assertSessionHasErrors('account_id');
});

test('an expense can be logged against my bill and comes off the account', function (): void {
    $bill = Bill::factory()->for($this->user)->create(['account_id' => $this->account->id]);

    $this->actingAs($this->user)->post('/money/transactions', billPayment(['account_id' => $this->account->id, 'bill_id' => $bill->id]))->assertRedirect();

    expect($this->user->transactions()->sole()->bill_id)->toBe($bill->id)
        ->and($this->account->fresh()->balance)->toBe(850_000);
});

test('an expense cannot be logged against someone else\'s bill or as income', function (): void {
    $other = Bill::factory()->create();
    $mine = Bill::factory()->for($this->user)->create();

    $this->actingAs($this->user)->post('/money/transactions', billPayment(['account_id' => $this->account->id, 'bill_id' => $other->id]))->assertSessionHasErrors('bill_id');
    $this->actingAs($this->user)->post('/money/transactions', ['kind' => 'income', 'date' => '2026-10-20', 'amount' => 100, 'account_id' => $this->account->id, 'bill_id' => $mine->id])->assertSessionHasErrors('bill_id');
});

test('editing a bill payment keeps the bill', function (): void {
    $bill = Bill::factory()->for($this->user)->create();
    $transaction = Transaction::factory()->for($this->user)->create(['account_id' => $this->account->id, 'bill_id' => $bill->id]);

    $this->actingAs($this->user)->patch("/money/transactions/{$transaction->id}", billPayment(['account_id' => $this->account->id]))->assertRedirect();

    expect($transaction->fresh()->bill_id)->toBe($bill->id);
});

test('the Transactions page lists my bills and names the bill a row pays', function (): void {
    $bill = Bill::factory()->for($this->user)->create(['name' => 'Meralco', 'account_id' => $this->account->id]);
    Transaction::factory()->for($this->user)->create(['date' => '2026-10-10', 'account_id' => $this->account->id, 'bill_id' => $bill->id]);

    $this->actingAs($this->user)->get('/money/transactions')->assertInertia(fn ($page) => $page
        ->where('bills.0.name', 'Meralco')
        ->where('bills.0.accountId', $this->account->id)
        ->where('transactions.0.billName', 'Meralco'));
});

test('a bill paid this month is not counted as still to come', function (): void {
    $bill = Bill::factory()->for($this->user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
    $projection = app(Projection::class);
    $today = Carbon::today();

    expect($projection->upcoming(collect(), $this->user->bills()->get(), collect(), $today, 14)[0]['received'])->toBeFalse();

    Transaction::factory()->for($this->user)->create(['date' => '2026-10-12', 'account_id' => $this->account->id, 'bill_id' => $bill->id]);

    $items = $projection->upcoming(collect(), $this->user->bills()->get(), collect(), $today, 14);

    expect($items[0]['received'])->toBeTrue()
        ->and($projection->shortfalls(collect(), $this->user->bills()->get(), collect(), collect(), $today))->toBe([]);
});

test('next month\'s bill is still due after this month\'s is paid', function (): void {
    $bill = Bill::factory()->for($this->user)->create(['due_day' => 5]);
    Transaction::factory()->for($this->user)->create(['date' => '2026-10-04', 'account_id' => $this->account->id, 'bill_id' => $bill->id]);

    $items = app(Projection::class)->upcoming(collect(), $this->user->bills()->get(), collect(), Carbon::parse('2026-10-15'), 30);

    expect($items)->toHaveCount(1)->and($items[0]['date'])->toBe('2026-11-05')->and($items[0]['received'])->toBeFalse();
});
