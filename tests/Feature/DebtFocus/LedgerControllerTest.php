<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\Settings;
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

test('renders an account with its balance and this month\'s activity', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Main Checking', 'balance' => 100]);
    (new LedgerService)->recordIncome($account, 50, 'Paycheck');
    (new LedgerService)->recordExpense($account, 20, 'Groceries');

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['accounts'])->toHaveCount(1);
    expect($props['accounts'][0]['name'])->toBe('Main Checking');
    expect((float) $props['accounts'][0]['balance'])->toBe(130.0);
    expect($props['selectedAccountId'])->toBe($account->id);
    expect($props['activity']['entries'])->toHaveCount(2);
});

test('a transfer reads the right way round on each side of it', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking']);
    $savings = Account::factory()->for($user)->create(['name' => 'Savings']);
    (new LedgerService)->recordTransfer($checking, $savings, 100);

    $fromChecking = $this->actingAs($user)->get("/ledger?account={$checking->id}")->assertOk()
        ->viewData('page')['props']['activity']['entries'][0];

    expect($fromChecking['direction'])->toBe('out');
    expect($fromChecking['title'])->toBe('Transfer to Savings');

    $fromSavings = $this->actingAs($user)->get("/ledger?account={$savings->id}")->assertOk()
        ->viewData('page')['props']['activity']['entries'][0];

    expect($fromSavings['direction'])->toBe('in');
    expect($fromSavings['title'])->toBe('Transfer from Checking');
});

test('activity is ordered most recent first', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $service = new LedgerService;

    foreach (range(1, 5) as $i) {
        $service->recordIncome($account, 1, "Deposit {$i}", now()->startOfMonth()->addDays($i));
    }

    $entries = $this->actingAs($user)->get('/ledger')->assertOk()
        ->viewData('page')['props']['activity']['entries'];

    expect($entries)->toHaveCount(5);
    expect($entries[0]['title'])->toBe('Deposit 5');
    expect($entries[4]['title'])->toBe('Deposit 1');
});

test('activity is scoped to the selected account', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking']);
    $cash = Account::factory()->for($user)->create(['name' => 'Cash']);
    (new LedgerService)->recordExpense($checking, 10, 'Card lunch');
    (new LedgerService)->recordExpense($cash, 20, 'Cash lunch');

    $entries = $this->actingAs($user)->get("/ledger?account={$cash->id}")->assertOk()
        ->viewData('page')['props']['activity']['entries'];

    expect($entries)->toHaveCount(1);
    expect($entries[0]['title'])->toBe('Cash lunch');
});

test('the rail defaults to the first asset account, never a liability', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->liability()->create(['name' => 'A card']);
    $checking = Account::factory()->for($user)->create(['name' => 'Z checking']);

    $props = $this->actingAs($user)->get('/ledger')->assertOk()->viewData('page')['props'];

    expect($props['selectedAccountId'])->toBe($checking->id);
});

test('an account belonging to someone else cannot be selected', function () {
    $user = User::factory()->create();
    $own = Account::factory()->for($user)->create();
    $other = Account::factory()->for(User::factory()->create())->create();

    $props = $this->actingAs($user)->get("/ledger?account={$other->id}")->assertOk()->viewData('page')['props'];

    expect($props['selectedAccountId'])->toBe($own->id);
});

test('the last three months range widens the activity window', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    (new LedgerService)->recordExpense($account, 10, 'Two months ago', now()->startOfMonth()->subMonthsNoOverflow(2));
    (new LedgerService)->recordExpense($account, 20, 'This month', now()->startOfMonth());

    $thisMonth = $this->actingAs($user)->get('/ledger')->assertOk()->viewData('page')['props']['activity']['entries'];
    expect($thisMonth)->toHaveCount(1);

    $lastThree = $this->actingAs($user)->get('/ledger?range=last_3')->assertOk()->viewData('page')['props']['activity']['entries'];
    expect($lastThree)->toHaveCount(2);
});

test('a custom range requires both ends and rejects a backwards window', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create();

    $this->actingAs($user)->get('/ledger?range=custom')->assertSessionHasErrors(['from', 'to']);
    $this->actingAs($user)->get('/ledger?range=custom&from=2026-09-30&to=2026-09-01')->assertSessionHasErrors('to');
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
    expect(Transaction::where('account_id', $account->id)->exists())->toBeFalse();
});

test('raising an asset account balance records the difference as income', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 1000]);

    $this->actingAs($user)->patch("/ledger/accounts/{$account->id}", [
        'name' => $account->name, 'type' => $account->type, 'is_liability' => false, 'balance' => 1500,
    ])->assertRedirect();

    expect((float) $account->fresh()->balance)->toBe(1500.0);
    $transaction = Transaction::where('account_id', $account->id)->sole();
    expect($transaction->type)->toBe('income');
    expect((float) $transaction->amount)->toBe(500.0);
    expect($transaction->description)->toBe('Balance correction');
});

test('lowering a liability balance owed records the difference as income', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 5000]);

    $this->actingAs($user)->patch("/ledger/accounts/{$account->id}", [
        'name' => $account->name, 'type' => $account->type, 'is_liability' => true, 'balance' => 0,
    ])->assertRedirect();

    expect((float) $account->fresh()->balance)->toBe(0.0);
    $transaction = Transaction::where('account_id', $account->id)->sole();
    expect($transaction->type)->toBe('income');
    expect((float) $transaction->amount)->toBe(5000.0);
});

test('raising a liability balance owed records the difference as an expense', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 5000]);

    $this->actingAs($user)->patch("/ledger/accounts/{$account->id}", [
        'name' => $account->name, 'type' => $account->type, 'is_liability' => true, 'balance' => 7000,
    ])->assertRedirect();

    expect((float) $account->fresh()->balance)->toBe(7000.0);
    $transaction = Transaction::where('account_id', $account->id)->sole();
    expect($transaction->type)->toBe('expense');
    expect((float) $transaction->amount)->toBe(2000.0);
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

    $props = $this->actingAs($user)->get('/ledger')->assertOk()->viewData('page')['props'];

    expect(collect($props['accounts'])->pluck('name')->all())->toBe(['Savings']);
    $entry = $props['activity']['entries'][0];
    expect($entry['title'])->toBe('Move to savings');
    expect($entry['direction'])->toBe('in');
    expect((float) $entry['amount'])->toBe(100.0);
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

test('exposes net worth totals across asset and liability accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 500]);
    Account::factory()->for($user)->create(['balance' => 300]);
    Account::factory()->for($user)->liability()->create(['balance' => 200]);

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $totals = $response->viewData('page')['props']['totals'];

    expect($totals['assets'])->toBe(800.0);
    expect($totals['liabilities'])->toBe(200.0);
    expect($totals['netWorth'])->toBe(600.0);
});

test('net worth totals are zero for a user with no accounts', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $totals = $response->viewData('page')['props']['totals'];

    expect($totals)->toBe(['assets' => 0.0, 'liabilities' => 0.0, 'netWorth' => 0.0, 'changeThisMonth' => null]);
});

test('a user with no accounts gets an empty hero, rail and feed rather than an error', function () {
    $user = User::factory()->create();

    $props = $this->actingAs($user)->get('/ledger')->assertOk()->viewData('page')['props'];

    expect($props['netWorthHistory'])->toBe([]);
    expect($props['selectedAccountId'])->toBeNull();
    expect($props['flow'])->toBeNull();
    expect($props['totalFlow'])->toBeNull();
    expect($props['activity']['entries'])->toBe([]);
});

test('the net worth sparkline ends on today\'s net worth and rises with income', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create([
        'balance' => 0,
        'created_at' => now()->startOfMonth()->subMonthsNoOverflow(3),
    ]);
    (new LedgerService)->recordIncome($account, 500, 'Paycheck', now()->startOfMonth());

    $props = $this->actingAs($user)->get('/ledger')->assertOk()->viewData('page')['props'];
    $history = $props['netWorthHistory'];

    expect($history)->toHaveCount(4);
    expect($history[0]['value'])->toBe(0.0);
    expect(end($history)['value'])->toBe(500.0);
    expect($props['totals']['netWorth'])->toBe(500.0);
    expect($props['totals']['changeThisMonth'])->toBe(500.0);
});

test('months before the first account existed are left off the sparkline', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create([
        'balance' => 100,
        'created_at' => now()->startOfMonth()->subMonthsNoOverflow(1),
    ]);

    $history = $this->actingAs($user)->get('/ledger')->assertOk()
        ->viewData('page')['props']['netWorthHistory'];

    expect($history)->toHaveCount(2);
    expect($history[0]['month'])->toBe(now()->startOfMonth()->subMonthsNoOverflow(1)->format('Y-m'));
    expect($history[0]['value'])->toBe(100.0);
});

test('a transfer between two owned accounts leaves net worth untouched', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $card = Account::factory()->for($user)->liability()->create(['balance' => 400]);
    (new LedgerService)->recordTransfer($checking, $card, 100, 'Card payment', now()->startOfMonth());

    $props = $this->actingAs($user)->get('/ledger')->assertOk()->viewData('page')['props'];

    expect($props['totals']['netWorth'])->toBe(600.0);
    expect(end($props['netWorthHistory'])['value'])->toBe(600.0);
});

test('the flow card splits what left into bills, debt, savings and living', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking', 'balance' => 0]);
    $savings = Account::factory()->for($user)->create(['name' => 'Savings', 'type' => 'savings']);
    $card = Account::factory()->for($user)->liability()->create(['name' => 'Card']);
    $service = new LedgerService;
    $month = now()->startOfMonth();

    $service->recordIncome($checking, 1000, 'Payroll', $month);
    $service->recordExpense($checking, 100, 'Bill: Internet', $month);
    $service->recordTransfer($checking, $card, 200, 'Card payment', $month);
    $service->recordTransfer($checking, $savings, 300, 'Emergency fund', $month);
    $service->recordExpense($checking, 50, 'Lunch', $month);

    $flow = $this->actingAs($user)->get("/ledger?account={$checking->id}")->assertOk()
        ->viewData('page')['props']['flow'];

    expect($flow['in'])->toBe(1000.0);
    expect($flow['out'])->toBe(650.0);
    expect($flow['stayed'])->toBe(350.0);
    expect(collect($flow['out_segments'])->pluck('amount', 'key')->all())
        ->toBe(['bills' => 100.0, 'debt' => 200.0, 'saved' => 300.0, 'living' => 50.0]);
    expect($flow['debt_share_of_income'])->toBe(0.2);
    expect($flow['in_sources'])->toBe([['label' => 'Payroll', 'amount' => 1000.0]]);
});

test('the total flow rolls every account up and leaves internal moves out of it', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking', 'type' => 'checking', 'balance' => 0]);
    $cash = Account::factory()->for($user)->create(['name' => 'Cash', 'type' => 'cash']);
    $savings = Account::factory()->for($user)->create(['name' => 'Savings', 'type' => 'savings']);
    $card = Account::factory()->for($user)->liability()->create(['name' => 'Card']);
    $service = new LedgerService;
    $month = now()->startOfMonth();

    $service->recordIncome($checking, 1000, 'Payroll', $month);
    $service->recordIncome($cash, 200, 'Side gig', $month);
    $service->recordExpense($checking, 100, 'Bill: Internet', $month);
    $service->recordTransfer($checking, $card, 200, 'Card payment', $month);
    $service->recordTransfer($checking, $savings, 300, 'Emergency fund', $month);
    $service->recordExpense($checking, 50, 'Lunch', $month);
    // Neither of these is income or spending — the money never left the user.
    $service->recordTransfer($savings, $checking, 40, 'Topping up', $month);
    $service->recordTransfer($cash, $checking, 75, 'Bank run', $month);

    $flow = $this->actingAs($user)->get('/ledger')->assertOk()
        ->viewData('page')['props']['totalFlow'];

    expect($flow['in'])->toBe(1200.0);
    expect($flow['out'])->toBe(650.0);
    expect($flow['stayed'])->toBe(550.0);
    expect(collect($flow['out_segments'])->pluck('amount', 'key')->all())
        ->toBe(['bills' => 100.0, 'debt' => 200.0, 'saved' => 300.0, 'living' => 50.0]);
    expect($flow['in_sources'])->toBe([
        ['label' => 'Payroll', 'amount' => 1000.0],
        ['label' => 'Side gig', 'amount' => 200.0],
    ]);
});

test('the total flow counts cash drawn off a card as money in and the card payment as money out', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking', 'balance' => 0]);
    $card = Account::factory()->for($user)->liability()->create(['name' => 'Card']);
    $service = new LedgerService;
    $month = now()->startOfMonth();

    $service->recordTransfer($card, $checking, 400, 'Cash advance', $month);
    $service->recordTransfer($checking, $card, 150, 'Card payment', $month);

    $flow = $this->actingAs($user)->get('/ledger')->assertOk()
        ->viewData('page')['props']['totalFlow'];

    expect($flow['in'])->toBe(400.0);
    expect($flow['in_sources'])->toBe([['label' => 'From Card', 'amount' => 400.0]]);
    expect($flow['out'])->toBe(150.0);
    expect(collect($flow['out_segments'])->pluck('amount', 'key')->all())->toBe(['debt' => 150.0]);
    expect($flow['debt_share_of_income'])->toBe(0.375);
});

test('a payment on a debt with no ledger account still lands in the total debt segment', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking', 'balance' => 5000]);
    $debt = Debt::factory()->for($user)->create(['name' => 'SSS Loan', 'balance' => 9750, 'linked_account_id' => null]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'debt',
        'debt_id' => $debt->id,
        'from_account_id' => $checking->id,
        'amount' => 900,
        'occurred_at' => now()->startOfMonth()->toDateString(),
    ])->assertRedirect();

    $flow = $this->actingAs($user)->get('/ledger')->assertOk()
        ->viewData('page')['props']['totalFlow'];

    expect($flow['out'])->toBe(900.0);
    expect(collect($flow['out_segments'])->pluck('amount', 'key')->all())->toBe(['debt' => 900.0]);
});

test('a debt payment is labelled as debt in the activity feed', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking']);
    $card = Account::factory()->for($user)->liability()->create(['name' => 'Card']);
    (new LedgerService)->recordTransfer($checking, $card, 200, 'Card payment');

    $entry = $this->actingAs($user)->get("/ledger?account={$checking->id}")->assertOk()
        ->viewData('page')['props']['activity']['entries'][0];

    expect($entry['kind'])->toBe('debt');
    expect($entry['icon'])->toBe('credit_card');
});

test('an expense carries its envelope through to the activity feed', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $envelope = Envelope::factory()->for($user)->create(['name' => 'Groceries', 'icon' => 'shopping_cart']);
    (new LedgerService)->recordExpense($account, 40, 'SM Supermarket', now(), $envelope->id);

    $entry = $this->actingAs($user)->get('/ledger')->assertOk()
        ->viewData('page')['props']['activity']['entries'][0];

    expect($entry['envelope_id'])->toBe($envelope->id);
    expect($entry['envelope_name'])->toBe('Groceries');
    expect($entry['icon'])->toBe('shopping_cart');
});

test('the ledger page exposes the active envelope list', function () {
    $user = User::factory()->create();
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'active' => true]);
    Envelope::factory()->for($user)->create(['name' => 'Old one', 'active' => false]);

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $envelopes = collect($response->viewData('page')['props']['envelopes'])->pluck('name');

    expect($envelopes->all())->toBe(['Groceries']);
});

// ── 10d: the Debt tab ────────────────────────────────────────────────────

test('the ledger offers the live debts a payment can be logged against', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 3000]);
    Debt::factory()->for($user)->create(['name' => 'BPI Card', 'balance' => 48200, 'monthly_rate' => 3.25, 'minimum_payment' => 2100]);
    Debt::factory()->for($user)->create(['name' => 'SSS Loan', 'balance' => 9750, 'monthly_rate' => 0.8, 'minimum_payment' => 900]);
    Debt::factory()->for($user)->create(['name' => 'Cleared', 'balance' => 0, 'minimum_payment' => 100]);

    $props = $this->actingAs($user)->get('/ledger')->assertOk()->viewData('page')['props'];
    $debts = collect($props['debts']);

    expect($debts->pluck('name')->all())->toBe(['BPI Card', 'SSS Loan']);
    expect($debts->firstWhere('name', 'BPI Card')['is_target'])->toBeTrue();
    expect($debts->firstWhere('name', 'BPI Card')['planned_payment'])->toBe(5100.0);
    expect($debts->firstWhere('name', 'SSS Loan')['planned_payment'])->toBe(900.0);
});

test('logging a debt payment against a linked debt moves both accounts and tags the debt', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 24180]);
    $card = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 48200]);
    $debt = Debt::factory()->for($user)->create(['name' => 'BPI Card', 'balance' => 48200, 'linked_account_id' => $card->id]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'debt',
        'debt_id' => $debt->id,
        'from_account_id' => $checking->id,
        'amount' => 9500,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(14680.0);
    expect((float) $card->fresh()->balance)->toBe(38700.0);
    expect(Transaction::where('debt_id', $debt->id)->count())->toBe(1);
});

test('logging a debt payment against an unlinked debt reduces its balance directly', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 5000, 'linked_account_id' => null]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'debt',
        'debt_id' => $debt->id,
        'from_account_id' => $checking->id,
        'amount' => 1200,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(3800.0);
    expect((float) $checking->fresh()->balance)->toBe(8800.0);
});

test('a debt payment must name a debt', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'debt',
        'from_account_id' => $checking->id,
        'amount' => 500,
        'occurred_at' => now()->toDateString(),
    ])->assertSessionHasErrors('debt_id');
});

test('a debt payment cannot be logged against another user\'s debt', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false]);
    $theirs = Debt::factory()->for(User::factory())->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'debt',
        'debt_id' => $theirs->id,
        'from_account_id' => $checking->id,
        'amount' => 500,
        'occurred_at' => now()->toDateString(),
    ])->assertSessionHasErrors('debt_id');
});

test('a debt cannot be attached to an ordinary expense', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false]);
    $debt = Debt::factory()->for($user)->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'expense',
        'account_id' => $checking->id,
        'debt_id' => $debt->id,
        'amount' => 500,
        'occurred_at' => now()->toDateString(),
    ])->assertSessionHasErrors('debt_id');
});

test('previewing a debt payment splits it and shows the horizon moving', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche', 'extra_monthly' => 0]);
    $debt = Debt::factory()->for($user)->create(['balance' => 48200, 'monthly_rate' => 3.25, 'minimum_payment' => 2100]);

    $preview = $this->actingAs($user)->postJson('/ledger/debt-payment/preview', [
        'debt_id' => $debt->id,
        'amount' => 9500,
    ])->assertOk()->json();

    expect($preview['interest'])->toBe(1566.5);
    expect($preview['principal'])->toBe(7933.5);
    expect((float) $preview['balanceBefore'])->toBe(48200.0);
    expect($preview['balanceAfter'])->toBe(40266.5);
    expect($preview['coversInterest'])->toBeTrue();
    expect($preview['daysEarlier'])->toBeGreaterThan(0);
    expect($preview['interestSaved'])->toBeGreaterThan(0.0);
});

test('previewing a payment that does not cover the interest says so', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 10000, 'monthly_rate' => 2, 'minimum_payment' => 50]);

    $preview = $this->actingAs($user)->postJson('/ledger/debt-payment/preview', [
        'debt_id' => $debt->id,
        'amount' => 100,
    ])->assertOk()->json();

    expect($preview['coversInterest'])->toBeFalse();
    expect((float) $preview['principal'])->toBe(-100.0);
    expect((float) $preview['balanceAfter'])->toBe(10100.0);
});

test('previewing a debt payment saves nothing', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 5000, 'monthly_rate' => 1, 'minimum_payment' => 200]);

    $this->actingAs($user)->postJson('/ledger/debt-payment/preview', [
        'debt_id' => $debt->id, 'amount' => 1000,
    ])->assertOk();

    expect((float) $debt->fresh()->balance)->toBe(5000.0);
    expect((float) $checking->fresh()->balance)->toBe(10000.0);
    expect(Transaction::count())->toBe(0);
});

test('cannot preview a payment against another user\'s debt', function () {
    $user = User::factory()->create();
    $theirs = Debt::factory()->for(User::factory())->create(['balance' => 5000]);

    $this->actingAs($user)->postJson('/ledger/debt-payment/preview', [
        'debt_id' => $theirs->id, 'amount' => 1000,
    ])->assertStatus(422);
});

// ── Editing and removing entries ─────────────────────────────────────────

test('changing an expense amount moves the account by the difference, not the whole amount', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $transaction = (new LedgerService)->recordExpense($checking, 500, 'Lunch');

    expect((float) $checking->fresh()->balance)->toBe(9500.0);

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'account_id' => $checking->id,
        'amount' => 800,
        'description' => 'Lunch',
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(9200.0);
    expect((float) $transaction->fresh()->amount)->toBe(800.0);
});

test('moving an expense to another account puts the money back on the first', function () {
    $user = User::factory()->create();
    $maya = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $gotyme = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $transaction = (new LedgerService)->recordExpense($maya, 500, 'Lunch');

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'account_id' => $gotyme->id,
        'amount' => 500,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $maya->fresh()->balance)->toBe(10000.0);
    expect((float) $gotyme->fresh()->balance)->toBe(9500.0);
});

test('editing an expense on a liability account keeps the sign right', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 1000]);
    $transaction = (new LedgerService)->recordExpense($card, 500, 'Spend on the card');

    expect((float) $card->fresh()->balance)->toBe(1500.0);

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'account_id' => $card->id,
        'amount' => 200,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(1200.0);
});

test('editing an income adjusts the account it landed in', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 1000]);
    $transaction = (new LedgerService)->recordIncome($checking, 5000, 'Paycheck');

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'account_id' => $checking->id,
        'amount' => 4000,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(5000.0);
});

test('editing a transfer re-lands both legs', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $to = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 0]);
    $transaction = (new LedgerService)->recordTransfer($from, $to, 2000);

    expect((float) $from->fresh()->balance)->toBe(8000.0);
    expect((float) $to->fresh()->balance)->toBe(2000.0);

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount' => 3000,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $from->fresh()->balance)->toBe(7000.0);
    expect((float) $to->fresh()->balance)->toBe(3000.0);
});

test('redirecting a transfer to a different destination unwinds the old one', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $wrong = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 0]);
    $right = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 0]);
    $transaction = (new LedgerService)->recordTransfer($from, $wrong, 2000);

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'from_account_id' => $from->id,
        'to_account_id' => $right->id,
        'amount' => 2000,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $wrong->fresh()->balance)->toBe(0.0);
    expect((float) $right->fresh()->balance)->toBe(2000.0);
    expect((float) $from->fresh()->balance)->toBe(8000.0);
});

test('an expense can be moved into a different envelope', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $groceries = Envelope::factory()->for($user)->create();
    $eatingOut = Envelope::factory()->for($user)->create();
    $transaction = (new LedgerService)->recordExpense($checking, 500, 'Dinner', now(), $groceries->id);

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'account_id' => $checking->id,
        'envelope_id' => $eatingOut->id,
        'amount' => 500,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect($transaction->fresh()->envelope_id)->toBe($eatingOut->id);
});

test('deleting an expense puts the money back', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $transaction = (new LedgerService)->recordExpense($checking, 500, 'Lunch');

    $this->actingAs($user)->delete("/ledger/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(10000.0);
    expect(Transaction::find($transaction->id))->toBeNull();
});

test('deleting a transfer puts both accounts back', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $to = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 500]);
    $transaction = (new LedgerService)->recordTransfer($from, $to, 2000);

    $this->actingAs($user)->delete("/ledger/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $from->fresh()->balance)->toBe(10000.0);
    expect((float) $to->fresh()->balance)->toBe(500.0);
});

test('deleting a payment against an unlinked debt restores what it owed', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 5000, 'linked_account_id' => null]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'debt',
        'debt_id' => $debt->id,
        'from_account_id' => $checking->id,
        'amount' => 1200,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(3800.0);

    $transaction = Transaction::where('debt_id', $debt->id)->firstOrFail();
    $this->actingAs($user)->delete("/ledger/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(5000.0);
    expect((float) $checking->fresh()->balance)->toBe(10000.0);
});

test('deleting a payment against a linked debt restores the mirrored account', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $card = Account::factory()->for($user)->create(['is_liability' => true, 'balance' => 5000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 5000, 'linked_account_id' => $card->id]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'debt',
        'debt_id' => $debt->id,
        'from_account_id' => $checking->id,
        'amount' => 1200,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(3800.0);

    $transaction = Transaction::where('debt_id', $debt->id)->firstOrFail();
    $this->actingAs($user)->delete("/ledger/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(5000.0);
    expect((float) $checking->fresh()->balance)->toBe(10000.0);
    // The debt mirrors its account, so it must not be double-restored.
    expect((float) $debt->fresh()->balance)->toBe(5000.0);
});

test('editing a payment against an unlinked debt re-lands it on the debt', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 5000, 'linked_account_id' => null]);

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'debt', 'debt_id' => $debt->id, 'from_account_id' => $checking->id,
        'amount' => 1200, 'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    $transaction = Transaction::where('debt_id', $debt->id)->firstOrFail();

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'account_id' => $checking->id,
        'amount' => 2000,
        'occurred_at' => now()->toDateString(),
    ])->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(3000.0);
    expect((float) $checking->fresh()->balance)->toBe(8000.0);
});

test('the date an entry is filed under can be corrected', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $transaction = (new LedgerService)->recordExpense($checking, 500, 'Lunch', now());

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'account_id' => $checking->id,
        'amount' => 500,
        'occurred_at' => '2026-08-14',
    ])->assertRedirect();

    expect($transaction->fresh()->occurred_at->toDateString())->toBe('2026-08-14');
    expect((float) $checking->fresh()->balance)->toBe(9500.0);
});

test('cannot edit another user\'s entry', function () {
    $user = User::factory()->create();
    $theirAccount = Account::factory()->for(User::factory())->create(['is_liability' => false, 'balance' => 10000]);
    $theirs = (new LedgerService)->recordExpense($theirAccount, 500, 'Lunch');

    $this->actingAs($user)->patch("/ledger/transactions/{$theirs->id}", [
        'account_id' => $theirAccount->id, 'amount' => 999, 'occurred_at' => now()->toDateString(),
    ])->assertForbidden();

    expect((float) $theirs->fresh()->amount)->toBe(500.0);
});

test('cannot delete another user\'s entry', function () {
    $user = User::factory()->create();
    $theirAccount = Account::factory()->for(User::factory())->create(['is_liability' => false, 'balance' => 10000]);
    $theirs = (new LedgerService)->recordExpense($theirAccount, 500, 'Lunch');

    $this->actingAs($user)->delete("/ledger/transactions/{$theirs->id}")->assertForbidden();

    expect(Transaction::find($theirs->id))->not->toBeNull();
});

test('an entry cannot be moved onto another user\'s account', function () {
    $user = User::factory()->create();
    $mine = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $theirs = Account::factory()->for(User::factory())->create(['is_liability' => false, 'balance' => 10000]);
    $transaction = (new LedgerService)->recordExpense($mine, 500, 'Lunch');

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'account_id' => $theirs->id, 'amount' => 500, 'occurred_at' => now()->toDateString(),
    ])->assertSessionHasErrors('account_id');

    expect((float) $theirs->fresh()->balance)->toBe(10000.0);
    expect((float) $mine->fresh()->balance)->toBe(9500.0);
});

test('a transfer cannot be edited to leave and land in the same account', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $to = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 0]);
    $transaction = (new LedgerService)->recordTransfer($from, $to, 2000);

    $this->actingAs($user)->patch("/ledger/transactions/{$transaction->id}", [
        'from_account_id' => $from->id, 'to_account_id' => $from->id,
        'amount' => 2000, 'occurred_at' => now()->toDateString(),
    ])->assertSessionHasErrors('from_account_id');
});

test('activity rows carry the raw fields the edit sheet needs', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['is_liability' => false, 'balance' => 10000]);
    $envelope = Envelope::factory()->for($user)->create();
    (new LedgerService)->recordExpense($checking, 500, 'Lunch', now(), $envelope->id);

    $props = $this->actingAs($user)->get("/ledger?account={$checking->id}")->assertOk()->viewData('page')['props'];
    $entry = $props['activity']['entries'][0];

    expect($entry['type'])->toBe('expense');
    expect($entry['description'])->toBe('Lunch');
    expect($entry['account_id'])->toBe($checking->id);
    expect($entry['envelope_id'])->toBe($envelope->id);
    expect($entry['from_account_id'])->toBeNull();
    expect($entry['occurred_on'])->toBe(now()->toDateString());
});
