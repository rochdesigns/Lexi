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
    $this->projection = new Projection;
});

test('a bill has a share saved for each month since it was last due', function (): void {
    $annual = Bill::factory()->for($this->user)->annual(3)->create(['amount' => 100_000]);
    $quarterly = Bill::factory()->for($this->user)->quarterly(2)->create(['amount' => 30_000]);

    expect($this->projection->setAsideSoFar($annual->load('payments'), Carbon::today()))->toBe(58_338)
        ->and($this->projection->setAsideSoFar($quarterly->load('payments'), Carbon::today()))->toBe(20_000);
});

test('in its due month a bill needs the whole amount until it is paid', function (): void {
    $bill = Bill::factory()->for($this->user)->annual(10)->create(['amount' => 120_000]);

    expect($this->projection->setAsideSoFar($bill->load('payments'), Carbon::today()))->toBe(120_000);

    Transaction::factory()->for($this->user)->create(['date' => '2026-10-10', 'bill_id' => $bill->id, 'amount' => 120_000]);

    expect($this->projection->setAsideSoFar($bill->fresh()->load('payments'), Carbon::today()))->toBe(0);
});

test('the plan shows the yearly bills account against what the bills need by now', function (): void {
    $account = Account::factory()->for($this->user)->notSpendable()->create(['name' => 'Yearly pot', 'balance' => 50_000]);
    Bill::factory()->for($this->user)->annual(3)->create(['name' => 'Domain', 'amount' => 120_000]);
    Bill::factory()->for($this->user)->create(['name' => 'Rent', 'amount' => 150_000]);
    $this->user->setting()->updateOrCreate([], ['yearly_bills_account_id' => $account->id]);

    $this->actingAs($this->user)->get('/money/plan')->assertOk()->assertInertia(fn ($page) => $page
        ->where('yearlyBillsAccount', ['id' => $account->id, 'name' => 'Yearly pot', 'saved' => 50_000, 'target' => 70_000, 'short' => 20_000])
        ->where('yearlyBills.0.setAsideSoFar', 70_000));
});

test('the plan has no yearly bills account until one is picked', function (): void {
    $this->actingAs($this->user)->get('/money/plan')->assertInertia(fn ($page) => $page->where('yearlyBillsAccount', null));
});

test('the yearly bills account can be picked and cleared', function (): void {
    $account = Account::factory()->for($this->user)->notSpendable()->create();

    $this->actingAs($this->user)->patch('/money/yearly-bills-account', ['account_id' => $account->id])->assertRedirect();
    expect($this->user->setting()->first()->yearly_bills_account_id)->toBe($account->id);

    $this->actingAs($this->user)->patch('/money/yearly-bills-account', ['account_id' => null])->assertRedirect();
    expect($this->user->setting()->first()->yearly_bills_account_id)->toBeNull();
});

test('another user\'s account cannot be picked', function (): void {
    $other = Account::factory()->for(User::factory())->create();

    $this->actingAs($this->user)->patch('/money/yearly-bills-account', ['account_id' => $other->id])->assertSessionHasErrors('account_id');
});
