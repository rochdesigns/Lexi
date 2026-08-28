<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use App\Services\DebtFocus\LedgerService;

test('guests are redirected to login', function () {
    $this->get('/ledger')->assertRedirect('/login');
});

test('renders empty state for a user with no accounts', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/ledger')->assertOk();

    expect($response->viewData('page')['props']['accounts'])->toBe([]);
});

test('renders an account with its balance and recent transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Main Checking', 'balance' => 100]);
    (new LedgerService)->recordIncome($account, 50, 'Paycheck');
    (new LedgerService)->recordExpense($account, 20, 'Groceries');

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $props = $response->viewData('page')['props']['accounts'];

    expect($props)->toHaveCount(1);
    expect($props[0]['name'])->toBe('Main Checking');
    expect((float) $props[0]['balance'])->toBe(130.0);
    expect($props[0]['recent_transactions'])->toHaveCount(2);
});

test('a transfer shows the counterpart account and correct direction on each side', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking']);
    $savings = Account::factory()->for($user)->create(['name' => 'Savings']);
    (new LedgerService)->recordTransfer($checking, $savings, 100, 'Move to savings');

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $accounts = collect($response->viewData('page')['props']['accounts'])->keyBy('name');

    $checkingTx = $accounts['Checking']['recent_transactions'][0];
    expect($checkingTx['direction'])->toBe('out');
    expect($checkingTx['counterpart'])->toBe('Savings');

    $savingsTx = $accounts['Savings']['recent_transactions'][0];
    expect($savingsTx['direction'])->toBe('in');
    expect($savingsTx['counterpart'])->toBe('Checking');
});

test('recent transactions are limited to 10 and ordered most recent first', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $service = new LedgerService;

    foreach (range(1, 12) as $i) {
        $service->recordIncome($account, 1, "Deposit {$i}", now()->subDays(12 - $i));
    }

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $transactions = $response->viewData('page')['props']['accounts'][0]['recent_transactions'];

    expect($transactions)->toHaveCount(10);
    expect($transactions[0]['description'])->toBe('Deposit 12');
});

test('stores a new account for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/ledger/accounts', [
        'name' => 'New Savings', 'institution' => 'BPI', 'type' => 'savings', 'is_liability' => false, 'balance' => 500,
    ])->assertRedirect();

    $account = Account::where('user_id', $user->id)->firstOrFail();
    expect($account->name)->toBe('New Savings');
    expect((float) $account->balance)->toBe(500.0);
});

test('storing an account requires a name', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/ledger/accounts', [
        'type' => 'checking', 'is_liability' => false, 'balance' => 0,
    ])->assertSessionHasErrors('name');
});

test('updates an owned account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Old name']);

    $this->actingAs($user)->patch("/ledger/accounts/{$account->id}", [
        'name' => 'New name', 'type' => 'checking', 'is_liability' => false, 'balance' => 200,
    ])->assertRedirect();

    expect($account->fresh()->name)->toBe('New name');
});

test('an account with a negative balance can still be updated', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Old name', 'balance' => -50]);

    $this->actingAs($user)->patch("/ledger/accounts/{$account->id}", [
        'name' => 'New name', 'type' => 'checking', 'is_liability' => false, 'balance' => -50,
    ])->assertRedirect()->assertSessionHasNoErrors();

    expect($account->fresh()->name)->toBe('New name');
});

test('cannot update another user\'s account', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = Account::factory()->for($owner)->create();

    $this->actingAs($intruder)->patch("/ledger/accounts/{$account->id}", [
        'name' => 'Hacked', 'type' => 'checking', 'is_liability' => false, 'balance' => 1,
    ])->assertForbidden();
});

test('deletes an owned account and cascades its transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($account, 10, 'Deposit');

    $this->actingAs($user)->delete("/ledger/accounts/{$account->id}")->assertRedirect();

    expect(Account::find($account->id))->toBeNull();
    expect(Transaction::where('account_id', $account->id)->exists())->toBeFalse();
});

test('cannot delete another user\'s account', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = Account::factory()->for($owner)->create();

    $this->actingAs($intruder)->delete("/ledger/accounts/{$account->id}")->assertForbidden();

    expect(Account::find($account->id))->not->toBeNull();
});

test('deleting an account also deletes any debt linked to it', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create();
    $debt = Debt::factory()->for($user)->create(['linked_account_id' => $account->id]);

    $this->actingAs($user)->delete("/ledger/accounts/{$account->id}")->assertRedirect();

    expect(Account::find($account->id))->toBeNull();
    expect(Debt::find($debt->id))->toBeNull();
});

test('an account with a linked debt lists its name for the delete warning', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create();
    Debt::factory()->for($user)->create(['name' => 'Maya CC', 'linked_account_id' => $account->id]);

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $props = $response->viewData('page')['props']['accounts'][0];

    expect($props['linked_debt_names'])->toBe(['Maya CC']);
});

test('an account with no linked debt exposes an empty linked_debt_names list', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $props = $response->viewData('page')['props']['accounts'][0];

    expect($props['linked_debt_names'])->toBe([]);
});

test('deleting one side of a transfer preserves the transaction for the surviving account', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking']);
    $savings = Account::factory()->for($user)->create(['name' => 'Savings']);
    (new LedgerService)->recordTransfer($checking, $savings, 100, 'Move to savings');

    $this->actingAs($user)->delete("/ledger/accounts/{$checking->id}")->assertRedirect();

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $accounts = collect($response->viewData('page')['props']['accounts'])->keyBy('name');

    expect($accounts)->toHaveKey('Savings');
    $tx = $accounts['Savings']['recent_transactions'][0];
    expect($tx['counterpart'])->toBe('(deleted account)');
    expect($tx['direction'])->toBe('in');
    expect((float) $tx['amount'])->toBe(100.0);
});

test('logs an income transaction against an owned account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'income',
        'account_id' => $account->id,
        'amount' => 50,
        'description' => 'Paycheck',
        'occurred_at' => '2026-08-28',
    ])->assertRedirect();

    expect((float) $account->fresh()->balance)->toBe(150.0);
    $transaction = Transaction::where('account_id', $account->id)->firstOrFail();
    expect($transaction->type)->toBe('income');
    expect($transaction->description)->toBe('Paycheck');
});

test('logs an expense transaction against an owned account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'expense',
        'account_id' => $account->id,
        'amount' => 30,
        'occurred_at' => '2026-08-28',
    ])->assertRedirect();

    expect((float) $account->fresh()->balance)->toBe(70.0);
});

test('logs a transfer between two owned accounts', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 500]);
    $savings = Account::factory()->for($user)->create(['balance' => 200]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'transfer',
        'from_account_id' => $checking->id,
        'to_account_id' => $savings->id,
        'amount' => 100,
        'occurred_at' => '2026-08-28',
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(400.0);
    expect((float) $savings->fresh()->balance)->toBe(300.0);
});

test('cannot log a transaction against another user\'s account', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = Account::factory()->for($owner)->create(['balance' => 100]);

    $this->actingAs($intruder)->post('/ledger/transactions', [
        'type' => 'expense',
        'account_id' => $account->id,
        'amount' => 10,
        'occurred_at' => '2026-08-28',
    ])->assertSessionHasErrors('account_id');

    expect((float) $account->fresh()->balance)->toBe(100.0);
});

test('a transfer requires two different accounts', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'transfer',
        'from_account_id' => $account->id,
        'to_account_id' => $account->id,
        'amount' => 10,
        'occurred_at' => '2026-08-28',
    ])->assertSessionHasErrors('from_account_id');
});

test('transaction amount must be positive', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'income',
        'account_id' => $account->id,
        'amount' => 0,
        'occurred_at' => '2026-08-28',
    ])->assertSessionHasErrors('amount');
});

test('transaction amount cannot exceed the amount column\'s precision', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'income',
        'account_id' => $account->id,
        'amount' => 100000000,
        'occurred_at' => '2026-08-28',
    ])->assertSessionHasErrors('amount');
});

test('transaction date must be in Y-m-d format', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'income',
        'account_id' => $account->id,
        'amount' => 10,
        'occurred_at' => 'next monday',
    ])->assertSessionHasErrors('occurred_at');
});

test('deleting both accounts on a transfer removes the now-fully-orphaned transaction', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking']);
    $savings = Account::factory()->for($user)->create(['name' => 'Savings']);
    (new LedgerService)->recordTransfer($checking, $savings, 100, 'Move to savings');
    $transaction = Transaction::where('type', 'transfer')->firstOrFail();

    $this->actingAs($user)->delete("/ledger/accounts/{$checking->id}")->assertRedirect();
    expect(Transaction::find($transaction->id))->not->toBeNull();

    $this->actingAs($user)->delete("/ledger/accounts/{$savings->id}")->assertRedirect();

    expect(Transaction::find($transaction->id))->toBeNull();
});

test('logging an expense with an owned envelope tags the transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100]);
    $envelope = Envelope::factory()->for($user)->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'expense', 'account_id' => $account->id, 'amount' => 20,
        'occurred_at' => '2026-08-28', 'envelope_id' => $envelope->id,
    ])->assertRedirect();

    $transaction = Transaction::where('account_id', $account->id)->firstOrFail();
    expect($transaction->envelope_id)->toBe($envelope->id);
});

test('cannot tag a transaction with another user\'s envelope', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $account = Account::factory()->for($intruder)->create(['balance' => 100]);
    $envelope = Envelope::factory()->for($owner)->create();

    $this->actingAs($intruder)->post('/ledger/transactions', [
        'type' => 'expense', 'account_id' => $account->id, 'amount' => 20,
        'occurred_at' => '2026-08-28', 'envelope_id' => $envelope->id,
    ])->assertSessionHasErrors('envelope_id');
});

test('envelope_id is rejected on income and transfer transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100]);
    $envelope = Envelope::factory()->for($user)->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'income', 'account_id' => $account->id, 'amount' => 20,
        'occurred_at' => '2026-08-28', 'envelope_id' => $envelope->id,
    ])->assertSessionHasErrors('envelope_id');
});

test('the ledger page exposes the active envelope list', function () {
    $user = User::factory()->create();
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'active' => true]);
    Envelope::factory()->for($user)->create(['name' => 'Old one', 'active' => false]);

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $envelopes = collect($response->viewData('page')['props']['envelopes'])->pluck('name');

    expect($envelopes->all())->toBe(['Groceries']);
});
