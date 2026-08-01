<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
});

test('guest is redirected from debt', function (): void {
    $this->post('/logout');
    $this->get('/debt')->assertRedirect('/login');
});

test('debt page renders with hero aggregates', function (): void {
    Debt::factory()->for($this->user)->create(['balance' => 8400, 'original' => 15000, 'per_month' => 310]);
    Debt::factory()->for($this->user)->create(['balance' => 540, 'original' => 900, 'per_month' => 45]);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->component('Debt')
            ->where('debtTotal', 8940)
            ->where('debtOriginal', 15900)
            ->where('debtMonthly', 355)
            ->where('debtCount', 2)
            ->has('debts', 2)
        );
});

test('debt page computes paid-off percentage', function (): void {
    Debt::factory()->for($this->user)->create(['balance' => 1000, 'original' => 4000, 'per_month' => 200]);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->where('debtPaidPct', 75)
        );
});

test('debt page computes months_left per debt', function (): void {
    Debt::factory()->for($this->user)->create(['balance' => 600, 'original' => 1200, 'per_month' => 100]);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->where('debts.0.months_left', 6)
        );
});

test('debt page computes debt_free_date from longest months_left', function (): void {
    Debt::factory()->for($this->user)->create(['balance' => 600, 'original' => 1200, 'per_month' => 100]);
    Debt::factory()->for($this->user)->create(['balance' => 2000, 'original' => 4000, 'per_month' => 200]);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->whereNot('debtFreeDate', null)
        );
});

test('store creates a debt with auto position', function (): void {
    Debt::factory()->for($this->user)->create(['position' => 1]);

    $this->post('/debts', [
        'name' => 'Car loan',
        'icon' => 'directions_car',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 8400,
        'original' => 15000,
        'per_month' => 310,
        'due_day' => 5,
    ])->assertRedirect();

    $debt = $this->user->debts()->where('name', 'Car loan')->first();
    expect($debt->position)->toBe(2);
    expect((float) $debt->balance)->toBe(8400.0);
});

test('store validates required fields', function (): void {
    $this->post('/debts', [])->assertSessionHasErrors(['name', 'icon', 'color', 'bg', 'balance', 'original', 'per_month']);
});

test('store validates color hex format', function (): void {
    $this->post('/debts', [
        'name' => 'Test',
        'icon' => 'credit_card',
        'color' => 'not-a-hex',
        'bg' => '#dcebf5',
        'balance' => 1000,
        'original' => 2000,
        'per_month' => 100,
    ])->assertSessionHasErrors('color');
});

test('update edits a debt', function (): void {
    $debt = Debt::factory()->for($this->user)->create(['name' => 'Old name']);

    $this->patch("/debts/{$debt->id}", [
        'name' => 'New name',
        'icon' => 'credit_card',
        'color' => '#2f7d5b',
        'bg' => '#dff3e6',
        'balance' => 500,
        'original' => 1000,
        'per_month' => 100,
        'due_day' => null,
    ])->assertRedirect();

    expect($debt->fresh()->name)->toBe('New name');
});

test('update returns 403 for another user\'s debt', function (): void {
    $other = User::factory()->create();
    $debt = Debt::factory()->for($other)->create();

    $this->patch("/debts/{$debt->id}", [
        'name' => 'Hacked',
        'icon' => 'credit_card',
        'color' => '#2f7d5b',
        'bg' => '#dff3e6',
        'balance' => 0,
        'original' => 100,
        'per_month' => 10,
    ])->assertForbidden();
});

test('make-payment decrements debt balance and account balance, creates transaction', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 10000]);
    $debt = Debt::factory()->for($this->user)->create(['balance' => 500, 'name' => 'Car loan']);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 200,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-21',
    ])->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(300.0);
    expect((float) $account->fresh()->balance)->toBe(9800.0);
    expect(Transaction::where('account_id', $account->id)->where('amount', 200)->exists())->toBeTrue();
});

test('make-payment rejects amount exceeding account balance', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 100]);
    $debt = Debt::factory()->for($this->user)->create(['balance' => 500]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 9999,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-22',
    ])->assertSessionHasErrors('amount');

    expect((float) $account->fresh()->balance)->toBe(100.0);
    expect((float) $debt->fresh()->balance)->toBe(500.0);
});

test('make-payment floors debt balance at zero', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 10000]);
    $debt = Debt::factory()->for($this->user)->create(['balance' => 100]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 9999,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-21',
    ])->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(0.0);
});

test('make-payment defaults merchant to debt name', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 10000]);
    $debt = Debt::factory()->for($this->user)->create(['balance' => 500, 'name' => 'iPhone installment']);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 100,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-21',
    ])->assertRedirect();

    $tx = Transaction::where('account_id', $account->id)->first();
    expect($tx->merchant)->toBe('iPhone installment');
});

test('make-payment returns 403 for another user\'s debt', function (): void {
    $other = User::factory()->create();
    $account = Account::factory()->for($this->user)->create(['balance' => 10000]);
    $debt = Debt::factory()->for($other)->create(['balance' => 500]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 100,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-21',
    ])->assertForbidden();

    expect((float) $debt->fresh()->balance)->toBe(500.0);
    expect((float) $account->fresh()->balance)->toBe(10000.0);
});

test('destroy deletes a debt', function (): void {
    $debt = Debt::factory()->for($this->user)->create();

    $this->delete("/debts/{$debt->id}")->assertRedirect();

    expect(Debt::find($debt->id))->toBeNull();
});

test('destroy returns 403 for another user\'s debt', function (): void {
    $other = User::factory()->create();
    $debt = Debt::factory()->for($other)->create();

    $this->delete("/debts/{$debt->id}")->assertForbidden();
    expect(Debt::find($debt->id))->not->toBeNull();
});

test('budget_category_id is stored when creating a debt', function (): void {
    $category = BudgetCategory::factory()->for($this->user)->create();

    $this->post('/debts', [
        'budget_category_id' => $category->id,
        'name' => 'Car loan',
        'icon' => 'directions_car',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 100000,
        'original' => 200000,
        'per_month' => 5000,
    ])->assertRedirect();

    $debt = Debt::where('name', 'Car loan')->first();
    expect($debt->budget_category_id)->toBe($category->id);
});

test('budget_category_id is nullable when creating a debt', function (): void {
    $this->post('/debts', [
        'name' => 'Car loan',
        'icon' => 'directions_car',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 100000,
        'original' => 200000,
        'per_month' => 5000,
    ])->assertRedirect();

    expect(Debt::where('name', 'Car loan')->first()->budget_category_id)->toBeNull();
});

test('budget_category_id is returned in the debts index', function (): void {
    $category = BudgetCategory::factory()->for($this->user)->create();
    Debt::factory()->for($this->user)->create(['budget_category_id' => $category->id]);

    $this->get('/debt')->assertInertia(
        fn ($page) => $page->where('debts.0.budget_category_id', $category->id)
    );
});

test('make-payment stamps budget_category_id on the transaction', function (): void {
    $category = BudgetCategory::factory()->for($this->user)->create();
    $account = Account::factory()->for($this->user)->create(['balance' => 10000]);
    $debt = Debt::factory()->for($this->user)->create([
        'balance' => 500,
        'budget_category_id' => $category->id,
    ]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 200,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-23',
    ])->assertRedirect();

    $tx = Transaction::where('account_id', $account->id)->where('amount', 200)->first();
    expect($tx->budget_category_id)->toBe($category->id);
});

test('make-payment transaction has null budget_category_id when debt has no category', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 10000]);
    $debt = Debt::factory()->for($this->user)->create(['balance' => 500, 'budget_category_id' => null]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 200,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-23',
    ])->assertRedirect();

    $tx = Transaction::where('account_id', $account->id)->where('amount', 200)->first();
    expect($tx->budget_category_id)->toBeNull();
});

test('debt page includes accounts prop', function (): void {
    Account::factory()->for($this->user)->create(['name' => 'BDO Checking']);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->component('Debt')
            ->has('accounts', 1, fn ($a) => $a->where('name', 'BDO Checking')->etc())
        );
});

test('due_label is computed correctly from due_day', function (): void {
    Debt::factory()->for($this->user)->create(['balance' => 1000, 'original' => 2000, 'per_month' => 100, 'due_day' => 28]);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->whereNot('debts.0.due_label', null)
        );
});

test('interest_rate is stored when creating a debt', function (): void {
    $this->post('/debts', [
        'name' => 'Credit card',
        'icon' => 'credit_card',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 5000,
        'original' => 10000,
        'per_month' => 200,
        'interest_rate' => 19.99,
    ])->assertRedirect();

    $debt = $this->user->debts()->where('name', 'Credit card')->first();
    expect((float) $debt->interest_rate)->toBe(19.99);
});

test('interest_rate is nullable when creating a debt', function (): void {
    $this->post('/debts', [
        'name' => 'Car loan',
        'icon' => 'directions_car',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 8000,
        'original' => 15000,
        'per_month' => 300,
    ])->assertRedirect();

    $debt = $this->user->debts()->where('name', 'Car loan')->first();
    expect($debt->interest_rate)->toBeNull();
});

test('interest_rate is validated between 0 and 100', function (): void {
    $this->post('/debts', [
        'name' => 'Test',
        'icon' => 'credit_card',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 1000,
        'original' => 2000,
        'per_month' => 100,
        'interest_rate' => 150,
    ])->assertSessionHasErrors('interest_rate');
});

test('interest_rate is returned in the debts index', function (): void {
    Debt::factory()->for($this->user)->create(['interest_rate' => 24.0]);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->where('debts.0.interest_rate', 24)
        );
});

test('debt can be linked to a credit account', function (): void {
    $account = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 5000]);
    $debt = Debt::factory()->for($this->user)->create(['account_id' => $account->id, 'balance' => 5000]);

    expect($debt->account->id)->toBe($account->id);
    expect($account->linkedDebt->id)->toBe($debt->id);
});

test('syncLinkedDebt updates debt balance and per_month from account', function (): void {
    $account = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 10000]);
    $debt = Debt::factory()->for($this->user)->create([
        'account_id' => $account->id,
        'balance' => 0,
        'minimum_payment_rate' => 3.00,
        'per_month' => 0,
    ]);

    $account->syncLinkedDebt();

    $debt->refresh();
    expect((float) $debt->balance)->toBe(10000.0);
    expect((float) $debt->per_month)->toBe(300.0); // 10000 * 3% = 300
});

test('syncLinkedDebt does nothing when no linked debt', function (): void {
    $account = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 5000]);
    // No debt linked — should not throw
    $account->syncLinkedDebt();
    expect(true)->toBeTrue();
});

test('makePayment on linked CC debt decrements CC account and syncs debt', function (): void {
    $source = Account::factory()->for($this->user)->create(['type' => 'cash', 'balance' => 20000]);
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 15000]);
    $debt = Debt::factory()->for($this->user)->create([
        'account_id' => $cc->id,
        'balance' => 15000,
        'minimum_payment_rate' => 3.00,
        'per_month' => 450,
    ]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 5000,
        'account_id' => $source->id,
        'merchant' => 'HSBC CC Payment',
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $source->fresh()->balance)->toBe(15000.0); // 20000 - 5000
    expect((float) $cc->fresh()->balance)->toBe(10000.0);     // 15000 - 5000
    $debt->refresh();
    expect((float) $debt->balance)->toBe(10000.0);            // synced from CC account
    expect((float) $debt->per_month)->toBe(300.0);            // 10000 * 3% = 300
});

test('makePayment on unlinked debt still works as before', function (): void {
    $source = Account::factory()->for($this->user)->create(['type' => 'cash', 'balance' => 10000]);
    $debt = Debt::factory()->for($this->user)->create(['balance' => 8000, 'per_month' => 500]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 2000,
        'account_id' => $source->id,
        'merchant' => 'Loan Payment',
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $source->fresh()->balance)->toBe(8000.0);
    expect((float) $debt->fresh()->balance)->toBe(6000.0);
});

test('debt index passes creditAccounts prop', function (): void {
    Account::factory()->for($this->user)->create(['type' => 'credit', 'name' => 'HSBC CC']);
    Account::factory()->for($this->user)->create(['type' => 'cash', 'name' => 'BPI Savings']);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->has('creditAccounts', 1)
            ->where('creditAccounts.0.name', 'HSBC CC')
        );
});

test('store accepts account_id and minimum_payment_rate', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 10000]);

    $this->post('/debts', [
        'name' => 'HSBC CC',
        'icon' => 'credit_card',
        'color' => '#c0512c',
        'bg' => '#fbe2d6',
        'balance' => 10000,
        'original' => 50000,
        'per_month' => 300,
        'account_id' => $cc->id,
        'minimum_payment_rate' => 3.00,
    ])->assertRedirect();

    $debt = $this->user->debts()->where('name', 'HSBC CC')->first();
    expect($debt->account_id)->toBe($cc->id);
    expect((float) $debt->minimum_payment_rate)->toBe(3.0);
});

test('store creates linked CC debt with per_month zero', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 10000]);

    $this->post('/debts', [
        'name' => 'HSBC CC',
        'icon' => 'credit_card',
        'color' => '#c0512c',
        'bg' => '#fbe2d6',
        'balance' => 10000,
        'original' => 50000,
        'per_month' => 0,
        'account_id' => $cc->id,
        'minimum_payment_rate' => 3.00,
    ])->assertRedirect();

    $debt = $this->user->debts()->where('name', 'HSBC CC')->first();
    expect($debt)->not->toBeNull();
    expect($debt->account_id)->toBe($cc->id);
    expect((float) $debt->per_month)->toBe(0.0);
});

test('cannot link debt to another user\'s account', function (): void {
    $other = User::factory()->create();
    $otherCc = Account::factory()->for($other)->create(['type' => 'credit', 'balance' => 5000]);

    $this->post('/debts', [
        'name' => 'Stolen CC',
        'icon' => 'credit_card',
        'color' => '#c0512c',
        'bg' => '#fbe2d6',
        'balance' => 5000,
        'original' => 10000,
        'per_month' => 0,
        'account_id' => $otherCc->id,
    ])->assertSessionHasErrors('account_id');
});

test('cannot link debt to a non-credit account', function (): void {
    $cash = Account::factory()->for($this->user)->create(['type' => 'cash', 'balance' => 5000]);

    $this->post('/debts', [
        'name' => 'Invalid link',
        'icon' => 'credit_card',
        'color' => '#c0512c',
        'bg' => '#fbe2d6',
        'balance' => 5000,
        'original' => 10000,
        'per_month' => 0,
        'account_id' => $cash->id,
    ])->assertSessionHasErrors('account_id');
});

test('debt index returns account_id and minimum_payment_rate per debt', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 9000]);
    Debt::factory()->for($this->user)->create([
        'account_id' => $cc->id,
        'minimum_payment_rate' => 3.00,
        'balance' => 9000,
        'per_month' => 270,
    ]);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->where('debts.0.account_id', $cc->id)
            ->where('debts.0.minimum_payment_rate', 3)
        );
});
