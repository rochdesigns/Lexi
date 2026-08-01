# Credit Card Debt–Account Integration Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Link credit card accounts to their matching debt records so that spending on a CC updates the outstanding balance and auto-computes the monthly minimum, and payments on the Debt page reduce both the source account and the CC outstanding balance.

**Architecture:** Add `account_id` and `minimum_payment_rate` columns to `debts`. The Account model grows a `syncLinkedDebt()` helper that is called whenever a credit account's balance changes (via transaction, payment, or direct edit). Credit-type accounts that are linked to a debt are excluded from the asset total on the Accounts page (they are already captured in `debtTotal`).

**Tech Stack:** Laravel 13, Inertia v3, Vue 3, Pest v4, SQLite (local dev)

## Global Constraints

- PHP 8.4; strict types, constructor property promotion, explicit return types
- All controller changes must be tested with Pest feature tests
- Run `vendor/bin/pint --dirty --format agent` after every PHP file change
- Run `php artisan test --compact --filter=<TestFile>` to verify each task
- No new base directories; no new packages
- Vue components must have a single root element
- Wayfinder-generated action files in `resources/js/actions/` are auto-generated — never edit them manually

---

## File Map

| File | Action | Purpose |
|---|---|---|
| `database/migrations/[timestamp]_add_account_id_and_minimum_payment_rate_to_debts_table.php` | CREATE | Schema: add `account_id` FK + `minimum_payment_rate` to `debts` |
| `app/Models/Debt.php` | MODIFY | Add fillable fields, casts, `account()` BelongsTo relationship |
| `app/Models/Account.php` | MODIFY | Add `linkedDebt()` HasOne relationship + `syncLinkedDebt()` helper |
| `database/factories/DebtFactory.php` | MODIFY | Add `account_id: null` and `minimum_payment_rate: null` defaults |
| `app/Http/Controllers/TransactionController.php` | MODIFY | Fix expense/income balance direction for credit accounts; call `syncLinkedDebt()` after each balance change |
| `app/Http/Controllers/DebtController.php` | MODIFY | `makePayment`: decrement CC account + sync; `store`/`update` rules: add `account_id` + `minimum_payment_rate`; `index`: pass credit accounts; compute `per_month` from rate when linked |
| `app/Http/Controllers/AccountController.php` | MODIFY | Exclude linked-credit accounts from `accountTotal`; call `syncLinkedDebt()` after direct balance edit |
| `tests/Feature/DebtTest.php` | MODIFY | Add tests for linking, payment sync, per_month auto-compute |
| `tests/Feature/AccountTest.php` | MODIFY | Add tests for net worth exclusion of linked credit, direct-edit sync |
| `tests/Feature/TransactionCreditTest.php` | CREATE | Tests for credit account balance direction + debt sync |
| `resources/js/pages/Debt.vue` | MODIFY | Add linked account dropdown, minimum_payment_rate field, read-only per_month, CC chip on cards |
| `resources/js/pages/Accounts.vue` | MODIFY | Add "Add CC expense" form in transaction panel; credit group "Total owed" label |

---

## Task 1: Migration + Model Relationships

**Files:**
- Create: `database/migrations/[run php artisan make:migration to get timestamp]_add_account_id_and_minimum_payment_rate_to_debts_table.php`
- Modify: `app/Models/Debt.php`
- Modify: `app/Models/Account.php`
- Modify: `database/factories/DebtFactory.php`
- Test: `tests/Feature/DebtTest.php`

**Interfaces:**
- Produces: `Debt::account()` — `BelongsTo<Account>` relationship
- Produces: `Account::linkedDebt()` — `HasOne<Debt>` relationship (FK: `debts.account_id`)
- Produces: `Account::syncLinkedDebt(): void` — syncs linked debt's balance and per_month from the account's current balance

- [ ] **Step 1: Create the migration**

```bash
php artisan make:migration add_account_id_and_minimum_payment_rate_to_debts_table --no-interaction
```

Edit the generated file:

```php
public function up(): void
{
    Schema::table('debts', function (Blueprint $table): void {
        $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete()->after('budget_category_id');
        $table->decimal('minimum_payment_rate', 5, 2)->nullable()->after('interest_rate_period');
    });
}

public function down(): void
{
    Schema::table('debts', function (Blueprint $table): void {
        $table->dropForeign(['account_id']);
        $table->dropColumn(['account_id', 'minimum_payment_rate']);
    });
}
```

- [ ] **Step 2: Run the migration**

```bash
php artisan migrate --no-interaction
```

Expected: "Migrating: ...add_account_id..." then "Migrated".

- [ ] **Step 3: Update Debt model**

Open `app/Models/Debt.php`. Replace the `$fillable` array and `$casts` array, and add the `account()` relationship:

```php
protected $fillable = [
    'user_id', 'budget_category_id', 'account_id',
    'name', 'icon', 'color', 'bg',
    'balance', 'original', 'per_month',
    'interest_rate', 'interest_rate_period', 'minimum_payment_rate',
    'due_day', 'position',
];

protected $casts = [
    'balance' => 'decimal:2',
    'original' => 'decimal:2',
    'per_month' => 'decimal:2',
    'interest_rate' => 'decimal:2',
    'minimum_payment_rate' => 'decimal:2',
    'due_day' => 'integer',
];

public function account(): BelongsTo
{
    return $this->belongsTo(Account::class);
}
```

Add `use App\Models\Account;` at the top if not already present. Keep the existing `user()` relationship unchanged.

- [ ] **Step 4: Update Account model**

Open `app/Models/Account.php`. Add the `linkedDebt()` relationship and `syncLinkedDebt()` helper:

```php
use App\Models\Debt;
use Illuminate\Database\Eloquent\Relations\HasOne;

public function linkedDebt(): HasOne
{
    return $this->hasOne(Debt::class, 'account_id');
}

public function syncLinkedDebt(): void
{
    $debt = $this->linkedDebt()->first();
    if (! $debt) {
        return;
    }

    $balance = (float) $this->balance;
    $updates = ['balance' => $balance];

    if ($debt->minimum_payment_rate !== null) {
        $updates['per_month'] = round($balance * (float) $debt->minimum_payment_rate / 100, 2);
    }

    $debt->update($updates);
}
```

- [ ] **Step 5: Update DebtFactory**

Open `database/factories/DebtFactory.php`. Add these two nullable fields to the `definition()` return array:

```php
'account_id' => null,
'minimum_payment_rate' => null,
```

- [ ] **Step 6: Write the failing tests**

Add to `tests/Feature/DebtTest.php`:

```php
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
```

- [ ] **Step 7: Run the failing tests**

```bash
php artisan test --compact --filter=DebtTest
```

Expected: the three new tests FAIL (account_id column not on Debt's fillable yet or relationships missing).

- [ ] **Step 8: Run all new tests to verify they pass**

```bash
php artisan test --compact --filter=DebtTest
```

Expected: ALL tests pass including the three new ones.

- [ ] **Step 9: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 10: Commit**

```bash
git add database/migrations/ app/Models/Debt.php app/Models/Account.php database/factories/DebtFactory.php tests/Feature/DebtTest.php
git commit -m "feat: add account_id + minimum_payment_rate to debts; add Account::syncLinkedDebt"
```

---

## Task 2: Fix Credit Account Balance Direction in TransactionController

**Context:** Currently, all account types use the same balance logic: expense decrements, income increments. For credit accounts, the direction must be **inverted** — an expense (CC charge) means you owe *more* (balance goes up), and income (refund/cashback) means you owe *less* (balance goes down). After each balance change, if the account has a linked debt, sync it.

**Files:**
- Modify: `app/Http/Controllers/TransactionController.php`
- Create: `tests/Feature/TransactionCreditTest.php`

**Interfaces:**
- Consumes: `Account::syncLinkedDebt(): void` (from Task 1)
- Consumes: `Account::$type` — `'cash' | 'credit' | 'investment'`

- [ ] **Step 1: Write failing tests**

```bash
php artisan make:test --pest TransactionCreditTest --no-interaction
```

```php
<?php

use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\User;

beforeEach(function (): void {
    $this->user = User::factory()->create();
    $this->actingAs($this->user);
    $this->category = BudgetCategory::factory()->for($this->user)->create();
});

test('expense on credit account increases balance (owe more)', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 5000]);

    $this->post('/transactions', [
        'type' => 'expense',
        'amount' => 1500,
        'merchant' => 'Jollibee',
        'account_id' => $cc->id,
        'budget_category_id' => $this->category->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $cc->fresh()->balance)->toBe(6500.0);
});

test('income on credit account decreases balance (owe less)', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 5000]);

    $this->post('/transactions', [
        'type' => 'income',
        'amount' => 500,
        'merchant' => 'Cashback',
        'account_id' => $cc->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $cc->fresh()->balance)->toBe(4500.0);
});

test('expense on credit account syncs linked debt', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 5000]);
    $debt = Debt::factory()->for($this->user)->create([
        'account_id' => $cc->id,
        'balance' => 5000,
        'minimum_payment_rate' => 3.00,
    ]);

    $this->post('/transactions', [
        'type' => 'expense',
        'amount' => 2000,
        'merchant' => 'SM',
        'account_id' => $cc->id,
        'budget_category_id' => $this->category->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    $debt->refresh();
    expect((float) $debt->balance)->toBe(7000.0);
    expect((float) $debt->per_month)->toBe(210.0); // 7000 * 3% = 210
});

test('deleting credit account expense reverses balance correctly', function (): void {
    $cc = Account::factory()->for($this->user)->create(['type' => 'credit', 'balance' => 6500]);
    $tx = $this->user->transactions()->create([
        'type' => 'expense',
        'amount' => 1500,
        'merchant' => 'Jollibee',
        'account_id' => $cc->id,
        'budget_category_id' => $this->category->id,
        'transacted_at' => '2026-08-01',
    ]);

    $this->delete("/transactions/{$tx->id}")->assertRedirect();

    expect((float) $cc->fresh()->balance)->toBe(5000.0); // 6500 - 1500
});
```

- [ ] **Step 2: Run failing tests**

```bash
php artisan test --compact --filter=TransactionCreditTest
```

Expected: all four FAIL (direction logic not changed yet).

- [ ] **Step 3: Add private helper to TransactionController**

Add this private method to `TransactionController`:

```php
private function applyEffect(Account $account, string $type, float $amount): void
{
    if ($type === 'income') {
        $account->type === 'credit'
            ? $account->decrement('balance', $amount)
            : $account->increment('balance', $amount);
    } elseif ($type === 'transfer') {
        $account->decrement('balance', $amount);
    } else { // expense
        $account->type === 'credit'
            ? $account->increment('balance', $amount)
            : $account->decrement('balance', $amount);
    }
}

private function reverseEffect(Account $account, string $type, float $amount): void
{
    if ($type === 'income') {
        $account->type === 'credit'
            ? $account->increment('balance', $amount)
            : $account->decrement('balance', $amount);
    } elseif ($type === 'transfer') {
        $account->increment('balance', $amount);
    } else { // expense
        $account->type === 'credit'
            ? $account->decrement('balance', $amount)
            : $account->increment('balance', $amount);
    }
}
```

- [ ] **Step 4: Update `store()` in TransactionController**

Replace the `match ($type)` balance block (after `$user->transactions()->create($validated)`) with:

```php
$this->applyEffect($account, $type, (float) $validated['amount']);

if ($type === 'transfer') {
    $toAccount->increment('balance', $validated['amount']);
}

$account->refresh()->syncLinkedDebt();
```

Remove the old `match ($type)` block and the `$toAccount->increment` inside it. Keep the sinking fund and goal blocks unchanged below.

- [ ] **Step 5: Update `destroy()` in TransactionController**

Replace the `match ($transaction->type)` balance block with:

```php
$this->reverseEffect($transaction->account, $transaction->type, (float) $transaction->amount);

if ($transaction->type === 'transfer') {
    $transaction->toAccount?->decrement('balance', $transaction->amount);
}

$transaction->account->refresh()->syncLinkedDebt();
```

Remove the old `match` block. Keep sinking fund and goal reversal blocks below.

- [ ] **Step 6: Update `update()` in TransactionController**

The `update()` method has two balance blocks: reversal of old and application of new. Replace **both**:

Reversal block (after validating, before `$transaction->update`):
```php
$this->reverseEffect($transaction->account, $transaction->type, (float) $transaction->amount);
if ($transaction->type === 'transfer') {
    $transaction->toAccount?->decrement('balance', $transaction->amount);
}
```

Application block (after `$transaction->update($validated)`, replace the `match ($validated['type'])` block):
```php
$newAccount = $user->accounts()->findOrFail($validated['account_id']);
$this->applyEffect($newAccount, $validated['type'], (float) $validated['amount']);

if ($validated['type'] === 'transfer') {
    $toAccount = $user->accounts()->findOrFail($validated['to_account_id']);
    $toAccount->increment('balance', $validated['amount']);
}

$newAccount->refresh()->syncLinkedDebt();
```

Keep sinking fund and goal reversal/application blocks unchanged.

- [ ] **Step 7: Run tests**

```bash
php artisan test --compact --filter=TransactionCreditTest
```

Expected: all four pass.

- [ ] **Step 8: Run full test suite to check for regressions**

```bash
php artisan test --compact
```

Expected: all existing tests still pass.

- [ ] **Step 9: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/TransactionController.php tests/Feature/TransactionCreditTest.php
git commit -m "feat: invert balance direction for credit accounts in TransactionController; sync linked debt"
```

---

## Task 3: DebtController::makePayment — Sync CC Account on Payment

**Context:** When a user records a payment on the Debt page, the current flow decrements only the source account and manually reduces the debt balance. For CC-linked debts, we must also decrement the credit card account and then use `syncLinkedDebt()` to recompute debt balance and per_month from the account.

**Files:**
- Modify: `app/Http/Controllers/DebtController.php`
- Test: `tests/Feature/DebtTest.php`

**Interfaces:**
- Consumes: `Account::syncLinkedDebt(): void` (Task 1)
- Consumes: `Debt::$account_id` (Task 1)

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/DebtTest.php`:

```php
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
```

- [ ] **Step 2: Run failing tests**

```bash
php artisan test --compact --filter="makePayment on linked"
```

Expected: FAIL (CC account not decremented yet).

- [ ] **Step 3: Update `makePayment()` in DebtController**

Replace the current payment logic (after `$account->decrement`) with:

```php
$account->decrement('balance', $validated['amount']); // source account (e.g., BPI Savings)

if ($debt->account_id) {
    // CC-linked debt: decrement the CC outstanding balance, then sync
    $ccAccount = $user->accounts()->findOrFail($debt->account_id);
    $ccAccount->decrement('balance', $validated['amount']);
    $ccAccount->refresh()->syncLinkedDebt();
} else {
    // Unlinked debt: manually reduce balance
    $newBalance = max(0, (float) $debt->balance - (float) $validated['amount']);
    $debt->update(['balance' => $newBalance]);
}
```

Remove the old `$newBalance` and `$debt->update(['balance' => $newBalance])` lines (they're now inside the else branch above).

- [ ] **Step 4: Run tests**

```bash
php artisan test --compact --filter=DebtTest
```

Expected: all pass.

- [ ] **Step 5: Run Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/DebtController.php tests/Feature/DebtTest.php
git commit -m "feat: makePayment syncs CC account balance and linked debt on payment"
```

---

## Task 4: AccountController — Net Worth Exclusion + Edit Sync

**Context:** Credit accounts with a linked debt are liabilities already captured in `debtTotal`. If they're also included in `accountTotal`, they'd be counted twice. Exclude them. Also, when a user directly edits a credit account's balance (e.g., after a statement reconciliation), sync the linked debt.

**Files:**
- Modify: `app/Http/Controllers/AccountController.php`
- Test: `tests/Feature/AccountTest.php`

**Interfaces:**
- Consumes: `Account::linkedDebt()` HasOne (Task 1)
- Consumes: `Account::syncLinkedDebt(): void` (Task 1)

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/AccountTest.php`:

```php
test('accountTotal excludes credit accounts that have a linked debt', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);

    $cash = Account::factory()->create(['user_id' => $user->id, 'type' => 'cash', 'balance' => 10000]);
    $cc = Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => 3000]);
    Debt::factory()->create(['user_id' => $user->id, 'account_id' => $cc->id, 'balance' => 3000]);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('accountTotal', 10000.0) // CC excluded because it has a linked debt
            ->where('debtTotal', 3000.0)
            ->where('netWorth', 7000.0)
        );
});

test('accountTotal includes unlinked credit accounts (backward compat)', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);

    Account::factory()->create(['user_id' => $user->id, 'type' => 'cash', 'balance' => 10000]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => 500]);
    // No debt linked to the credit account

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->where('accountTotal', 10500.0) // unlinked credit still included
        );
});

test('updating credit account balance syncs linked debt', function () {
    $user = User::factory()->create();
    $cc = Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => 5000]);
    $debt = Debt::factory()->create([
        'user_id' => $user->id,
        'account_id' => $cc->id,
        'balance' => 5000,
        'minimum_payment_rate' => 2.00,
        'per_month' => 100,
    ]);

    $this->actingAs($user)
        ->patch(route('accounts.update', $cc), [
            'name' => $cc->name,
            'balance' => 8000,
            'type' => 'credit',
        ])->assertRedirect();

    $debt->refresh();
    expect((float) $debt->balance)->toBe(8000.0);
    expect((float) $debt->per_month)->toBe(160.0); // 8000 * 2% = 160
});
```

- [ ] **Step 2: Run failing tests**

```bash
php artisan test --compact --filter=AccountTest
```

Expected: the three new tests FAIL.

- [ ] **Step 3: Update `index()` in AccountController**

Replace the `$accountTotal` line:

```php
// Before:
$accountTotal = (float) $accounts->sum('balance');

// After:
$linkedCreditIds = $user->accounts()
    ->where('type', 'credit')
    ->whereHas('linkedDebt')
    ->pluck('id');

$accountTotal = (float) $accounts->reject(fn ($a) => $linkedCreditIds->contains($a->id))->sum('balance');
```

`$debtTotal` and `$netWorth` calculations remain unchanged.

- [ ] **Step 4: Update `update()` in AccountController**

After `$account->update($validated)`, add:

```php
if ($account->type === 'credit') {
    $account->refresh()->syncLinkedDebt();
}
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact --filter=AccountTest
```

Expected: all pass (including the existing tests that use negative credit balances for unlinked accounts).

- [ ] **Step 6: Run Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/AccountController.php tests/Feature/AccountTest.php
git commit -m "feat: exclude linked credit accounts from accountTotal; sync debt on direct balance edit"
```

---

## Task 5: DebtController — New Fields and Credit Accounts Prop

**Context:** The Debt form needs two new fields: `account_id` (links to a credit account) and `minimum_payment_rate` (percentage). The Debt page also needs a `creditAccounts` prop so the frontend dropdown can list only credit-type accounts. When a linked debt is returned from `index()`, its `per_month` is computed from the account balance × rate.

**Files:**
- Modify: `app/Http/Controllers/DebtController.php`
- Test: `tests/Feature/DebtTest.php`

**Interfaces:**
- Produces: `creditAccounts` prop — array of `{id, name}` for credit-type accounts
- Produces: `debts[].account_id` — nullable int
- Produces: `debts[].minimum_payment_rate` — nullable float

- [ ] **Step 1: Write failing tests**

Add to `tests/Feature/DebtTest.php`:

```php
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
            ->where('debts.0.minimum_payment_rate', 3.0)
        );
});
```

- [ ] **Step 2: Run failing tests**

```bash
php artisan test --compact --filter="creditAccounts|minimum_payment_rate|account_id"
```

Expected: all three FAIL.

- [ ] **Step 3: Update `rules()` in DebtController**

Add to the `rules()` return array:

```php
'account_id' => ['nullable', 'integer', 'exists:accounts,id'],
'minimum_payment_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
```

- [ ] **Step 4: Update `index()` in DebtController**

Add `creditAccounts` to the `Inertia::render` call:

```php
$creditAccounts = $user->accounts()
    ->where('type', 'credit')
    ->orderBy('name')
    ->get()
    ->map(fn ($a) => ['id' => $a->id, 'name' => $a->name]);
```

Add to the `debts` map (inside the `map(function (Debt $d)` closure):
```php
'account_id' => $d->account_id,
'minimum_payment_rate' => $d->minimum_payment_rate !== null ? (float) $d->minimum_payment_rate : null,
```

Add to the `Inertia::render('Debt', [...])` array:
```php
'creditAccounts' => $creditAccounts,
```

- [ ] **Step 5: Run tests**

```bash
php artisan test --compact --filter=DebtTest
```

Expected: all pass.

- [ ] **Step 6: Run Pint + commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/DebtController.php tests/Feature/DebtTest.php
git commit -m "feat: add account_id and minimum_payment_rate to DebtController; pass creditAccounts prop"
```

---

## Task 6: Frontend — Debt.vue: Linked CC Form Fields + Card Display

**Context:** The debt form needs a "Linked credit account" dropdown and a "Minimum payment rate (%)" field. When a CC account is linked, the `original` field label changes to "Credit limit" and `per_month` becomes read-only (showing the auto-computed value). Debt cards for linked CC debts show a "Credit card" chip and display per_month as "~₱X/mo min".

**Files:**
- Modify: `resources/js/pages/Debt.vue`

**Interfaces:**
- Consumes: `creditAccounts` prop — `{ id: number; name: string }[]`
- Consumes: `debts[].account_id` — `number | null`
- Consumes: `debts[].minimum_payment_rate` — `number | null`

- [ ] **Step 1: Add new prop types and form fields in `<script setup>`**

In the `Debt` type definition, add:
```ts
account_id: number | null;
minimum_payment_rate: number | null;
```

Add `creditAccounts` to `defineProps`:
```ts
creditAccounts: { id: number; name: string }[];
```

In `useForm(...)`, add:
```ts
account_id: null as number | null,
minimum_payment_rate: '' as number | string,
```

Update `openEdit(debt: Debt)` to populate new fields:
```ts
form.account_id = debt.account_id;
form.minimum_payment_rate = debt.minimum_payment_rate ? String(debt.minimum_payment_rate) : '';
```

Update `form.reset()` calls in `openCreate()` to also clear the new fields:
```ts
form.account_id = null;
form.minimum_payment_rate = '';
```

Update `submitForm()` transform to include the new fields:
```ts
account_id: data.account_id ?? null,
minimum_payment_rate: data.minimum_payment_rate ? parseFloat(String(data.minimum_payment_rate)) : null,
```

Add a computed to check if the form is a linked CC debt:
```ts
const isLinkedCC = computed(() => !!form.account_id);
```

- [ ] **Step 2: Add form fields to the debt CRUD modal**

In the CRUD modal `<form>`, after the Budget category field, add:

```html
<!-- Linked credit account -->
<div style="display: flex; flex-direction: column; gap: 6px">
    <label style="font-size: 13px; font-weight: 600; color: #4a4436">
        Linked credit account
        <span style="font-weight: 400; color: #8a8172">(optional — links this debt to a credit card account)</span>
    </label>
    <select v-model.number="form.account_id"
        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17; appearance: none; box-sizing: border-box">
        <option :value="null">— None —</option>
        <option v-for="a in creditAccounts" :key="a.id" :value="a.id">{{ a.name }}</option>
    </select>
</div>

<!-- Minimum payment rate (only when CC linked) -->
<div v-if="isLinkedCC" style="display: flex; flex-direction: column; gap: 6px">
    <label style="font-size: 13px; font-weight: 600; color: #4a4436">Minimum payment rate (%)</label>
    <input v-model="form.minimum_payment_rate" type="number" inputmode="decimal" min="0" max="100" step="0.01"
        placeholder="e.g. 3"
        style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
    <div v-if="form.errors.minimum_payment_rate" style="font-size: 12px; color: #c0512c">{{ form.errors.minimum_payment_rate }}</div>
</div>
```

- [ ] **Step 3: Update `original` label and `per_month` field in the form**

Find the "Original amount (₱)" label and change it to:
```html
<label style="font-size: 13px; font-weight: 600; color: #4a4436">
    {{ isLinkedCC ? 'Credit limit (₱)' : 'Original amount (₱)' }}
</label>
```

Find the `per_month` input field and wrap it in a conditional:

```html
<!-- When CC linked: read-only computed display -->
<template v-if="isLinkedCC">
    <label style="font-size: 13px; font-weight: 600; color: #4a4436">Est. min. payment (₱)</label>
    <div style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #f7f3ec; color: #8a8172; display: flex; align-items: center">
        Auto-computed from balance × rate
    </div>
</template>
<template v-else>
    <label style="font-size: 13px; font-weight: 600; color: #4a4436">Monthly payment (₱)</label>
    <!-- existing per_month input unchanged -->
    <input v-model="form.per_month" type="text" inputmode="decimal" required ...existing styles... />
    <div v-if="perMonthHasExpr" ...>...</div>
    <div v-if="form.errors.per_month" ...>...</div>
</template>
```

- [ ] **Step 4: Update debt card display**

In the debt card template, find the footer div (with `/mo` and payments left), and update the `per_month` display:

```html
<span style="color: #4a4436; font-weight: 600">
    <template v-if="debt.account_id && debt.minimum_payment_rate">~</template>{{ formatMoney(debt.per_month) }}/mo
    <template v-if="debt.account_id && debt.minimum_payment_rate">
        <span style="font-weight: 400; font-size: 11px; color: #8a8172"> min</span>
    </template>
</span>
```

In the card header (next to the status badge), add a CC chip before the status badge:

```html
<span v-if="debt.account_id"
    style="font-size: 10.5px; font-weight: 600; padding: 2px 8px; border-radius: 20px; background: #fbe2d6; color: #c0512c; white-space: nowrap">
    Credit card
</span>
```

- [ ] **Step 5: Verify in browser**

Start the dev server and check:
- The debt form shows "Linked credit account" dropdown with credit accounts listed
- Selecting a CC account shows the "Minimum payment rate (%)" field
- The `original` label changes to "Credit limit" when a CC is linked
- The `per_month` field becomes the read-only display when CC linked
- Debt cards for linked CC debts show the "Credit card" chip and "~₱X/mo min"

```bash
# Ask the user to run: npm run dev
```

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/Debt.vue
git commit -m "feat: add linked CC account + minimum payment rate fields to Debt form; CC chip on cards"
```

---

## Task 7: Frontend — Accounts.vue: Add CC Expense + Credit Group Labels

**Context:** The transaction panel for credit accounts needs an "Add expense" button. When clicked, a small inline form appears (amount, merchant, category, date). Submitting POSTs to `/transactions` and reloads the panel. The Credit group subtotal label also changes to "Total owed" to reflect that these are liabilities.

**Files:**
- Modify: `resources/js/pages/Accounts.vue`

**Interfaces:**
- Consumes: `POST /transactions` — `TransactionController::store` (existing route)
- Consumes: `txPanelCategories` — already loaded when panel opens
- Produces: UI for adding CC expenses from the accounts panel

- [ ] **Step 1: Import TransactionController store action**

In the `<script setup>` imports section, update the TransactionController import to include `store`:

```ts
import { destroy as destroyTransaction, update as updateTransaction, store as storeTransaction } from '@/actions/App/Http/Controllers/TransactionController';
```

- [ ] **Step 2: Add "add expense" state to `<script setup>`**

Add these reactive refs after the existing tx panel state:

```ts
const addExpenseOpen = ref(false);
const addExpenseForm = reactive({
    amount: '',
    merchant: '',
    budget_category_id: null as number | null,
    transacted_at: new Date().toISOString().split('T')[0],
});
const addExpenseAmountEvaluated = computed(() => evaluateArithmetic(addExpenseForm.amount));
const addExpenseAmountHasExpr = computed(() => hasArithmetic(addExpenseForm.amount) && addExpenseAmountEvaluated.value !== null);

function openAddExpense() {
    Object.assign(addExpenseForm, {
        amount: '',
        merchant: '',
        budget_category_id: null,
        transacted_at: new Date().toISOString().split('T')[0],
    });
    addExpenseOpen.value = true;
}

function submitAddExpense() {
    if (!txPanelAccount.value) { return; }
    router.post(storeTransaction().url, {
        type: 'expense',
        amount: addExpenseAmountEvaluated.value ?? parseFloat(addExpenseForm.amount),
        merchant: addExpenseForm.merchant || null,
        account_id: txPanelAccount.value.id,
        budget_category_id: addExpenseForm.budget_category_id,
        transacted_at: addExpenseForm.transacted_at,
    }, {
        preserveScroll: true,
        onSuccess: () => {
            addExpenseOpen.value = false;
            openAccountTransactions(txPanelAccount.value!);
        },
    });
}
```

- [ ] **Step 3: Add "Add expense" button and form in the transaction panel template**

Find the transaction panel in the template (it opens when `txPanelOpen` is true, shows `txPanelAccount` name and transaction list). Just below the panel header and above the transaction list, add:

```html
<!-- Add expense button — only for credit accounts -->
<template v-if="txPanelAccount?.type === 'credit'">
    <div v-if="!addExpenseOpen" style="padding: 0 20px 12px">
        <button @click="openAddExpense"
            style="width: 100%; display: flex; align-items: center; justify-content: center; gap: 6px; height: 36px; border: 1.5px dashed #c8c0b0; border-radius: 10px; background: none; font-family: 'Instrument Sans'; font-size: 12.5px; font-weight: 600; color: #6b6355; cursor: pointer">
            <span class="material-symbols-rounded" style="font-size: 15px">add</span> Add CC expense
        </button>
    </div>

    <div v-else style="padding: 0 20px 16px; display: flex; flex-direction: column; gap: 10px">
        <!-- Amount -->
        <div style="display: flex; flex-direction: column; gap: 4px">
            <label style="font-size: 12px; font-weight: 600; color: #4a4436">Amount (₱)</label>
            <input v-model="addExpenseForm.amount" type="text" inputmode="decimal" autofocus
                style="height: 38px; border: 1px solid #e0dacd; border-radius: 8px; padding: 0 10px; font-family: 'Instrument Sans'; font-size: 13px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
            <div v-if="addExpenseAmountHasExpr" style="font-size: 11px; color: #8a8172">= ₱{{ addExpenseAmountEvaluated?.toLocaleString('en-PH', { minimumFractionDigits: 2 }) }}</div>
        </div>
        <!-- Merchant -->
        <div style="display: flex; flex-direction: column; gap: 4px">
            <label style="font-size: 12px; font-weight: 600; color: #4a4436">Merchant</label>
            <input v-model="addExpenseForm.merchant" type="text" placeholder="e.g. SM Supermarket"
                style="height: 38px; border: 1px solid #e0dacd; border-radius: 8px; padding: 0 10px; font-family: 'Instrument Sans'; font-size: 13px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
        </div>
        <!-- Category -->
        <div style="display: flex; flex-direction: column; gap: 4px">
            <label style="font-size: 12px; font-weight: 600; color: #4a4436">Category</label>
            <select v-model.number="addExpenseForm.budget_category_id"
                style="width: 100%; height: 38px; border: 1px solid #e0dacd; border-radius: 8px; padding: 0 10px; font-family: 'Instrument Sans'; font-size: 13px; background: #fffdf8; outline: none; color: #211d17; appearance: none; box-sizing: border-box">
                <option :value="null">— None —</option>
                <option v-for="c in txPanelCategories" :key="c.id" :value="c.id">{{ c.icon }} {{ c.name }}</option>
            </select>
        </div>
        <!-- Date -->
        <div style="display: flex; flex-direction: column; gap: 4px">
            <label style="font-size: 12px; font-weight: 600; color: #4a4436">Date</label>
            <input v-model="addExpenseForm.transacted_at" type="date"
                style="height: 38px; border: 1px solid #e0dacd; border-radius: 8px; padding: 0 10px; font-family: 'Instrument Sans'; font-size: 13px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
        </div>
        <!-- Buttons -->
        <div style="display: flex; gap: 8px">
            <button type="button" @click="addExpenseOpen = false"
                style="flex: 1; height: 36px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 8px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 12.5px; cursor: pointer; color: #4a4436">
                Cancel
            </button>
            <button type="button" @click="submitAddExpense"
                style="flex: 1; height: 36px; background: #211d17; color: #fdfbf5; border: none; border-radius: 8px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 12.5px; cursor: pointer">
                Add expense
            </button>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Update Credit group subtotal label**

Find the section in the template where account group subtotals are rendered. Locate the credit group's subtotal display and change its label from "Balance" (or whatever it currently says) to "Total owed":

Find the group label/subtotal section (it loops over `groups`) and add a conditional for the credit type:

```html
<!-- In the group header/subtotal area: -->
<span style="font-size: 12.5px; color: #8a8172">
    {{ group.type === 'credit' ? 'Total owed' : 'Balance' }}
</span>
```

(Locate the exact spot by finding the `group.subtotal` display in the template — the label adjacent to it gets this change.)

- [ ] **Step 5: Verify in browser**

Open the Accounts page. Click a credit card account. Verify:
- The "Add CC expense" button appears in the transaction panel
- Clicking it shows the inline form
- Submitting a valid expense closes the form, reloads the panel with the new transaction, and the CC account balance has increased
- If a debt is linked, navigate to the Debt page and verify the balance synced

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/Accounts.vue
git commit -m "feat: add CC expense form in account transaction panel; update credit group label to Total owed"
```

---

## Self-Review Checklist

- [x] Migration: `account_id` nullable FK + `minimum_payment_rate` nullable decimal — **Task 1**
- [x] `Account::syncLinkedDebt()` syncs balance + per_month — **Task 1**
- [x] `Account::linkedDebt()` HasOne relationship — **Task 1**
- [x] CC expense increments balance (not decrements) — **Task 2**
- [x] CC income decrements balance — **Task 2**
- [x] Transaction delete/update reverses correctly for CC — **Task 2**
- [x] Linked debt synced after any CC transaction — **Task 2**
- [x] `makePayment` decrements CC account AND source account — **Task 3**
- [x] `makePayment` unlinked path unchanged — **Task 3**
- [x] Linked CC accounts excluded from `accountTotal` — **Task 4**
- [x] Unlinked CC accounts remain in `accountTotal` (backward compat) — **Task 4**
- [x] Direct account balance edit syncs linked debt — **Task 4**
- [x] `creditAccounts` prop passed to Debt page — **Task 5**
- [x] `account_id` + `minimum_payment_rate` in store/update rules — **Task 5**
- [x] Debt form: linked account dropdown + rate field — **Task 6**
- [x] `per_month` read-only when CC linked — **Task 6**
- [x] `original` label → "Credit limit" when CC linked — **Task 6**
- [x] CC chip on debt cards — **Task 6**
- [x] Add CC expense form in Accounts panel — **Task 7**
- [x] Credit group subtotal labelled "Total owed" — **Task 7**
- [x] Migration of existing data: user manually links via debt form (documented in spec) — no auto-match

**Note on existing credit account transactions:** If users have previously logged expense transactions against credit-type accounts, those transactions used the OLD direction (decrement). Deleting those old transactions will now apply the reversed NEW direction (decrement, which was the old increment). This means account balances may need manual correction after the old transactions are deleted. Communicate this to the user as part of the rollout: after linking, verify the CC account balance matches the actual statement.
