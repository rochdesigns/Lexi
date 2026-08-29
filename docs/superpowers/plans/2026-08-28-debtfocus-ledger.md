# DebtFocus Ledger (Account + Transaction) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the foundational ledger — `Account` and `Transaction` models plus a `LedgerService` that applies a transaction's balance effect correctly for both asset and liability accounts — as a standalone, backend-only subsystem.

**Architecture:** A new, isolated `DebtFocus` ledger: `debt_focus_accounts` and `debt_focus_transactions` tables, `App\Models\DebtFocus\Account`/`Transaction` models, and `App\Services\DebtFocus\LedgerService` — the single place that mutates account balances, so no controller or page ever hand-rolls the sign logic. Continues the existing `DebtFocus` namespace/table-prefix convention already used by `Debt`/`Settings`/`PayoffEngine`, kept entirely separate from the app's existing `App\Models\Account`/`Transaction`. The core rule this plan implements: every ledger event is either a "positive event" (income, or being the destination of a transfer) or a "negative event" (expense, or being the source of a transfer); a positive event increases an asset account's balance and decreases a liability account's balance, and a negative event does the opposite. A credit-card payment is a transfer where the card is the destination — that single rule makes "decreasing what's owed" fall out automatically, per the spec.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` — this plan implements the `Account` and `Transaction` entities from the "Core entities" section, and the balance-effect behavior their descriptions imply. `Debt`/`DebtSettings` already exist (`docs/superpowers/plans/2026-08-27-debtfocus-payoff-engine.md`) but are not linked to `Account` yet — wiring `Debt.linked_account_id` up to this ledger is a separate future plan, as is all of `Bill`/`Envelope`/budgeting. No controllers, routes, or frontend are built here — this is backend schema and business logic only, matching the scoping discipline the payoff-engine plan already established.

## Global Constraints

- This is a **new, standalone subsystem** — do not read, modify, or reuse the existing `App\Models\Account`, `App\Models\Transaction`, or any of their controllers/services anywhere in this plan.
- Namespace everything under `DebtFocus`: models in `App\Models\DebtFocus`, services in `App\Services\DebtFocus`, factories in `Database\Factories\DebtFocus`, tables prefixed `debt_focus_`.
- No frontend/UI, controllers, or routes in this plan — models, migrations, and the `LedgerService` only.
- After every PHP change: `vendor/bin/pint --dirty --format agent`.
- After every task: run the targeted Pest filter. Run the full `DebtFocus` suite (`php artisan test --compact --filter=DebtFocus`) before the final commit of the plan.
- Money fields are `decimal(10,2)` at the database level and cast `decimal:2` on the model, matching every existing `DebtFocus` model.

---

## File Structure

**Create:**
- `database/migrations/2026_08_28_090000_create_debt_focus_accounts_table.php`
- `database/migrations/2026_08_28_090100_create_debt_focus_transactions_table.php`
- `app/Models/DebtFocus/Account.php`
- `app/Models/DebtFocus/Transaction.php`
- `database/factories/DebtFocus/AccountFactory.php`
- `database/factories/DebtFocus/TransactionFactory.php`
- `app/Services/DebtFocus/LedgerService.php`
- `tests/Feature/DebtFocus/AccountModelTest.php`
- `tests/Feature/DebtFocus/TransactionModelTest.php`
- `tests/Feature/DebtFocus/LedgerServiceTest.php`

**Modify:**
- `app/Models/User.php` — add a `debtFocusAccounts(): HasMany` relation, mirroring the existing `debtFocusDebts()`/`debtFocusSettings()` relations.

---

### Task 1: Account model, migration, and factory

**Files:**
- Create: `database/migrations/2026_08_28_090000_create_debt_focus_accounts_table.php`
- Create: `app/Models/DebtFocus/Account.php`
- Create: `database/factories/DebtFocus/AccountFactory.php`
- Modify: `app/Models/User.php`
- Test: `tests/Feature/DebtFocus/AccountModelTest.php`

**Interfaces:**
- Produces: `App\Models\DebtFocus\Account` with fillable `user_id, name, institution, type, is_liability, balance`; `is_liability` casts to `bool`, `balance` casts to `decimal:2`. `Database\Factories\DebtFocus\AccountFactory` with a `liability()` state. `User::debtFocusAccounts(): HasMany<Account, $this>`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DebtFocus/AccountModelTest.php`:

```php
<?php

use App\Models\DebtFocus\Account;
use App\Models\User;

test('an asset account persists with its fields', function () {
    $user = User::factory()->create();

    $account = Account::factory()->for($user)->create([
        'name' => 'Main Checking',
        'institution' => 'BPI',
        'type' => 'checking',
        'is_liability' => false,
        'balance' => 1500.50,
    ]);

    $fresh = $account->fresh();

    expect($fresh->name)->toBe('Main Checking');
    expect($fresh->institution)->toBe('BPI');
    expect($fresh->type)->toBe('checking');
    expect($fresh->is_liability)->toBeFalse();
    expect((float) $fresh->balance)->toBe(1500.50);
    expect($fresh->user_id)->toBe($user->id);
});

test('the liability factory state produces a credit account with is_liability true', function () {
    $account = Account::factory()->liability()->create();

    expect($account->type)->toBe('credit');
    expect($account->is_liability)->toBeTrue();
});

test('institution is nullable', function () {
    $account = Account::factory()->create(['institution' => null]);

    expect($account->fresh()->institution)->toBeNull();
});

test('a user has many debt focus accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->count(2)->create();
    Account::factory()->create();

    expect($user->debtFocusAccounts)->toHaveCount(2);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/DebtFocus/AccountModelTest.php`
Expected: FAIL — class `App\Models\DebtFocus\Account` not found.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_28_090000_create_debt_focus_accounts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('debt_focus_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('institution')->nullable();
            $table->string('type');
            $table->boolean('is_liability')->default(false);
            $table->decimal('balance', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debt_focus_accounts');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/DebtFocus/Account.php`:

```php
<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\AccountFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    protected $table = 'debt_focus_accounts';

    protected $fillable = [
        'user_id', 'name', 'institution', 'type', 'is_liability', 'balance',
    ];

    protected $casts = [
        'is_liability' => 'boolean',
        'balance' => 'decimal:2',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/DebtFocus/AccountFactory.php`:

```php
<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Account> */
class AccountFactory extends Factory
{
    protected $model = Account::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'institution' => fake()->company(),
            'type' => fake()->randomElement(['checking', 'savings', 'cash']),
            'is_liability' => false,
            'balance' => fake()->randomFloat(2, 0, 5000),
        ];
    }

    public function liability(): static
    {
        return $this->state(fn () => [
            'type' => 'credit',
            'is_liability' => true,
        ]);
    }
}
```

- [ ] **Step 6: Add the `User::debtFocusAccounts()` relation**

In `app/Models/User.php`, add the import next to the existing `DebtFocus` imports:

```php
use App\Models\DebtFocus\Account as DebtFocusAccount;
```

Then add the relation next to `debtFocusDebts()`:

```php
/**
 * @return HasMany<DebtFocusAccount, $this>
 */
public function debtFocusAccounts(): HasMany
{
    return $this->hasMany(DebtFocusAccount::class);
}
```

- [ ] **Step 7: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/DebtFocus/AccountModelTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_28_090000_create_debt_focus_accounts_table.php \
  app/Models/DebtFocus/Account.php \
  database/factories/DebtFocus/AccountFactory.php \
  app/Models/User.php \
  tests/Feature/DebtFocus/AccountModelTest.php
git commit -m "feat: add DebtFocus Account model"
```

---

### Task 2: Transaction model, migration, and factory

**Files:**
- Create: `database/migrations/2026_08_28_090100_create_debt_focus_transactions_table.php`
- Create: `app/Models/DebtFocus/Transaction.php`
- Create: `database/factories/DebtFocus/TransactionFactory.php`
- Test: `tests/Feature/DebtFocus/TransactionModelTest.php`

**Interfaces:**
- Consumes: `App\Models\DebtFocus\Account` (Task 1).
- Produces: `App\Models\DebtFocus\Transaction` with fillable `user_id, type, account_id, from_account_id, to_account_id, amount, description, occurred_at`; relations `account()`, `fromAccount()`, `toAccount()` (each `BelongsTo<Account, $this>`). `Database\Factories\DebtFocus\TransactionFactory` defaults to an `expense` against a freshly-created `Account`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DebtFocus/TransactionModelTest.php`:

```php
<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Transaction;
use App\Models\User;

test('an expense transaction persists against a single account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->create([
        'type' => 'expense',
        'account_id' => $account->id,
        'from_account_id' => null,
        'to_account_id' => null,
        'amount' => 42.50,
        'description' => 'Groceries',
        'occurred_at' => '2026-08-28',
    ]);

    $fresh = $transaction->fresh();

    expect($fresh->type)->toBe('expense');
    expect($fresh->account_id)->toBe($account->id);
    expect($fresh->from_account_id)->toBeNull();
    expect($fresh->to_account_id)->toBeNull();
    expect((float) $fresh->amount)->toBe(42.50);
    expect($fresh->description)->toBe('Groceries');
    expect($fresh->occurred_at->toDateString())->toBe('2026-08-28');
    expect($fresh->account->id)->toBe($account->id);
});

test('a transfer transaction persists with both legs and no single account_id', function () {
    $user = User::factory()->create();
    $from = Account::factory()->for($user)->create();
    $to = Account::factory()->for($user)->create();

    $transaction = Transaction::factory()->for($user)->create([
        'type' => 'transfer',
        'account_id' => null,
        'from_account_id' => $from->id,
        'to_account_id' => $to->id,
        'amount' => 100,
    ]);

    $fresh = $transaction->fresh();

    expect($fresh->type)->toBe('transfer');
    expect($fresh->account_id)->toBeNull();
    expect($fresh->fromAccount->id)->toBe($from->id);
    expect($fresh->toAccount->id)->toBe($to->id);
});

test('description is nullable', function () {
    $transaction = Transaction::factory()->create(['description' => null]);

    expect($transaction->fresh()->description)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/DebtFocus/TransactionModelTest.php`
Expected: FAIL — class `App\Models\DebtFocus\Transaction` not found.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_28_090100_create_debt_focus_transactions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('debt_focus_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('account_id')->nullable()->constrained('debt_focus_accounts')->cascadeOnDelete();
            $table->foreignId('from_account_id')->nullable()->constrained('debt_focus_accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('debt_focus_accounts')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->date('occurred_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debt_focus_transactions');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/DebtFocus/Transaction.php`:

```php
<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\TransactionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use HasFactory;

    protected $table = 'debt_focus_transactions';

    protected $fillable = [
        'user_id', 'type', 'account_id', 'from_account_id', 'to_account_id', 'amount', 'description', 'occurred_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'date',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function fromAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'from_account_id');
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function toAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'to_account_id');
    }
}
```

- [ ] **Step 5: Write the factory**

Create `database/factories/DebtFocus/TransactionFactory.php`:

```php
<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Transaction> */
class TransactionFactory extends Factory
{
    protected $model = Transaction::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'expense',
            'account_id' => Account::factory(),
            'from_account_id' => null,
            'to_account_id' => null,
            'amount' => fake()->randomFloat(2, 5, 500),
            'description' => fake()->words(2, true),
            'occurred_at' => now()->toDateString(),
        ];
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/DebtFocus/TransactionModelTest.php`
Expected: PASS, 3 tests.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_28_090100_create_debt_focus_transactions_table.php \
  app/Models/DebtFocus/Transaction.php \
  database/factories/DebtFocus/TransactionFactory.php \
  tests/Feature/DebtFocus/TransactionModelTest.php
git commit -m "feat: add DebtFocus Transaction model"
```

---

### Task 3: `LedgerService::recordIncome()` and `recordExpense()`

**Files:**
- Create: `app/Services/DebtFocus/LedgerService.php`
- Test: `tests/Feature/DebtFocus/LedgerServiceTest.php`

**Interfaces:**
- Consumes: `App\Models\DebtFocus\Account` (Task 1), `App\Models\DebtFocus\Transaction` (Task 2).
- Produces: `App\Services\DebtFocus\LedgerService::recordIncome(Account $account, float $amount, ?string $description = null, ?\Carbon\CarbonInterface $occurredAt = null): Transaction` and `recordExpense(...)` with the same signature. Both persist a `Transaction` row and mutate `$account->balance` in the same DB transaction. Task 4 depends on the private `applyEffect()` convention documented in Architecture above (positive/negative event, flipped by `is_liability`) but calls its own copy — no shared private method crosses tasks.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DebtFocus/LedgerServiceTest.php`:

```php
<?php

use App\Models\DebtFocus\Account;
use App\Services\DebtFocus\LedgerService;

test('income increases an asset account balance and records the transaction', function () {
    $account = Account::factory()->create(['balance' => 100]);

    $transaction = (new LedgerService)->recordIncome($account, 50, 'Paycheck');

    expect((float) $account->fresh()->balance)->toBe(150.0);
    expect($transaction->type)->toBe('income');
    expect($transaction->account_id)->toBe($account->id);
    expect((float) $transaction->amount)->toBe(50.0);
    expect($transaction->description)->toBe('Paycheck');
    expect($transaction->user_id)->toBe($account->user_id);
});

test('income decreases a liability account balance', function () {
    $card = Account::factory()->liability()->create(['balance' => 100]);

    (new LedgerService)->recordIncome($card, 30, 'Refund');

    expect((float) $card->fresh()->balance)->toBe(70.0);
});

test('expense decreases an asset account balance and records the transaction', function () {
    $account = Account::factory()->create(['balance' => 100]);

    $transaction = (new LedgerService)->recordExpense($account, 30, 'Groceries');

    expect((float) $account->fresh()->balance)->toBe(70.0);
    expect($transaction->type)->toBe('expense');
    expect($transaction->account_id)->toBe($account->id);
    expect((float) $transaction->amount)->toBe(30.0);
});

test('expense increases a liability account balance, since a purchase increases what is owed', function () {
    $card = Account::factory()->liability()->create(['balance' => 100]);

    (new LedgerService)->recordExpense($card, 30, 'New shoes');

    expect((float) $card->fresh()->balance)->toBe(130.0);
});

test('recordIncome and recordExpense default occurred_at to today', function () {
    $account = Account::factory()->create();

    $transaction = (new LedgerService)->recordIncome($account, 10);

    expect($transaction->occurred_at->toDateString())->toBe(now()->toDateString());
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact tests/Feature/DebtFocus/LedgerServiceTest.php`
Expected: FAIL — class `App\Services\DebtFocus\LedgerService` not found.

- [ ] **Step 3: Write the implementation**

Create `app/Services/DebtFocus/LedgerService.php`:

```php
<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

class LedgerService
{
    public function recordIncome(Account $account, float $amount, ?string $description = null, ?CarbonInterface $occurredAt = null): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description, $occurredAt) {
            $this->applyEffect($account, $amount, positive: true);

            return Transaction::create([
                'user_id' => $account->user_id,
                'type' => 'income',
                'account_id' => $account->id,
                'amount' => $amount,
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        });
    }

    public function recordExpense(Account $account, float $amount, ?string $description = null, ?CarbonInterface $occurredAt = null): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description, $occurredAt) {
            $this->applyEffect($account, $amount, positive: false);

            return Transaction::create([
                'user_id' => $account->user_id,
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => $amount,
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
            ]);
        });
    }

    /**
     * A "positive event" (income, or being a transfer's destination) increases an
     * asset account's balance and decreases a liability account's balance; a
     * "negative event" (expense, or being a transfer's source) does the opposite.
     */
    private function applyEffect(Account $account, float $amount, bool $positive): void
    {
        $delta = $positive ? $amount : -$amount;

        if ($account->is_liability) {
            $delta = -$delta;
        }

        $account->increment('balance', $delta);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact tests/Feature/DebtFocus/LedgerServiceTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/DebtFocus/LedgerService.php tests/Feature/DebtFocus/LedgerServiceTest.php
git commit -m "feat: add DebtFocus LedgerService income/expense recording"
```

---

### Task 4: `LedgerService::recordTransfer()`

**Files:**
- Modify: `app/Services/DebtFocus/LedgerService.php`
- Modify: `tests/Feature/DebtFocus/LedgerServiceTest.php`

**Interfaces:**
- Consumes: `Account`, `Transaction`, and the private `applyEffect()` already defined on `LedgerService` (Task 3) — this task reuses it, it does not redefine it.
- Produces: `LedgerService::recordTransfer(Account $from, Account $to, float $amount, ?string $description = null, ?\Carbon\CarbonInterface $occurredAt = null): Transaction`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DebtFocus/LedgerServiceTest.php`:

```php
test('a transfer between two asset accounts moves the balance from one to the other', function () {
    $checking = Account::factory()->create(['balance' => 500]);
    $savings = Account::factory()->create(['balance' => 200]);

    $transaction = (new LedgerService)->recordTransfer($checking, $savings, 100, 'Move to savings');

    expect((float) $checking->fresh()->balance)->toBe(400.0);
    expect((float) $savings->fresh()->balance)->toBe(300.0);
    expect($transaction->type)->toBe('transfer');
    expect($transaction->from_account_id)->toBe($checking->id);
    expect($transaction->to_account_id)->toBe($savings->id);
    expect($transaction->account_id)->toBeNull();
    expect($transaction->user_id)->toBe($checking->user_id);
});

test('a transfer to a liability account pays it down, the credit-card-payment case', function () {
    $checking = Account::factory()->create(['balance' => 500]);
    $card = Account::factory()->liability()->create(['balance' => 300]);

    (new LedgerService)->recordTransfer($checking, $card, 100, 'Card payment');

    expect((float) $checking->fresh()->balance)->toBe(400.0);
    expect((float) $card->fresh()->balance)->toBe(200.0);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/DebtFocus/LedgerServiceTest.php`
Expected: FAIL — call to undefined method `LedgerService::recordTransfer()`.

- [ ] **Step 3: Add `recordTransfer()`**

In `app/Services/DebtFocus/LedgerService.php`, add this method after `recordExpense()`:

```php
public function recordTransfer(Account $from, Account $to, float $amount, ?string $description = null, ?CarbonInterface $occurredAt = null): Transaction
{
    return DB::transaction(function () use ($from, $to, $amount, $description, $occurredAt) {
        $this->applyEffect($from, $amount, positive: false);
        $this->applyEffect($to, $amount, positive: true);

        return Transaction::create([
            'user_id' => $from->user_id,
            'type' => 'transfer',
            'from_account_id' => $from->id,
            'to_account_id' => $to->id,
            'amount' => $amount,
            'description' => $description,
            'occurred_at' => $occurredAt ?? now(),
        ]);
    });
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/DebtFocus/LedgerServiceTest.php`
Expected: PASS, 7 tests.

- [ ] **Step 5: Run the full DebtFocus suite**

Run: `php artisan test --compact --filter=DebtFocus`
Expected: PASS, all tests (existing Debt/PayoffEngine/PayoffPlan tests plus the new Account/Transaction/LedgerService tests).

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/DebtFocus/LedgerService.php tests/Feature/DebtFocus/LedgerServiceTest.php
git commit -m "feat: add DebtFocus LedgerService transfer recording"
```
