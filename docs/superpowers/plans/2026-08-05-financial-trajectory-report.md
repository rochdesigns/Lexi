# Financial Trajectory Report Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a `/trajectory` page to Habitat that answers "am I actually getting ahead?" by reporting net worth trend, savings rate, debt payoff (with what-if extra payments), emergency-fund and goal progress, investment/FIRE progress, monthly flow, and a signature "investment gain minus debt interest" metric.

**Architecture:** A new stateless `App\Services\TrajectoryReportService` owns all computation; every public method takes `User $user` as its explicit first parameter and returns an array that is either `['available' => false, 'reason' => '…']` or a fully populated payload. Historical trend data comes from two new daily snapshot tables (`account_balance_snapshots`, `debt_balance_snapshots`) populated by a new `finance:snapshot` artisan command (scheduled daily, with a `--backfill=N` reconstruction mode). Interest-vs-principal history comes from a new `debt_payments` ledger written by `DebtController::makePayment`. A thin `FinanceTrajectoryController` fans out to the service and Inertia-renders `resources/js/pages/Trajectory.vue`, which composes small presentational components under `resources/js/components/trajectory/`.

**Tech Stack:** Laravel 13 (PHP 8.4), Inertia v3, Vue 3 `<script setup lang="ts">`, vue-chartjs / chart.js, Pest 4, Laravel Wayfinder, Tailwind v4 (layout only).

## Global Constraints

- Every service method returns `['available' => false, 'reason' => '…']` when its input data is missing — never misleading zeros. Every consumer (controller, Vue) must honour this contract.
- Service methods are **public instance methods taking `User $user` as the explicit first parameter**; the service holds no user state. (This mirrors `ReportController`'s private compute-method style but decouples it from `$this->user`.)
- All money values are rounded with `round($value, 2)` before leaving the service; all percentages with `round($pct, 1)` unless stated otherwise.
- Currency is Philippine peso (`₱`) — reuse the `formatMoney` helper shape from `resources/js/pages/Reports.vue`.
- New Vue files follow the app-wide convention: **inline `style=` for visual detail**, Tailwind only for the shared page wrapper `class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px]"` and grid/flex utility layout. Do **not** rewrite in a Tailwind-first style.
- Palette (copied from `Reports.vue`): surface `#fffdf8`, border `#e0dacd`, ink `#211d17`, muted `#8a8172`, secondary text `#6b6355`, positive `#2f7d5b`, negative `#c0512c`, heading font `'Bricolage Grotesque'`.
- Pages declare no layout — the layout is resolved globally in `resources/js/app.ts:12`.
- `tests/Pest.php` already applies `RefreshDatabase` to everything in `tests/Feature`; do not re-declare `uses(RefreshDatabase::class)` in new Feature tests.
- Every test that depends on "now" calls `Carbon::setTestNow(...)` at the top and `Carbon::setTestNow();` at the end, matching `tests/Feature/ReportTest.php`.
- Do **not** run `vendor/bin/pint` per task — Task 21 runs it once over everything.
- Migrations use `php artisan make:migration` and copy the nullable-FK style of `database/migrations/2026_07_23_143704_add_goal_id_to_transactions_table.php` verbatim (`->after('x')->constrained()->nullOnDelete()` in `up()`, `dropForeignIdFor(Model::class)` in `down()`).
- Run the minimum test set per task: `php artisan test --compact --filter=<name>`.

---

## File Structure

### Created

| Path | Responsibility |
| --- | --- |
| `database/migrations/2026_08_05_100000_add_fi_fields_to_settings_table.php` | Adds `fi_number`, `fi_expected_return_rate`, `emergency_fund_target_months`, `investment_monthly_contribution_target` to `settings`. |
| `database/migrations/2026_08_05_100100_add_trajectory_fields_to_accounts_table.php` | Adds `cost_basis`, `is_emergency_fund` to `accounts`. |
| `database/migrations/2026_08_05_100200_add_debt_id_to_transactions_table.php` | Adds nullable `debt_id` FK to `transactions`. |
| `database/migrations/2026_08_05_100300_create_debt_payments_table.php` | Principal/interest ledger for each debt payment. |
| `database/migrations/2026_08_05_100400_create_account_balance_snapshots_table.php` | Daily per-account balance history. |
| `database/migrations/2026_08_05_100500_create_debt_balance_snapshots_table.php` | Daily per-debt balance history. |
| `app/Models/DebtPayment.php` | Eloquent model for `debt_payments`. |
| `app/Models/AccountBalanceSnapshot.php` | Eloquent model for `account_balance_snapshots`. |
| `app/Models/DebtBalanceSnapshot.php` | Eloquent model for `debt_balance_snapshots`. |
| `database/factories/DebtPaymentFactory.php` | Factory for `DebtPayment`. |
| `database/factories/AccountBalanceSnapshotFactory.php` | Factory for `AccountBalanceSnapshot`. |
| `database/factories/DebtBalanceSnapshotFactory.php` | Factory for `DebtBalanceSnapshot`. |
| `app/Console/Commands/RecordFinanceSnapshot.php` | `finance:snapshot` — daily recording plus `--backfill=N` reconstruction. |
| `app/Services/TrajectoryReportService.php` | All trajectory computations. |
| `app/Http/Controllers/FinanceTrajectoryController.php` | `index()` — validates `extra_monthly`, fans out to the service, renders `Trajectory`. |
| `resources/js/pages/Trajectory.vue` | Page shell: header, section composition, prop typing. |
| `resources/js/components/trajectory/format.ts` | Shared `formatMoney` / `formatPct` / `formatSigned` helpers for every trajectory component. |
| `resources/js/components/trajectory/UnavailableNote.vue` | Uniform empty-state note rendering a section's `reason`. |
| `resources/js/components/trajectory/NetWorthCard.vue` | Net worth headline + 12-month line trend. |
| `resources/js/components/trajectory/SavingsRateCard.vue` | Current savings rate + 6-month trend. |
| `resources/js/components/trajectory/DebtFreeCard.vue` | Debt-free date with the extra-monthly what-if input. |
| `resources/js/components/trajectory/DebtTable.vue` | Per-debt rows sorted by interest rate. |
| `resources/js/components/trajectory/InterestBleedingCard.vue` | Interest paid this month / YTD. |
| `resources/js/components/trajectory/PrincipalInterestTrend.vue` | Monthly principal-vs-interest split chart. |
| `resources/js/components/trajectory/DtiCard.vue` | Debt-to-income ratio + 6-month trend. |
| `resources/js/components/trajectory/EmergencyFundProgress.vue` | EF months covered vs target. |
| `resources/js/components/trajectory/SavingsGoalsList.vue` | Named goals + required monthly contribution. |
| `resources/js/components/trajectory/CashBufferTrend.vue` | Minimum monthly cash buffer, 12 months. |
| `resources/js/components/trajectory/InvestmentContributions.vue` | This month's contributions vs target. |
| `resources/js/components/trajectory/InvestmentVehicleTable.vue` | Per-vehicle value / cost basis / gain / allocation. |
| `resources/js/components/trajectory/AllocationChart.vue` | Donut of allocation by vehicle. |
| `resources/js/components/trajectory/FireProgress.vue` | FIRE progress bar + years-to-FI. |
| `resources/js/components/trajectory/MonthlyFlowCard.vue` | Income/expense/net, over-budget categories, fixed vs variable. |
| `resources/js/components/trajectory/SignatureMetricCard.vue` | Investment gain minus debt interest, 12-month trend, crossover month. |
| `resources/js/components/trajectory/ReportDonutChart.vue` | Generic doughnut wrapper mirroring `ReportLineChart.vue`. |
| `tests/Feature/FinanceTrajectoryReportTest.php` | Service calculations + empty states + controller/route. |
| `tests/Feature/RecordFinanceSnapshotTest.php` | Plain recording, backfill reconciliation, skip-and-warn. |

### Modified

| Path | Change |
| --- | --- |
| `app/Models/Setting.php:14-25` | Add the four FI fields to `$fillable` and `$casts`. |
| `app/Models/Account.php:14-16` | Add `cost_basis`, `is_emergency_fund` to `$fillable`/`$casts`; add `balanceSnapshots()` HasMany. |
| `database/factories/AccountFactory.php` | Add `cost_basis` + `is_emergency_fund` defaults and an `investment()` state. |
| `app/Models/Transaction.php:16` | Add `debt_id` to `$fillable`; add `debt()` BelongsTo. |
| `app/Models/Debt.php` | Add `transactions()`, `debtPayments()`, `balanceSnapshots()` HasMany relations. |
| `app/Http/Controllers/DebtController.php:143-164` | Split each payment into principal/interest, write a `DebtPayment`, set `transactions.debt_id`. |
| `app/Http/Controllers/TransactionController.php:12-38` | On destroy of a debt-linked transaction, restore `debts.balance` by the payment's principal. |
| `routes/console.php` | Add `use Illuminate\Support\Facades\Schedule;` and `Schedule::command('finance:snapshot')->daily();` (first scheduled job in the app). |
| `routes/web.php:76` | Add `Route::get('/trajectory', …)->name('trajectory');` right after the `/reports` route, same `auth`+`verified` group. |
| `database/seeders/DatabaseSeeder.php` | Add a `seedTrajectoryDemoData(User $user, Carbon $today): void` private method called from `run()` (extends the existing single-seeder convention, no new seeder class). |
| `tests/Feature/SeederTest.php` | Assert the new demo records exist; account count moves from 3 to 6. |
| `tests/Feature/DebtTest.php` | Add three cases covering the principal/interest split written by `makePayment`. |
| `tests/Feature/TransactionTest.php` | Add two cases covering debt-balance restoration on delete. |

---

### Task 1: Settings — FI planning fields

**Files:**
- Create: `database/migrations/2026_08_05_100000_add_fi_fields_to_settings_table.php`
- Modify: `app/Models/Setting.php:14-25`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: nothing (first task).
- Produces: `settings.fi_number` (nullable decimal 12,2), `settings.fi_expected_return_rate` (nullable decimal 5,2, default `6.00`), `settings.emergency_fund_target_months` (nullable unsignedTinyInteger, default `6`), `settings.investment_monthly_contribution_target` (nullable decimal 12,2). `Setting` casts: `fi_number` → `decimal:2`, `fi_expected_return_rate` → `decimal:2`, `emergency_fund_target_months` → `integer`, `investment_monthly_contribution_target` → `decimal:2`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
<?php

use App\Models\Setting;
use App\Models\User;

test('settings store financial independence planning fields', function () {
    $user = User::factory()->create();

    $setting = Setting::create([
        'user_id' => $user->id,
        'theme' => 'ocean',
        'budget_cycle_days' => 30,
        'fi_number' => 25000000,
        'fi_expected_return_rate' => 7.5,
        'emergency_fund_target_months' => 4,
        'investment_monthly_contribution_target' => 15000,
    ]);

    $fresh = $setting->fresh();

    expect((float) $fresh->fi_number)->toBe(25000000.0)
        ->and((float) $fresh->fi_expected_return_rate)->toBe(7.5)
        ->and($fresh->emergency_fund_target_months)->toBe(4)
        ->and((float) $fresh->investment_monthly_contribution_target)->toBe(15000.0);
});

test('financial independence fields fall back to sensible defaults', function () {
    $user = User::factory()->create();

    $setting = Setting::create(['user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30])->fresh();

    expect($setting->fi_number)->toBeNull()
        ->and((float) $setting->fi_expected_return_rate)->toBe(6.0)
        ->and($setting->emergency_fund_target_months)->toBe(6)
        ->and($setting->investment_monthly_contribution_target)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="financial independence"`
Expected: FAIL with an SQLite error such as `no such column: fi_number` (the columns do not exist yet).

- [ ] **Step 3: Write minimal implementation**

Generate the migration: `php artisan make:migration add_fi_fields_to_settings_table --no-interaction`, then rename the generated file to `2026_08_05_100000_add_fi_fields_to_settings_table.php` and write:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->decimal('fi_number', 12, 2)->nullable()->after('budget_cycle_anchor');
            $table->decimal('fi_expected_return_rate', 5, 2)->nullable()->default(6.00)->after('fi_number');
            $table->unsignedTinyInteger('emergency_fund_target_months')->nullable()->default(6)->after('fi_expected_return_rate');
            $table->decimal('investment_monthly_contribution_target', 12, 2)->nullable()->after('emergency_fund_target_months');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'fi_number',
                'fi_expected_return_rate',
                'emergency_fund_target_months',
                'investment_monthly_contribution_target',
            ]);
        });
    }
};
```

Update `app/Models/Setting.php` — replace the `$fillable` and `$casts` blocks:

```php
    protected $fillable = [
        'user_id',
        'theme',
        'budget_cycle_days',
        'budget_cycle_anchor',
        'fi_number',
        'fi_expected_return_rate',
        'emergency_fund_target_months',
        'investment_monthly_contribution_target',
    ];

    protected $casts = [
        'budget_cycle_days' => 'integer',
        'budget_cycle_anchor' => 'date',
        'fi_number' => 'decimal:2',
        'fi_expected_return_rate' => 'decimal:2',
        'emergency_fund_target_months' => 'integer',
        'investment_monthly_contribution_target' => 'decimal:2',
    ];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="financial independence"`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_05_100000_add_fi_fields_to_settings_table.php app/Models/Setting.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add financial independence planning fields to settings"
```

---

### Task 2: Accounts — cost basis and emergency-fund flag

**Files:**
- Create: `database/migrations/2026_08_05_100100_add_trajectory_fields_to_accounts_table.php`
- Modify: `app/Models/Account.php:14-16`
- Modify: `database/factories/AccountFactory.php`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `accounts.cost_basis` (nullable decimal 12,2), `accounts.is_emergency_fund` (boolean, default `false`). `Account` casts `cost_basis` → `decimal:2`, `is_emergency_fund` → `boolean`. `AccountFactory` gains an `investment()` state producing `type='investment'` with a non-null `cost_basis`, and an `emergencyFund()` state producing `type='cash'`, `is_emergency_fund=true`.

> `Account` already uses `HasFactory` (`app/Models/Account.php:12`) and `database/factories/AccountFactory.php` already exists — extend them, do not create them.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('accounts store cost basis and emergency fund flag', function () {
    $user = User::factory()->create();

    $investment = \App\Models\Account::factory()->investment()->for($user)->create([
        'balance' => 120000,
        'cost_basis' => 100000,
    ])->fresh();

    $emergency = \App\Models\Account::factory()->emergencyFund()->for($user)->create()->fresh();

    expect((float) $investment->cost_basis)->toBe(100000.0)
        ->and($investment->type)->toBe('investment')
        ->and($investment->is_emergency_fund)->toBeFalse()
        ->and($emergency->is_emergency_fund)->toBeTrue()
        ->and($emergency->type)->toBe('cash');
});
```

Add `use App\Models\Account;` to the file's import block (and keep `use App\Models\Setting; use App\Models\User;`).

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="cost basis and emergency fund flag"`
Expected: FAIL with `Call to undefined method Database\Factories\AccountFactory::investment()`.

- [ ] **Step 3: Write minimal implementation**

Generate + rename the migration to `2026_08_05_100100_add_trajectory_fields_to_accounts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->decimal('cost_basis', 12, 2)->nullable()->after('balance');
            $table->boolean('is_emergency_fund')->default(false)->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['cost_basis', 'is_emergency_fund']);
        });
    }
};
```

In `app/Models/Account.php`, replace the `$fillable`/`$casts` lines:

```php
    protected $fillable = ['user_id', 'name', 'institution', 'last4', 'balance', 'cost_basis', 'type', 'is_emergency_fund'];

    protected $casts = [
        'balance' => 'decimal:2',
        'cost_basis' => 'decimal:2',
        'is_emergency_fund' => 'boolean',
    ];
```

In `database/factories/AccountFactory.php`, add `'cost_basis' => null,` and `'is_emergency_fund' => false,` to `definition()` and append the two states:

```php
    public function investment(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'investment',
            'name' => fake()->randomElement(['Index Fund', 'Brokerage', 'Retirement']).' Account',
            'balance' => fake()->randomFloat(2, 20000, 500000),
            'cost_basis' => fake()->randomFloat(2, 15000, 400000),
            'is_emergency_fund' => false,
        ]);
    }

    public function emergencyFund(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => 'cash',
            'name' => 'Emergency Fund',
            'is_emergency_fund' => true,
        ]);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="cost basis and emergency fund flag"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_05_100100_add_trajectory_fields_to_accounts_table.php app/Models/Account.php database/factories/AccountFactory.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add cost basis and emergency fund flag to accounts"
```

---

### Task 3: Transactions — nullable `debt_id` link

**Files:**
- Create: `database/migrations/2026_08_05_100200_add_debt_id_to_transactions_table.php`
- Modify: `app/Models/Transaction.php:16` (`$fillable`) and the relation block below it
- Modify: `app/Models/Debt.php` (add `transactions()` HasMany)
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `transactions.debt_id` nullable FK → `debts.id`, `nullOnDelete()`, positioned `->after('goal_id')`. `Transaction::debt(): BelongsTo`. `Debt::transactions(): HasMany`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('a transaction can be linked to a debt and is unlinked when the debt is deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = \App\Models\Debt::factory()->for($user)->create();

    $transaction = \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'debt_id' => $debt->id,
        'type' => 'expense',
        'amount' => 500,
    ]);

    expect($transaction->fresh()->debt->id)->toBe($debt->id)
        ->and($debt->transactions()->pluck('id')->all())->toBe([$transaction->id]);

    $debt->delete();

    expect($transaction->fresh()->debt_id)->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="linked to a debt"`
Expected: FAIL with `no such column: debt_id` on the `transactions` insert.

- [ ] **Step 3: Write minimal implementation**

Migration `2026_08_05_100200_add_debt_id_to_transactions_table.php`, copying the `goal_id` pattern verbatim:

```php
<?php

use App\Models\Debt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('debt_id')->nullable()->after('goal_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropForeignIdFor(Debt::class);
        });
    }
};
```

In `app/Models/Transaction.php`, replace the `$fillable` line and append a relation:

```php
    protected $fillable = ['user_id', 'account_id', 'to_account_id', 'budget_category_id', 'sinking_fund_id', 'goal_id', 'debt_id', 'type', 'amount', 'merchant', 'transacted_at'];
```

```php
    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
```

In `app/Models/Debt.php`, add `use Illuminate\Database\Eloquent\Relations\HasMany;` and:

```php
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="linked to a debt"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_05_100200_add_debt_id_to_transactions_table.php app/Models/Transaction.php app/Models/Debt.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: link transactions to debts via nullable debt_id"
```

---

### Task 4: `debt_payments` ledger table, model and factory

**Files:**
- Create: `database/migrations/2026_08_05_100300_create_debt_payments_table.php`
- Create: `app/Models/DebtPayment.php`
- Create: `database/factories/DebtPaymentFactory.php`
- Modify: `app/Models/Debt.php` (add `debtPayments()` HasMany)
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: `transactions.debt_id` from Task 3 (the payment's transaction carries the same `debt_id`).
- Produces: table `debt_payments(id, user_id, debt_id, transaction_id, account_id, amount, principal_amount, interest_amount, paid_at, timestamps)` — all four FKs `cascadeOnDelete()`. `DebtPayment` model with `$fillable`, casts (`amount`/`principal_amount`/`interest_amount` → `decimal:2`, `paid_at` → `date`), relations `user()`, `debt()`, `transaction()`, `account()`. `Debt::debtPayments(): HasMany`. `DebtPaymentFactory` with a `definition()` that self-consistently satisfies `amount = principal_amount + interest_amount`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('debt payments record the principal and interest split and cascade with their transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = \App\Models\Debt::factory()->for($user)->create();
    $transaction = \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'debt_id' => $debt->id,
    ]);

    $payment = \App\Models\DebtPayment::create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'transaction_id' => $transaction->id,
        'account_id' => $account->id,
        'amount' => 5000,
        'principal_amount' => 4200,
        'interest_amount' => 800,
        'paid_at' => '2026-08-01',
    ]);

    expect((float) $payment->fresh()->principal_amount)->toBe(4200.0)
        ->and($payment->fresh()->paid_at->toDateString())->toBe('2026-08-01')
        ->and($debt->debtPayments()->count())->toBe(1)
        ->and($payment->transaction->id)->toBe($transaction->id);

    $transaction->delete();

    expect(\App\Models\DebtPayment::find($payment->id))->toBeNull();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="principal and interest split"`
Expected: FAIL with `Class "App\Models\DebtPayment" not found`.

- [ ] **Step 3: Write minimal implementation**

Run `php artisan make:model DebtPayment -mf --no-interaction`, then rename the migration to `2026_08_05_100300_create_debt_payments_table.php` and write:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->decimal('principal_amount', 10, 2);
            $table->decimal('interest_amount', 10, 2);
            $table->date('paid_at');
            $table->timestamps();

            $table->index(['user_id', 'paid_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
    }
};
```

`app/Models/DebtPayment.php`:

```php
<?php

namespace App\Models;

use Database\Factories\DebtPaymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtPayment extends Model
{
    /** @use HasFactory<DebtPaymentFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id', 'debt_id', 'transaction_id', 'account_id',
        'amount', 'principal_amount', 'interest_amount', 'paid_at',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'principal_amount' => 'decimal:2',
        'interest_amount' => 'decimal:2',
        'paid_at' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
```

`database/factories/DebtPaymentFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Debt;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtPaymentFactory extends Factory
{
    public function definition(): array
    {
        $amount = fake()->randomFloat(2, 500, 8000);
        $interest = round($amount * fake()->randomFloat(2, 0.05, 0.4), 2);

        return [
            'user_id' => User::factory(),
            'debt_id' => Debt::factory(),
            'transaction_id' => Transaction::factory(),
            'account_id' => Account::factory(),
            'amount' => $amount,
            'principal_amount' => round($amount - $interest, 2),
            'interest_amount' => $interest,
            'paid_at' => fake()->dateTimeBetween('-11 months', 'now')->format('Y-m-d'),
        ];
    }
}
```

In `app/Models/Debt.php` add:

```php
    public function debtPayments(): HasMany
    {
        return $this->hasMany(DebtPayment::class);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="principal and interest split"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_05_100300_create_debt_payments_table.php app/Models/DebtPayment.php app/Models/Debt.php database/factories/DebtPaymentFactory.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add debt_payments ledger with principal and interest split"
```

---

### Task 5: Balance snapshot tables, models and factories

**Files:**
- Create: `database/migrations/2026_08_05_100400_create_account_balance_snapshots_table.php`
- Create: `database/migrations/2026_08_05_100500_create_debt_balance_snapshots_table.php`
- Create: `app/Models/AccountBalanceSnapshot.php`
- Create: `app/Models/DebtBalanceSnapshot.php`
- Create: `database/factories/AccountBalanceSnapshotFactory.php`
- Create: `database/factories/DebtBalanceSnapshotFactory.php`
- Modify: `app/Models/Account.php` (add `balanceSnapshots()` HasMany)
- Modify: `app/Models/Debt.php` (add `balanceSnapshots()` HasMany)
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `account_balance_snapshots(id, user_id, account_id, balance, snapshot_date, timestamps)` with `unique(account_id, snapshot_date)`; `debt_balance_snapshots(id, user_id, debt_id, balance, snapshot_date, timestamps)` with `unique(debt_id, snapshot_date)`. Both models cast `balance` → `decimal:2`, `snapshot_date` → `date`, with `user()` + owner relations. `Account::balanceSnapshots(): HasMany`, `Debt::balanceSnapshots(): HasMany`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('balance snapshots are unique per owner per day', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = \App\Models\Debt::factory()->for($user)->create();

    \App\Models\AccountBalanceSnapshot::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'balance' => 1200.50,
        'snapshot_date' => '2026-08-01',
    ]);

    \App\Models\DebtBalanceSnapshot::create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'balance' => 90000,
        'snapshot_date' => '2026-08-01',
    ]);

    expect($account->balanceSnapshots()->count())->toBe(1)
        ->and((float) $account->balanceSnapshots()->first()->balance)->toBe(1200.50)
        ->and($debt->balanceSnapshots()->count())->toBe(1);

    expect(fn () => \App\Models\AccountBalanceSnapshot::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'balance' => 999,
        'snapshot_date' => '2026-08-01',
    ]))->toThrow(Illuminate\Database\UniqueConstraintViolationException::class);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="snapshots are unique per owner"`
Expected: FAIL with `Class "App\Models\AccountBalanceSnapshot" not found`.

- [ ] **Step 3: Write minimal implementation**

Run `php artisan make:model AccountBalanceSnapshot -mf --no-interaction` and `php artisan make:model DebtBalanceSnapshot -mf --no-interaction`, rename migrations, then write:

`2026_08_05_100400_create_account_balance_snapshots_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 10, 2);
            $table->date('snapshot_date');
            $table->timestamps();

            $table->unique(['account_id', 'snapshot_date']);
            $table->index(['user_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_balance_snapshots');
    }
};
```

`2026_08_05_100500_create_debt_balance_snapshots_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_balance_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->decimal('balance', 10, 2);
            $table->date('snapshot_date');
            $table->timestamps();

            $table->unique(['debt_id', 'snapshot_date']);
            $table->index(['user_id', 'snapshot_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_balance_snapshots');
    }
};
```

`app/Models/AccountBalanceSnapshot.php`:

```php
<?php

namespace App\Models;

use Database\Factories\AccountBalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountBalanceSnapshot extends Model
{
    /** @use HasFactory<AccountBalanceSnapshotFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'account_id', 'balance', 'snapshot_date'];

    protected $casts = [
        'balance' => 'decimal:2',
        'snapshot_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }
}
```

`app/Models/DebtBalanceSnapshot.php`:

```php
<?php

namespace App\Models;

use Database\Factories\DebtBalanceSnapshotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DebtBalanceSnapshot extends Model
{
    /** @use HasFactory<DebtBalanceSnapshotFactory> */
    use HasFactory;

    protected $fillable = ['user_id', 'debt_id', 'balance', 'snapshot_date'];

    protected $casts = [
        'balance' => 'decimal:2',
        'snapshot_date' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
```

`database/factories/AccountBalanceSnapshotFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AccountBalanceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => Account::factory(),
            'balance' => fake()->randomFloat(2, 0, 50000),
            'snapshot_date' => fake()->unique()->dateTimeBetween('-11 months', 'now')->format('Y-m-d'),
        ];
    }
}
```

`database/factories/DebtBalanceSnapshotFactory.php`:

```php
<?php

namespace Database\Factories;

use App\Models\Debt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class DebtBalanceSnapshotFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'debt_id' => Debt::factory(),
            'balance' => fake()->randomFloat(2, 0, 200000),
            'snapshot_date' => fake()->unique()->dateTimeBetween('-11 months', 'now')->format('Y-m-d'),
        ];
    }
}
```

Add to `app/Models/Account.php` (import `Illuminate\Database\Eloquent\Relations\HasMany`):

```php
    public function balanceSnapshots(): HasMany
    {
        return $this->hasMany(AccountBalanceSnapshot::class);
    }
```

Add to `app/Models/Debt.php`:

```php
    public function balanceSnapshots(): HasMany
    {
        return $this->hasMany(DebtBalanceSnapshot::class);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="snapshots are unique per owner"`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_08_05_1004* database/migrations/2026_08_05_1005* app/Models/AccountBalanceSnapshot.php app/Models/DebtBalanceSnapshot.php app/Models/Account.php app/Models/Debt.php database/factories/AccountBalanceSnapshotFactory.php database/factories/DebtBalanceSnapshotFactory.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add account and debt balance snapshot tables"
```

---

### Task 6: `DebtController::makePayment` — split each payment into principal and interest

**Files:**
- Modify: `app/Http/Controllers/DebtController.php:124-167` (the `makePayment` method; verify the exact line span before editing)
- Test: `tests/Feature/DebtTest.php`

**Interfaces:**
- Consumes: `DebtPayment` model + `debt_payments` table (Task 4), `transactions.debt_id` (Task 3).
- Produces: every debt payment now writes one `DebtPayment` row and stamps `transactions.debt_id`. New private helper on `DebtController`:
  `private function accruedInterest(Debt $debt, Carbon $paidAt): float` — annual-rate × elapsed-year-fraction interest on the debt's pre-payment balance since the last `DebtPayment.paid_at` (or `debt.created_at` when there is none).
- Behaviour change: an unlinked debt's `balance` is now reduced by `principal_amount` rather than the raw payment amount. When `interest_rate` is null (the `DebtFactory` default) `interest_amount` is `0.00`, so existing `DebtTest` expectations are unaffected.

**Interest formula (documented in a PHPDoc on the helper):** `annualRate = interest_rate / 100`, doubled through `× 12` when `interest_rate_period === 'monthly'`; `accrued = preBalance × annualRate × (daysElapsed / 365)`; `interest_amount = min(round(accrued, 2), paymentAmount)`; `principal_amount = paymentAmount − interest_amount`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/DebtTest.php` (the file already imports `Account`, `Debt`, `Transaction` and sets `$this->user` in `beforeEach`; add `use App\Models\DebtPayment;` and `use Carbon\Carbon;` if not already present):

```php
test('make-payment records a debt payment split into principal and interest', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    $debt = Debt::factory()->for($this->user)->create([
        'balance' => 100000,
        'interest_rate' => 12,
        'interest_rate_period' => 'annual',
        'created_at' => Carbon::parse('2026-07-01'),
    ]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 5000,
        'account_id' => $account->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    $payment = DebtPayment::where('debt_id', $debt->id)->firstOrFail();

    // 100000 * 0.12 * 31/365 = 1019.178... -> 1019.18
    expect((float) $payment->interest_amount)->toBe(1019.18)
        ->and((float) $payment->principal_amount)->toBe(3980.82)
        ->and((float) $payment->amount)->toBe(5000.0)
        ->and($payment->paid_at->toDateString())->toBe('2026-08-01')
        ->and($payment->account_id)->toBe($account->id)
        ->and($payment->user_id)->toBe($this->user->id);

    expect($payment->transaction->debt_id)->toBe($debt->id);

    // Only the principal reduces the debt
    expect((float) $debt->fresh()->balance)->toBe(96019.18);
});

test('make-payment with no interest rate books the whole payment as principal', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 10000]);
    $debt = Debt::factory()->for($this->user)->create(['balance' => 500, 'interest_rate' => null]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 200,
        'account_id' => $account->id,
        'transacted_at' => '2026-07-21',
    ])->assertRedirect();

    $payment = DebtPayment::where('debt_id', $debt->id)->firstOrFail();

    expect((float) $payment->interest_amount)->toBe(0.0)
        ->and((float) $payment->principal_amount)->toBe(200.0)
        ->and((float) $debt->fresh()->balance)->toBe(300.0);
});

test('make-payment accrues interest only since the previous payment', function (): void {
    $account = Account::factory()->for($this->user)->create(['balance' => 100000]);
    $debt = Debt::factory()->for($this->user)->create([
        'balance' => 100000,
        'interest_rate' => 12,
        'interest_rate_period' => 'annual',
        'created_at' => Carbon::parse('2026-01-01'),
    ]);

    DebtPayment::factory()->create([
        'user_id' => $this->user->id,
        'debt_id' => $debt->id,
        'account_id' => $account->id,
        'paid_at' => '2026-07-01',
    ]);

    $this->post("/debts/{$debt->id}/payment", [
        'amount' => 5000,
        'account_id' => $account->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    $latest = DebtPayment::where('debt_id', $debt->id)->orderByDesc('paid_at')->first();

    expect((float) $latest->interest_amount)->toBe(1019.18);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="make-payment records a debt payment split"`
Expected: FAIL — `DebtPayment::where(...)->firstOrFail()` throws `ModelNotFoundException` because no `debt_payments` row is written.

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/DebtController.php` add `use App\Models\DebtPayment;` to the imports, then replace the body of `makePayment` from the `Transaction::create([...])` call through the closing `return back();` with:

```php
        $paidAt = Carbon::parse($validated['transacted_at'])->startOfDay();
        $amount = round((float) $validated['amount'], 2);

        $interestAmount = min(round($this->accruedInterest($debt, $paidAt), 2), $amount);
        $principalAmount = round($amount - $interestAmount, 2);

        $transaction = Transaction::create([
            'user_id' => $user->id,
            'account_id' => $account->id,
            'budget_category_id' => $debt->budget_category_id,
            'debt_id' => $debt->id,
            'type' => 'expense',
            'amount' => $amount,
            'merchant' => $validated['merchant'] ?? $debt->name,
            'transacted_at' => $validated['transacted_at'],
        ]);

        DebtPayment::create([
            'user_id' => $user->id,
            'debt_id' => $debt->id,
            'transaction_id' => $transaction->id,
            'account_id' => $account->id,
            'amount' => $amount,
            'principal_amount' => $principalAmount,
            'interest_amount' => $interestAmount,
            'paid_at' => $paidAt->toDateString(),
        ]);

        $account->decrement('balance', $amount);

        if ($debt->account_id) {
            // CC-linked debt: decrement the CC outstanding balance, then sync
            $ccAccount = $user->accounts()->findOrFail($debt->account_id);
            $ccAccount->decrement('balance', $amount);
            $ccAccount->refresh()->syncLinkedDebt();
        } else {
            // Unlinked debt: only the principal portion reduces what is owed
            $newBalance = max(0, round((float) $debt->balance - $principalAmount, 2));
            $debt->update(['balance' => $newBalance]);
        }

        return back();
    }

    /**
     * Interest accrued on a debt's current balance since its last recorded payment
     * (or since the debt was created, when it has never been paid).
     *
     * annualRate = interest_rate / 100, multiplied by 12 when the stored rate is monthly.
     * accrued = balance * annualRate * (daysElapsed / 365).
     */
    private function accruedInterest(Debt $debt, Carbon $paidAt): float
    {
        if ($debt->interest_rate === null || (float) $debt->interest_rate <= 0) {
            return 0.0;
        }

        $annualRate = $debt->interest_rate_period === 'monthly'
            ? (float) $debt->interest_rate / 100 * 12
            : (float) $debt->interest_rate / 100;

        $lastPaidAt = $debt->debtPayments()->max('paid_at');

        $since = $lastPaidAt !== null
            ? Carbon::parse($lastPaidAt)->startOfDay()
            : Carbon::parse($debt->created_at)->startOfDay();

        $days = (int) round(abs($since->diffInDays($paidAt)));

        if ($days <= 0) {
            return 0.0;
        }

        return (float) $debt->balance * $annualRate * ($days / 365);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=DebtTest`
Expected: PASS — the three new tests plus every pre-existing `DebtTest` case (unchanged because `DebtFactory` defaults `interest_rate` to `null`).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/DebtController.php tests/Feature/DebtTest.php
git commit -m "feat: split debt payments into principal and interest"
```

---

### Task 7: `TransactionController::destroy` — restore debt balance for debt-linked transactions

**Files:**
- Modify: `app/Http/Controllers/TransactionController.php:12-38` (the `destroy` method; verify the exact line span before editing)
- Test: `tests/Feature/TransactionTest.php`

**Interfaces:**
- Consumes: `DebtPayment` (Task 4), `transactions.debt_id` (Task 3), and the principal-based decrement introduced in Task 6.
- Produces: deleting a debt-linked transaction now reverses the debt side symmetrically — CC-linked debts get the full amount added back to the linked credit account followed by `syncLinkedDebt()`; unlinked debts get `principal_amount` added back to `debts.balance`. The `DebtPayment` row itself is removed by the `transaction_id` FK's `cascadeOnDelete()`, so the reversal must read the row *before* `$transaction->delete()`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/TransactionTest.php` (add any missing imports: `App\Models\Debt`, `App\Models\DebtPayment`, `Carbon\Carbon`):

```php
test('deleting a debt payment transaction restores the debt balance by its principal', function (): void {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100000]);
    $debt = Debt::factory()->for($user)->create([
        'balance' => 100000,
        'interest_rate' => 12,
        'interest_rate_period' => 'annual',
        'created_at' => Carbon::parse('2026-07-01'),
    ]);

    $this->actingAs($user)->post("/debts/{$debt->id}/payment", [
        'amount' => 5000,
        'account_id' => $account->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    $payment = DebtPayment::where('debt_id', $debt->id)->firstOrFail();
    $transaction = $payment->transaction;

    expect((float) $debt->fresh()->balance)->toBe(96019.18);

    $this->actingAs($user)->delete("/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $debt->fresh()->balance)->toBe(100000.0)
        ->and((float) $account->fresh()->balance)->toBe(100000.0)
        ->and(DebtPayment::find($payment->id))->toBeNull();
});

test('deleting a payment on a credit-linked debt restores the credit account balance', function (): void {
    $user = User::factory()->create();
    $paying = Account::factory()->for($user)->create(['balance' => 50000, 'type' => 'cash']);
    $card = Account::factory()->for($user)->create(['balance' => 20000, 'type' => 'credit']);
    $debt = Debt::factory()->for($user)->create([
        'account_id' => $card->id,
        'balance' => 20000,
        'interest_rate' => null,
    ]);

    $this->actingAs($user)->post("/debts/{$debt->id}/payment", [
        'amount' => 2000,
        'account_id' => $paying->id,
        'transacted_at' => '2026-08-01',
    ])->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(18000.0);

    $transaction = DebtPayment::where('debt_id', $debt->id)->firstOrFail()->transaction;

    $this->actingAs($user)->delete("/transactions/{$transaction->id}")->assertRedirect();

    expect((float) $card->fresh()->balance)->toBe(20000.0)
        ->and((float) $debt->fresh()->balance)->toBe(20000.0);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="restores the debt balance by its principal"`
Expected: FAIL — the debt balance stays at `96019.18` instead of returning to `100000.0`.

- [ ] **Step 3: Write minimal implementation**

In `app/Http/Controllers/TransactionController.php`, add `use App\Models\DebtPayment;` to the imports and insert this block immediately before `$transaction->delete();` in `destroy()`:

```php
        // Reverse the debt side of a debt payment. The DebtPayment row is removed by the
        // transaction_id foreign key's cascade, so read it before deleting the transaction.
        if ($transaction->debt_id) {
            $debtPayment = DebtPayment::where('transaction_id', $transaction->id)->first();
            $debt = $transaction->debt;

            if ($debt && $debtPayment) {
                if ($debt->account_id) {
                    $creditAccount = $debt->account;
                    $creditAccount?->increment('balance', $debtPayment->amount);
                    $creditAccount?->refresh()->syncLinkedDebt();
                } else {
                    $debt->increment('balance', $debtPayment->principal_amount);
                }
            }
        }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=TransactionTest`
Expected: PASS — both new tests plus every pre-existing `TransactionTest` case.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TransactionController.php tests/Feature/TransactionTest.php
git commit -m "fix: restore debt balance when a debt payment transaction is deleted"
```

---

### Task 8: `finance:snapshot` — daily balance recording and scheduling

**Files:**
- Create: `app/Console/Commands/RecordFinanceSnapshot.php`
- Modify: `routes/console.php` (currently only the `inspire` command — this adds the app's first scheduled task, including the `Schedule` facade import)
- Test: `tests/Feature/RecordFinanceSnapshotTest.php`

**Interfaces:**
- Consumes: `AccountBalanceSnapshot`, `DebtBalanceSnapshot` (Task 5).
- Produces: artisan command `finance:snapshot {--date=} {--backfill=}` (the `--backfill` option is declared here but only implemented in Task 9). `handle(): int` returns `self::SUCCESS`. For every user, upserts one `AccountBalanceSnapshot` per account and one `DebtBalanceSnapshot` per debt for the target date, keyed on `(account_id|debt_id, snapshot_date)` so re-running the same day overwrites rather than duplicating.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/RecordFinanceSnapshotTest.php`:

```php
<?php

use App\Models\Account;
use App\Models\AccountBalanceSnapshot;
use App\Models\Debt;
use App\Models\DebtBalanceSnapshot;
use App\Models\User;
use Carbon\Carbon;

test('finance:snapshot records one snapshot per account and debt for today', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 2500]);
    $savings = Account::factory()->for($user)->create(['balance' => 40000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 90000]);

    $this->artisan('finance:snapshot')->assertSuccessful();

    expect(AccountBalanceSnapshot::count())->toBe(2)
        ->and(DebtBalanceSnapshot::count())->toBe(1);

    $snapshot = AccountBalanceSnapshot::where('account_id', $checking->id)->firstOrFail();

    expect((float) $snapshot->balance)->toBe(2500.0)
        ->and($snapshot->snapshot_date->toDateString())->toBe('2026-08-05')
        ->and($snapshot->user_id)->toBe($user->id)
        ->and((float) AccountBalanceSnapshot::where('account_id', $savings->id)->value('balance'))->toBe(40000.0)
        ->and((float) DebtBalanceSnapshot::where('debt_id', $debt->id)->value('balance'))->toBe(90000.0);

    Carbon::setTestNow();
});

test('finance:snapshot honours the --date option and is idempotent for the same day', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);

    $this->artisan('finance:snapshot', ['--date' => '2026-08-01'])->assertSuccessful();

    $account->update(['balance' => 7777]);

    $this->artisan('finance:snapshot', ['--date' => '2026-08-01'])->assertSuccessful();

    expect(AccountBalanceSnapshot::count())->toBe(1)
        ->and((float) AccountBalanceSnapshot::first()->balance)->toBe(7777.0)
        ->and(AccountBalanceSnapshot::first()->snapshot_date->toDateString())->toBe('2026-08-01');

    Carbon::setTestNow();
});

test('finance:snapshot keeps each users records separate', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $alice = User::factory()->create();
    $bob = User::factory()->create();
    Account::factory()->for($alice)->create(['balance' => 100]);
    Account::factory()->for($bob)->create(['balance' => 200]);

    $this->artisan('finance:snapshot')->assertSuccessful();

    expect(AccountBalanceSnapshot::where('user_id', $alice->id)->count())->toBe(1)
        ->and(AccountBalanceSnapshot::where('user_id', $bob->id)->count())->toBe(1);

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=RecordFinanceSnapshotTest`
Expected: FAIL with `The command "finance:snapshot" does not exist.`

- [ ] **Step 3: Write minimal implementation**

Run `php artisan make:command RecordFinanceSnapshot --no-interaction`, then write `app/Console/Commands/RecordFinanceSnapshot.php`:

```php
<?php

namespace App\Console\Commands;

use App\Models\AccountBalanceSnapshot;
use App\Models\DebtBalanceSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;

class RecordFinanceSnapshot extends Command
{
    protected $signature = 'finance:snapshot
                            {--date= : The date to snapshot (Y-m-d). Defaults to today}
                            {--backfill= : Reconstruct and write the previous N days of history instead of a single day}';

    protected $description = 'Record a daily balance snapshot for every account and debt';

    public function handle(): int
    {
        $date = $this->option('date') !== null
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today();

        User::query()->each(function (User $user) use ($date): void {
            $this->recordDay($user, $date);
        });

        return self::SUCCESS;
    }

    private function recordDay(User $user, Carbon $date): void
    {
        foreach ($user->accounts()->get() as $account) {
            AccountBalanceSnapshot::updateOrCreate(
                ['account_id' => $account->id, 'snapshot_date' => $date->toDateString()],
                ['user_id' => $user->id, 'balance' => round((float) $account->balance, 2)],
            );
        }

        foreach ($user->debts()->get() as $debt) {
            DebtBalanceSnapshot::updateOrCreate(
                ['debt_id' => $debt->id, 'snapshot_date' => $date->toDateString()],
                ['user_id' => $user->id, 'balance' => round((float) $debt->balance, 2)],
            );
        }
    }
}
```

Replace `routes/console.php` entirely (it currently holds only `inspire`):

```php
<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('finance:snapshot')->daily();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=RecordFinanceSnapshotTest`
Expected: PASS (3 tests). Also confirm the schedule registered: `php artisan schedule:list` lists `finance:snapshot`.

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/RecordFinanceSnapshot.php routes/console.php tests/Feature/RecordFinanceSnapshotTest.php
git commit -m "feat: add finance:snapshot command recording daily balances"
```

---

### Task 9: `finance:snapshot --backfill=N` — reconstruct history

**Files:**
- Modify: `app/Console/Commands/RecordFinanceSnapshot.php` (add the backfill branch and four private helpers)
- Test: `tests/Feature/RecordFinanceSnapshotTest.php`

**Interfaces:**
- Consumes: the command skeleton from Task 8, `DebtPayment` (Task 4).
- Produces:
  - `--backfill=N` writes snapshots for the `N`-day window ending today (today, today−1, …, today−(N−1)).
  - **Accounts** reconstruct *backwards*: `balanceAtEndOf(d) = currentBalance − Σ signed effects of transactions dated after d`. Signed effect on an account: `income` → `+amount`, `expense`/`transfer` → `−amount` when the account is `account_id`; `+amount` when it is `to_account_id`.
  - **Debts** reconstruct *forwards* from `debt.original` at `debt.created_at`: monthly interest compounds on each monthly anniversary of `created_at`, and each `DebtPayment.amount` is subtracted on its `paid_at`. If the reconstructed balance for today differs from the live `debts.balance` by more than `1.00`, the debt is skipped with `$this->warn(...)` and no debt snapshots are written for it.
  - Existing snapshots for a date are never overwritten by backfill (unlike the single-day mode, which upserts).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/RecordFinanceSnapshotTest.php` (add `use App\Models\DebtPayment;` and `use App\Models\Transaction;` to the imports):

```php
test('finance:snapshot --backfill reconstructs account balances by reversing transactions', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 5000]);

    Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'type' => 'expense',
        'amount' => 1000,
        'transacted_at' => '2026-08-05',
    ]);

    Transaction::factory()->for($user)->create([
        'account_id' => $account->id,
        'type' => 'income',
        'amount' => 2000,
        'transacted_at' => '2026-08-04',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])->assertSuccessful();

    $byDate = AccountBalanceSnapshot::where('account_id', $account->id)
        ->get()
        ->mapWithKeys(fn ($s) => [$s->snapshot_date->toDateString() => (float) $s->balance]);

    expect($byDate['2026-08-05'])->toBe(5000.0)
        ->and($byDate['2026-08-04'])->toBe(6000.0)
        ->and($byDate['2026-08-03'])->toBe(4000.0)
        ->and($byDate)->toHaveCount(3);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill leaves existing snapshots untouched', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 5000]);

    AccountBalanceSnapshot::create([
        'user_id' => $user->id,
        'account_id' => $account->id,
        'balance' => 999,
        'snapshot_date' => '2026-08-04',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])->assertSuccessful();

    expect((float) AccountBalanceSnapshot::where('snapshot_date', '2026-08-04')->value('balance'))->toBe(999.0)
        ->and(AccountBalanceSnapshot::count())->toBe(3);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill reconstructs a debt that reconciles with its current balance', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create([
        'original' => 100000,
        'balance' => 90000,
        'interest_rate' => null,
        'created_at' => Carbon::parse('2026-06-05'),
    ]);

    DebtPayment::factory()->create([
        'user_id' => $user->id,
        'debt_id' => $debt->id,
        'account_id' => $account->id,
        'amount' => 10000,
        'principal_amount' => 10000,
        'interest_amount' => 0,
        'paid_at' => '2026-07-10',
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])->assertSuccessful();

    $byDate = DebtBalanceSnapshot::where('debt_id', $debt->id)
        ->get()
        ->mapWithKeys(fn ($s) => [$s->snapshot_date->toDateString() => (float) $s->balance]);

    expect($byDate)->toHaveCount(3)
        ->and($byDate['2026-08-05'])->toBe(90000.0)
        ->and($byDate['2026-08-03'])->toBe(90000.0);

    Carbon::setTestNow();
});

test('finance:snapshot --backfill skips and warns about a debt that does not reconcile', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create([
        'name' => 'Mystery loan',
        'original' => 100000,
        'balance' => 12345,
        'interest_rate' => null,
        'created_at' => Carbon::parse('2026-06-05'),
    ]);

    $this->artisan('finance:snapshot', ['--backfill' => 3])
        ->expectsOutputToContain('Mystery loan')
        ->assertSuccessful();

    expect(DebtBalanceSnapshot::count())->toBe(0);

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="backfill"`
Expected: FAIL — `--backfill` is accepted but ignored, so `AccountBalanceSnapshot` holds only the single current-day row (`expect($byDate['2026-08-04'])` throws an undefined-key error).

- [ ] **Step 3: Write minimal implementation**

In `app/Console/Commands/RecordFinanceSnapshot.php`, add the imports `App\Models\Account`, `App\Models\Debt`, and replace `handle()` plus append the helpers:

```php
    public function handle(): int
    {
        $date = $this->option('date') !== null
            ? Carbon::parse($this->option('date'))->startOfDay()
            : Carbon::today();

        $backfill = $this->option('backfill') !== null ? max(1, (int) $this->option('backfill')) : null;

        User::query()->each(function (User $user) use ($date, $backfill): void {
            if ($backfill !== null) {
                $this->backfillAccounts($user, $backfill, $date);
                $this->backfillDebts($user, $backfill, $date);

                return;
            }

            $this->recordDay($user, $date);
        });

        return self::SUCCESS;
    }

    private function backfillAccounts(User $user, int $days, Carbon $today): void
    {
        $earliest = $today->copy()->subDays($days - 1);

        foreach ($user->accounts()->get() as $account) {
            $effects = $this->dailyAccountEffects($user, $account);
            $running = round((float) $account->balance, 2);

            for ($cursor = $today->copy(); $cursor->gte($earliest); $cursor->subDay()) {
                AccountBalanceSnapshot::firstOrCreate(
                    ['account_id' => $account->id, 'snapshot_date' => $cursor->toDateString()],
                    ['user_id' => $user->id, 'balance' => $running],
                );

                $running = round($running - ($effects[$cursor->toDateString()] ?? 0.0), 2);
            }
        }
    }

    /**
     * Signed effect each dated transaction had on this account's balance.
     *
     * @return array<string, float>
     */
    private function dailyAccountEffects(User $user, Account $account): array
    {
        $effects = [];

        $transactions = $user->transactions()
            ->where(fn ($query) => $query->where('account_id', $account->id)->orWhere('to_account_id', $account->id))
            ->get(['type', 'amount', 'account_id', 'to_account_id', 'transacted_at']);

        foreach ($transactions as $transaction) {
            $date = $transaction->transacted_at->toDateString();
            $amount = (float) $transaction->amount;
            $effect = 0.0;

            if ($transaction->account_id === $account->id) {
                $effect += $transaction->type === 'income' ? $amount : -$amount;
            }

            if ($transaction->to_account_id === $account->id) {
                $effect += $amount;
            }

            $effects[$date] = round(($effects[$date] ?? 0.0) + $effect, 2);
        }

        return $effects;
    }

    private function backfillDebts(User $user, int $days, Carbon $today): void
    {
        $earliest = $today->copy()->subDays($days - 1);

        foreach ($user->debts()->get() as $debt) {
            $history = $this->reconstructDebtHistory($debt, $today);
            $final = $history[$today->toDateString()] ?? null;

            if ($final === null || abs($final - (float) $debt->balance) > 1.00) {
                $this->warn("Skipping debt #{$debt->id} ({$debt->name}): reconstructed balance does not reconcile with the current balance.");

                continue;
            }

            for ($cursor = $earliest->copy(); $cursor->lte($today); $cursor->addDay()) {
                $date = $cursor->toDateString();

                if (! array_key_exists($date, $history)) {
                    continue;
                }

                DebtBalanceSnapshot::firstOrCreate(
                    ['debt_id' => $debt->id, 'snapshot_date' => $date],
                    ['user_id' => $user->id, 'balance' => $history[$date]],
                );
            }
        }
    }

    /**
     * Walk forward from the debt's original amount, compounding interest on each monthly
     * anniversary of its creation date and subtracting recorded payments on their paid_at.
     *
     * @return array<string, float>
     */
    private function reconstructDebtHistory(Debt $debt, Carbon $today): array
    {
        $monthlyRate = $debt->interest_rate === null ? 0.0
            : ($debt->interest_rate_period === 'monthly'
                ? (float) $debt->interest_rate / 100
                : (float) $debt->interest_rate / 100 / 12);

        $start = Carbon::parse($debt->created_at)->startOfDay();

        if ($start->gt($today)) {
            return [];
        }

        $paymentsByDate = $debt->debtPayments()
            ->get(['amount', 'paid_at'])
            ->groupBy(fn ($payment) => $payment->paid_at->toDateString())
            ->map(fn ($group) => (float) $group->sum('amount'));

        $balance = (float) $debt->original;
        $anniversaryDay = $start->day;
        $history = [];

        for ($cursor = $start->copy(); $cursor->lte($today); $cursor->addDay()) {
            if ($monthlyRate > 0 && ! $cursor->isSameDay($start) && $cursor->day === $anniversaryDay) {
                $balance *= (1 + $monthlyRate);
            }

            $balance = max(0, $balance - ($paymentsByDate[$cursor->toDateString()] ?? 0.0));

            $history[$cursor->toDateString()] = round($balance, 2);
        }

        return $history;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=RecordFinanceSnapshotTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Console/Commands/RecordFinanceSnapshot.php tests/Feature/RecordFinanceSnapshotTest.php
git commit -m "feat: reconstruct balance history with finance:snapshot --backfill"
```

---

### Task 10: `TrajectoryReportService` skeleton + `netWorth()`

**Files:**
- Create: `app/Services/TrajectoryReportService.php`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: `AccountBalanceSnapshot`, `DebtBalanceSnapshot` (Task 5).
- Produces:
  - `public function netWorth(User $user): array`
  - Available shape: `['available' => true, 'current' => float, 'accountTotal' => float, 'debtTotal' => float, 'trend' => list<array{month: string, label: string, value: float}>, 'changeAmount' => float, 'changePct' => float|null]`
  - Unavailable shape: `['available' => false, 'reason' => string, 'current' => float, 'accountTotal' => float, 'debtTotal' => float, 'trend' => []]` — this method is the **one documented exception** to the plain unavailable contract: the headline figures are always computable, so they are always returned; consumers must still gate all trend UI on `available === true`.
  - Shared private helper reused by later tasks: `private function lastBalancePerMonth(Collection $snapshots, string $ownerKey): array` → `['YYYY-MM' => float]`, summing each owner's latest snapshot inside each month.
  - Net worth uses the exact `AccountController::index` formula (`app/Http/Controllers/AccountController.php:23-30`): accounts of type `credit` that have a `linkedDebt` are excluded from `accountTotal`, and `netWorth = accountTotal − debts.sum('balance')`.
  - Trend covers the last 12 calendar months including the current one; months with no account snapshot are omitted. Fewer than 2 trend points → unavailable.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php` (add `use App\Models\AccountBalanceSnapshot; use App\Models\Debt; use App\Models\DebtBalanceSnapshot; use App\Services\TrajectoryReportService; use Carbon\Carbon;`):

```php
test('net worth reports the current figure and a monthly snapshot trend', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);
    $debt = Debt::factory()->for($user)->create(['balance' => 4000]);

    foreach ([['2026-07-31', 8000], ['2026-08-05', 10000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    foreach ([['2026-07-31', 5000], ['2026-08-05', 4000]] as [$date, $balance]) {
        DebtBalanceSnapshot::create([
            'user_id' => $user->id, 'debt_id' => $debt->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeTrue()
        ->and($result['current'])->toBe(6000.0)
        ->and($result['accountTotal'])->toBe(10000.0)
        ->and($result['debtTotal'])->toBe(4000.0)
        ->and($result['trend'])->toHaveCount(2)
        ->and($result['trend'][0])->toBe(['month' => '2026-07', 'label' => 'Jul 2026', 'value' => 3000.0])
        ->and($result['trend'][1]['value'])->toBe(6000.0)
        ->and($result['changeAmount'])->toBe(3000.0)
        ->and($result['changePct'])->toBe(100.0);

    Carbon::setTestNow();
});

test('net worth excludes credit accounts that are linked to a debt', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);
    $card = Account::factory()->for($user)->create(['balance' => 3000, 'type' => 'credit']);
    Debt::factory()->for($user)->create(['account_id' => $card->id, 'balance' => 3000]);

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['accountTotal'])->toBe(10000.0)
        ->and($result['current'])->toBe(7000.0);

    Carbon::setTestNow();
});

test('net worth is unavailable without at least two months of snapshots but still reports the headline', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 10000, 'type' => 'cash']);

    AccountBalanceSnapshot::create([
        'user_id' => $user->id, 'account_id' => $account->id,
        'balance' => 10000, 'snapshot_date' => '2026-08-05',
    ]);

    $result = app(TrajectoryReportService::class)->netWorth($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString()
        ->and($result['current'])->toBe(10000.0)
        ->and($result['trend'])->toBe([]);

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="net worth reports the current figure"`
Expected: FAIL with `Target class [App\Services\TrajectoryReportService] does not exist.`

- [ ] **Step 3: Write minimal implementation**

Run `php artisan make:class Services/TrajectoryReportService --no-interaction`, then write `app/Services/TrajectoryReportService.php`:

```php
<?php

namespace App\Services;

use App\Models\AccountBalanceSnapshot;
use App\Models\DebtBalanceSnapshot;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Computes every figure shown on the financial trajectory report.
 *
 * Each public method takes the user explicitly — the service holds no per-user state.
 * When the underlying data is missing a method returns ['available' => false, 'reason' => '…']
 * rather than zeros that would read as a real (and alarming) result.
 */
class TrajectoryReportService
{
    /**
     * @return array<string, mixed>
     */
    public function netWorth(User $user): array
    {
        $linkedCreditIds = $user->accounts()
            ->where('type', 'credit')
            ->whereHas('linkedDebt')
            ->pluck('id');

        $accountTotal = round((float) $user->accounts()->whereNotIn('id', $linkedCreditIds)->sum('balance'), 2);
        $debtTotal = round((float) $user->debts()->sum('balance'), 2);
        $current = round($accountTotal - $debtTotal, 2);

        $windowStart = Carbon::now()->startOfMonth()->subMonths(11);

        $accountByMonth = $this->lastBalancePerMonth(
            AccountBalanceSnapshot::where('user_id', $user->id)
                ->whereNotIn('account_id', $linkedCreditIds)
                ->where('snapshot_date', '>=', $windowStart->toDateString())
                ->orderBy('snapshot_date')
                ->get(),
            'account_id',
        );

        $debtByMonth = $this->lastBalancePerMonth(
            DebtBalanceSnapshot::where('user_id', $user->id)
                ->where('snapshot_date', '>=', $windowStart->toDateString())
                ->orderBy('snapshot_date')
                ->get(),
            'debt_id',
        );

        $trend = [];

        foreach ($this->monthWindow(12) as $month) {
            $key = $month->format('Y-m');

            if (! array_key_exists($key, $accountByMonth)) {
                continue;
            }

            $trend[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'value' => round($accountByMonth[$key] - ($debtByMonth[$key] ?? 0.0), 2),
            ];
        }

        $headline = [
            'current' => $current,
            'accountTotal' => $accountTotal,
            'debtTotal' => $debtTotal,
        ];

        if (count($trend) < 2) {
            return [
                'available' => false,
                'reason' => 'Not enough balance history yet — net worth trend needs snapshots from at least two months.',
                ...$headline,
                'trend' => [],
            ];
        }

        $first = $trend[0]['value'];
        $last = $trend[count($trend) - 1]['value'];

        return [
            'available' => true,
            ...$headline,
            'trend' => $trend,
            'changeAmount' => round($last - $first, 2),
            'changePct' => $first != 0.0 ? round(($last - $first) / abs($first) * 100, 1) : null,
        ];
    }

    /**
     * The last `$count` calendar months, oldest first, including the current month.
     *
     * @return list<Carbon>
     */
    protected function monthWindow(int $count): array
    {
        $months = [];

        for ($offset = $count - 1; $offset >= 0; $offset--) {
            $months[] = Carbon::now()->startOfMonth()->subMonths($offset);
        }

        return $months;
    }

    /**
     * Sum of each owner's latest snapshot within each calendar month.
     *
     * @param  Collection<int, AccountBalanceSnapshot|DebtBalanceSnapshot>  $snapshots
     * @param  'account_id'|'debt_id'  $ownerKey
     * @return array<string, float>
     */
    protected function lastBalancePerMonth(Collection $snapshots, string $ownerKey): array
    {
        $latest = [];

        foreach ($snapshots as $snapshot) {
            $month = $snapshot->snapshot_date->format('Y-m');
            $owner = $snapshot->{$ownerKey};
            $date = $snapshot->snapshot_date->toDateString();

            if (! isset($latest[$month][$owner]) || $latest[$month][$owner]['date'] < $date) {
                $latest[$month][$owner] = ['date' => $date, 'balance' => (float) $snapshot->balance];
            }
        }

        $totals = [];

        foreach ($latest as $month => $owners) {
            $totals[$month] = round(array_sum(array_column($owners, 'balance')), 2);
        }

        return $totals;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="net worth"`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TrajectoryReportService.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add TrajectoryReportService with net worth trend"
```

---

### Task 11: `TrajectoryReportService::savingsRate()`

**Files:**
- Modify: `app/Services/TrajectoryReportService.php` (add one public method + one private helper)
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: `monthWindow(int $count): array` from Task 10.
- Produces:
  - `public function savingsRate(User $user): array`
  - Available shape: `['available' => true, 'income' => float, 'expenses' => float, 'net' => float, 'rate' => float|null, 'trend' => list<array{month: string, label: string, income: float, expenses: float, rate: float|null}>, 'averageRate' => float|null]`
  - `rate = (income − expenses) / income × 100`, rounded to 1 dp, and **`null` whenever income is 0** — never `0.0`, which would read as "saved nothing" instead of "no income recorded".
  - `trend` always holds exactly 6 entries (oldest first), including months with no activity.
  - `averageRate` is the mean of the non-null trend rates, or `null` if every month is null.
  - Unavailable when the 6-month window contains no income or expense transactions at all.
  - New shared private helper reused by Tasks 12–16: `private function monthlyTotals(User $user, Carbon $month): array` → `['income' => float, 'expenses' => float]` for one calendar month.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('savings rate reports the current month and a six month trend', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $book = function (string $type, float $amount, string $date) use ($user, $account) {
        \App\Models\Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'type' => $type, 'amount' => $amount, 'transacted_at' => $date,
        ]);
    };

    $book('income', 50000, '2026-08-02');
    $book('expense', 30000, '2026-08-03');
    $book('income', 40000, '2026-07-02');
    $book('expense', 40000, '2026-07-03');

    $result = app(TrajectoryReportService::class)->savingsRate($user);

    expect($result['available'])->toBeTrue()
        ->and($result['income'])->toBe(50000.0)
        ->and($result['expenses'])->toBe(30000.0)
        ->and($result['net'])->toBe(20000.0)
        ->and($result['rate'])->toBe(40.0)
        ->and($result['trend'])->toHaveCount(6)
        ->and($result['trend'][5])->toBe(['month' => '2026-08', 'label' => 'Aug 2026', 'income' => 50000.0, 'expenses' => 30000.0, 'rate' => 40.0])
        ->and($result['trend'][4]['rate'])->toBe(0.0)
        ->and($result['trend'][0]['rate'])->toBeNull()
        ->and($result['averageRate'])->toBe(20.0);

    Carbon::setTestNow();
});

test('savings rate is null rather than zero when there is no income this month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 1000, 'transacted_at' => '2026-08-02',
    ]);

    $result = app(TrajectoryReportService::class)->savingsRate($user);

    expect($result['available'])->toBeTrue()
        ->and($result['rate'])->toBeNull()
        ->and($result['expenses'])->toBe(1000.0);

    Carbon::setTestNow();
});

test('savings rate is unavailable with no transactions in the window', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();

    $result = app(TrajectoryReportService::class)->savingsRate($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="savings rate"`
Expected: FAIL with `Call to undefined method App\Services\TrajectoryReportService::savingsRate()`.

- [ ] **Step 3: Write minimal implementation**

Add to `app/Services/TrajectoryReportService.php`:

```php
    /**
     * @return array<string, mixed>
     */
    public function savingsRate(User $user): array
    {
        $months = $this->monthWindow(6);
        $trend = [];
        $hasActivity = false;

        foreach ($months as $month) {
            $totals = $this->monthlyTotals($user, $month);

            if ($totals['income'] > 0 || $totals['expenses'] > 0) {
                $hasActivity = true;
            }

            $trend[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'income' => $totals['income'],
                'expenses' => $totals['expenses'],
                'rate' => $totals['income'] > 0
                    ? round(($totals['income'] - $totals['expenses']) / $totals['income'] * 100, 1)
                    : null,
            ];
        }

        if (! $hasActivity) {
            return [
                'available' => false,
                'reason' => 'No income or spending recorded in the last six months.',
            ];
        }

        $current = $trend[count($trend) - 1];
        $rates = array_values(array_filter(array_column($trend, 'rate'), fn ($rate) => $rate !== null));

        return [
            'available' => true,
            'income' => $current['income'],
            'expenses' => $current['expenses'],
            'net' => round($current['income'] - $current['expenses'], 2),
            'rate' => $current['rate'],
            'trend' => $trend,
            'averageRate' => $rates !== [] ? round(array_sum($rates) / count($rates), 1) : null,
        ];
    }

    /**
     * Income and expense totals for one calendar month.
     *
     * @return array{income: float, expenses: float}
     */
    protected function monthlyTotals(User $user, Carbon $month): array
    {
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $rows = $user->transactions()
            ->whereBetween('transacted_at', [$start, $end])
            ->whereIn('type', ['income', 'expense'])
            ->get(['type', 'amount']);

        return [
            'income' => round((float) $rows->where('type', 'income')->sum('amount'), 2),
            'expenses' => round((float) $rows->where('type', 'expense')->sum('amount'), 2),
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="savings rate"`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TrajectoryReportService.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add savings rate calculation to TrajectoryReportService"
```

---

### Task 12: `TrajectoryReportService::debtSummary()` + generalized amortization

**Files:**
- Modify: `app/Services/TrajectoryReportService.php`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: `monthWindow()`, `monthlyTotals()` (Tasks 10–11), `DebtPayment` (Task 4).
- Produces:
  - `public function simulatePayoff(array $debts, float $extraMonthly = 0.0): array` — generalized form of the loop in `ReportController::computePayoff()` (`app/Http/Controllers/ReportController.php`, the `while ($balance > 0 && $months < 600)` block). Input: `list<array{balance: float, perMonth: float, monthlyRate: float}>`. Output: `array{months: int|null, onTrack: bool, perDebtMonths: array<int, int|null>}`.
    - Avalanche allocation: every active debt receives its own `perMonth`; the **entire** `$extraMonthly` goes to the active debt with the highest `monthlyRate` (ties resolved by input order). The target is re-chosen every month, so the extra cascades to the next-highest debt once the current one is cleared.
    - The `perMonth <= interestAccrued` guard from `computePayoff` is re-evaluated **per debt, per month** (a debt can become payable once the extra is redirected to it); a debt that fails the guard is marked not-on-track and dropped from the simulation.
    - Freed-up `perMonth` from a cleared debt is **not** rolled into the remaining debts (documented simplification — only the user's explicit extra cascades).
  - `public function debtSummary(User $user, ?float $extraMonthly = null): array`
  - Available shape: `['available' => true, 'totalRemaining' => float, 'totalMonthly' => float, 'extraMonthly' => float, 'debts' => list<array{id: int, name: string, icon: string, color: string, bg: string, balance: float, perMonth: float, interestRate: float|null, interestRatePeriod: string, months: int|null, payoffDate: string|null}>, 'debtFreeMonths' => int|null, 'debtFreeDate' => string|null, 'onTrack' => bool, 'baselineDebtFreeMonths' => int|null, 'monthsSaved' => int|null, 'interestThisMonth' => float, 'interestYearToDate' => float, 'principalInterestTrend' => list<array{month: string, label: string, principal: float, interest: float}>, 'debtToIncome' => array{ratio: float|null, monthlyDebtPayments: float, income: float, isEstimated: bool, trend: list<array{month: string, label: string, ratio: float|null}>}]`
  - `debts` is sorted by `interest_rate` descending with nulls last.
  - DTI numerator is the month's `debt_payments.amount` sum; when the month has no recorded payments it falls back to `sum(debts.per_month)` and sets `isEstimated => true`. Ratio is `null` when the month has no income.
  - Unavailable when the user has no debts with a positive balance.
  - New private helper: `private function monthlyRate(Debt $debt): float` (mirrors `computePayoff`: `null` → `0.0`, `monthly` → `rate/100`, `annual` → `rate/100/12`).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php` (add `use App\Models\DebtPayment;`):

```php
test('debt summary sorts by interest rate and projects a debt free date', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Card', 'balance' => 10000, 'per_month' => 1000, 'interest_rate' => 18, 'interest_rate_period' => 'annual']);
    Debt::factory()->for($user)->create(['name' => 'Loan', 'balance' => 3000, 'per_month' => 1000, 'interest_rate' => 6, 'interest_rate_period' => 'annual']);
    Debt::factory()->for($user)->create(['name' => 'Friend', 'balance' => 500, 'per_month' => 500, 'interest_rate' => null]);

    $result = app(TrajectoryReportService::class)->debtSummary($user);

    expect($result['available'])->toBeTrue()
        ->and($result['totalRemaining'])->toBe(13500.0)
        ->and($result['totalMonthly'])->toBe(2500.0)
        ->and(array_column($result['debts'], 'name'))->toBe(['Card', 'Loan', 'Friend'])
        ->and($result['debts'][0]['interestRate'])->toBe(18.0)
        ->and($result['debts'][2]['interestRate'])->toBeNull()
        ->and($result['onTrack'])->toBeTrue()
        ->and($result['debtFreeMonths'])->toBeInt()
        ->and($result['debtFreeDate'])->toBeString()
        ->and($result['extraMonthly'])->toBe(0.0);

    Carbon::setTestNow();
});

test('an extra monthly payment shortens the debt free date avalanche style', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'A', 'balance' => 10000, 'per_month' => 1000, 'interest_rate' => null]);
    Debt::factory()->for($user)->create(['name' => 'B', 'balance' => 3000, 'per_month' => 1000, 'interest_rate' => null]);

    $service = app(TrajectoryReportService::class);

    expect($service->debtSummary($user)['debtFreeMonths'])->toBe(10);

    $boosted = $service->debtSummary($user, 1000.0);

    expect($boosted['extraMonthly'])->toBe(1000.0)
        ->and($boosted['debtFreeMonths'])->toBe(5)
        ->and($boosted['baselineDebtFreeMonths'])->toBe(10)
        ->and($boosted['monthsSaved'])->toBe(5);

    Carbon::setTestNow();
});

test('a debt whose payment cannot cover its interest is reported as not on track', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['balance' => 100000, 'per_month' => 100, 'interest_rate' => 24, 'interest_rate_period' => 'annual']);

    $result = app(TrajectoryReportService::class)->debtSummary($user);

    expect($result['onTrack'])->toBeFalse()
        ->and($result['debtFreeMonths'])->toBeNull()
        ->and($result['debtFreeDate'])->toBeNull()
        ->and($result['debts'][0]['months'])->toBeNull();

    Carbon::setTestNow();
});

test('debt summary reports interest bled this month and year to date with a monthly split trend', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 5000, 'interest_rate' => null]);

    foreach ([
        ['2026-08-01', 5000, 4200, 800],
        ['2026-03-01', 5000, 4500, 500],
        ['2025-12-01', 5000, 4001, 999],
    ] as [$date, $amount, $principal, $interest]) {
        DebtPayment::factory()->create([
            'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id,
            'amount' => $amount, 'principal_amount' => $principal, 'interest_amount' => $interest,
            'paid_at' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->debtSummary($user);

    expect($result['interestThisMonth'])->toBe(800.0)
        ->and($result['interestYearToDate'])->toBe(1300.0)
        ->and($result['principalInterestTrend'])->toHaveCount(12)
        ->and($result['principalInterestTrend'][11])->toBe(['month' => '2026-08', 'label' => 'Aug 2026', 'principal' => 4200.0, 'interest' => 800.0]);

    Carbon::setTestNow();
});

test('debt to income uses recorded payments and falls back to scheduled payments', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 4000, 'interest_rate' => null]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'income', 'amount' => 50000, 'transacted_at' => '2026-08-01',
    ]);

    $estimated = app(TrajectoryReportService::class)->debtSummary($user)['debtToIncome'];

    expect($estimated['isEstimated'])->toBeTrue()
        ->and($estimated['monthlyDebtPayments'])->toBe(4000.0)
        ->and($estimated['ratio'])->toBe(8.0);

    DebtPayment::factory()->create([
        'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id,
        'amount' => 5000, 'principal_amount' => 5000, 'interest_amount' => 0, 'paid_at' => '2026-08-02',
    ]);

    $actual = app(TrajectoryReportService::class)->debtSummary($user)['debtToIncome'];

    expect($actual['isEstimated'])->toBeFalse()
        ->and($actual['monthlyDebtPayments'])->toBe(5000.0)
        ->and($actual['ratio'])->toBe(10.0)
        ->and($actual['trend'])->toHaveCount(6);

    Carbon::setTestNow();
});

test('debt summary is unavailable with no outstanding debts', function () {
    $user = User::factory()->create();

    $result = app(TrajectoryReportService::class)->debtSummary($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="debt summary"`
Expected: FAIL with `Call to undefined method App\Services\TrajectoryReportService::debtSummary()`.

- [ ] **Step 3: Write minimal implementation**

Add `use App\Models\Debt;` and `use App\Models\DebtPayment;` to the service imports, then add:

```php
    /**
     * Generalized amortization simulation, extracted from ReportController::computePayoff().
     *
     * Each active debt pays its own perMonth every month; the whole $extraMonthly is applied
     * avalanche-style to the highest-rate active debt, re-chosen each month. A debt whose
     * allocated payment cannot cover its own accrued interest is dropped as not-on-track.
     *
     * @param  list<array{balance: float, perMonth: float, monthlyRate: float}>  $debts
     * @return array{months: int|null, onTrack: bool, perDebtMonths: array<int, int|null>}
     */
    public function simulatePayoff(array $debts, float $extraMonthly = 0.0): array
    {
        $state = [];

        foreach ($debts as $key => $debt) {
            $state[$key] = [
                'balance' => (float) $debt['balance'],
                'perMonth' => (float) $debt['perMonth'],
                'monthlyRate' => (float) $debt['monthlyRate'],
                'months' => (float) $debt['balance'] <= 0 ? 0 : null,
                'onTrack' => true,
            ];
        }

        $months = 0;

        while ($months < 600) {
            $active = array_keys(array_filter($state, fn (array $debt) => $debt['onTrack'] && $debt['balance'] > 0));

            if ($active === []) {
                break;
            }

            usort($active, fn (int $a, int $b) => $state[$b]['monthlyRate'] <=> $state[$a]['monthlyRate']);
            $target = $active[0];
            $months++;

            foreach ($active as $key) {
                $payment = $state[$key]['perMonth'] + ($key === $target ? $extraMonthly : 0.0);
                $interest = $state[$key]['balance'] * $state[$key]['monthlyRate'];

                if ($payment <= $interest) {
                    $state[$key]['onTrack'] = false;

                    continue;
                }

                $state[$key]['balance'] = $state[$key]['balance'] + $interest - $payment;

                if ($state[$key]['balance'] <= 0) {
                    $state[$key]['balance'] = 0.0;
                    $state[$key]['months'] = $months;
                }
            }
        }

        $perDebtMonths = [];
        $allCleared = true;
        $longest = 0;

        foreach ($state as $key => $debt) {
            $cleared = $debt['onTrack'] && $debt['months'] !== null;
            $perDebtMonths[$key] = $cleared ? $debt['months'] : null;

            if (! $cleared) {
                $allCleared = false;

                continue;
            }

            $longest = max($longest, (int) $debt['months']);
        }

        return [
            'months' => $allCleared ? $longest : null,
            'onTrack' => $allCleared,
            'perDebtMonths' => $perDebtMonths,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function debtSummary(User $user, ?float $extraMonthly = null): array
    {
        $debts = $user->debts()->where('balance', '>', 0)->orderBy('position')->orderBy('id')->get();

        if ($debts->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'No outstanding debts — nothing is accruing interest right now.',
            ];
        }

        $extra = max(0.0, (float) ($extraMonthly ?? 0.0));
        $today = Carbon::now()->startOfDay();

        $sorted = $debts
            ->sortByDesc(fn (Debt $debt) => $debt->interest_rate === null ? -1.0 : (float) $debt->interest_rate)
            ->values();

        $inputs = $sorted->map(fn (Debt $debt) => [
            'balance' => (float) $debt->balance,
            'perMonth' => (float) $debt->per_month,
            'monthlyRate' => $this->monthlyRate($debt),
        ])->all();

        $baseline = $this->simulatePayoff($inputs, 0.0);
        $projected = $extra > 0.0 ? $this->simulatePayoff($inputs, $extra) : $baseline;

        $rows = $sorted->values()->map(function (Debt $debt, int $index) use ($projected, $today) {
            $months = $projected['perDebtMonths'][$index] ?? null;

            return [
                'id' => $debt->id,
                'name' => $debt->name,
                'icon' => $debt->icon,
                'color' => $debt->color,
                'bg' => $debt->bg,
                'balance' => round((float) $debt->balance, 2),
                'perMonth' => round((float) $debt->per_month, 2),
                'interestRate' => $debt->interest_rate !== null ? (float) $debt->interest_rate : null,
                'interestRatePeriod' => $debt->interest_rate_period ?? 'annual',
                'months' => $months,
                'payoffDate' => $months !== null ? $today->copy()->addMonthsNoOverflow($months)->format('M Y') : null,
            ];
        })->all();

        $thisMonth = Carbon::now()->startOfMonth();
        $paymentsThisMonth = $this->debtPaymentTotals($user, $thisMonth);

        $interestYearToDate = round((float) DebtPayment::where('user_id', $user->id)
            ->whereBetween('paid_at', [Carbon::now()->startOfYear()->toDateString(), Carbon::now()->endOfYear()->toDateString()])
            ->sum('interest_amount'), 2);

        $splitTrend = [];

        foreach ($this->monthWindow(12) as $month) {
            $totals = $this->debtPaymentTotals($user, $month);
            $splitTrend[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'principal' => $totals['principal'],
                'interest' => $totals['interest'],
            ];
        }

        $scheduledMonthly = round((float) $debts->sum('per_month'), 2);
        $dtiTrend = [];

        foreach ($this->monthWindow(6) as $month) {
            $dtiTrend[] = [
                'month' => $month->format('Y-m'),
                'label' => $month->format('M Y'),
                'ratio' => $this->debtToIncomeRatio($user, $month, $scheduledMonthly),
            ];
        }

        $currentIncome = $this->monthlyTotals($user, $thisMonth)['income'];
        $numerator = $paymentsThisMonth['amount'] > 0 ? $paymentsThisMonth['amount'] : $scheduledMonthly;

        return [
            'available' => true,
            'totalRemaining' => round((float) $debts->sum('balance'), 2),
            'totalMonthly' => $scheduledMonthly,
            'extraMonthly' => round($extra, 2),
            'debts' => $rows,
            'debtFreeMonths' => $projected['months'],
            'debtFreeDate' => $projected['months'] !== null
                ? $today->copy()->addMonthsNoOverflow($projected['months'])->format('M Y')
                : null,
            'onTrack' => $projected['onTrack'],
            'baselineDebtFreeMonths' => $baseline['months'],
            'monthsSaved' => $baseline['months'] !== null && $projected['months'] !== null
                ? $baseline['months'] - $projected['months']
                : null,
            'interestThisMonth' => $paymentsThisMonth['interest'],
            'interestYearToDate' => $interestYearToDate,
            'principalInterestTrend' => $splitTrend,
            'debtToIncome' => [
                'ratio' => $this->debtToIncomeRatio($user, $thisMonth, $scheduledMonthly),
                'monthlyDebtPayments' => round($numerator, 2),
                'income' => $currentIncome,
                'isEstimated' => $paymentsThisMonth['amount'] <= 0,
                'trend' => $dtiTrend,
            ],
        ];
    }

    protected function monthlyRate(Debt $debt): float
    {
        if ($debt->interest_rate === null) {
            return 0.0;
        }

        return $debt->interest_rate_period === 'monthly'
            ? (float) $debt->interest_rate / 100
            : (float) $debt->interest_rate / 100 / 12;
    }

    /**
     * @return array{amount: float, principal: float, interest: float}
     */
    protected function debtPaymentTotals(User $user, Carbon $month): array
    {
        $rows = DebtPayment::where('user_id', $user->id)
            ->whereBetween('paid_at', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->get(['amount', 'principal_amount', 'interest_amount']);

        return [
            'amount' => round((float) $rows->sum('amount'), 2),
            'principal' => round((float) $rows->sum('principal_amount'), 2),
            'interest' => round((float) $rows->sum('interest_amount'), 2),
        ];
    }

    protected function debtToIncomeRatio(User $user, Carbon $month, float $scheduledMonthly): ?float
    {
        $income = $this->monthlyTotals($user, $month)['income'];

        if ($income <= 0) {
            return null;
        }

        $paid = $this->debtPaymentTotals($user, $month)['amount'];
        $numerator = $paid > 0 ? $paid : $scheduledMonthly;

        return round($numerator / $income * 100, 1);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="debt summary|debt free date|not on track|debt to income"`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TrajectoryReportService.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add debt summary with avalanche what-if payoff simulation"
```

---

### Task 13: `TrajectoryReportService::savingsSummary()`

**Files:**
- Modify: `app/Services/TrajectoryReportService.php`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: `monthWindow()`, `monthlyTotals()` (Tasks 10–11), `accounts.is_emergency_fund` (Task 2), `AccountBalanceSnapshot` (Task 5).
- Produces:
  - `public function savingsSummary(User $user): array`
  - Shape: `['available' => bool, 'reason'? => string, 'emergencyFund' => array, 'goals' => list<array>, 'cashBuffer' => array]` — each of the three sections carries its **own** `available`/`reason` pair, because a user may have goals but no flagged emergency fund (or vice versa). The top-level `available` is `false` only when all three sections are unavailable.
  - `emergencyFund`: `['available' => bool, 'reason'? => string, 'balance' => float, 'avgMonthlyExpenses' => float, 'monthsCovered' => float|null, 'targetMonths' => int, 'progressPct' => float|null]`. Balance = `sum(accounts.balance)` where `is_emergency_fund = true`. `avgMonthlyExpenses` = mean expenses over the last 3 calendar months (current month included). `monthsCovered = balance / avgMonthlyExpenses`, `null` when average expenses are 0. `targetMonths` comes from `settings.emergency_fund_target_months`, defaulting to `6`. Unavailable when no account is flagged.
  - `goals`: `list<array{id: int, name: string, icon: string, color: string, bg: string, saved: float, target: float, progressPct: float, perMonth: float, byDate: string|null, requiredMonthly: float|null, monthsRemaining: int|null, onTrack: bool|null}>` for goals where `saved < target`. When `by_date` is set, `monthsRemaining = max(1, ceil(daysUntil / 30.4375))` and `requiredMonthly = (target − saved) / monthsRemaining`; otherwise both are `null` and `onTrack` is `null`. `onTrack = perMonth >= requiredMonthly`.
  - `cashBuffer`: `['available' => bool, 'reason'? => string, 'trend' => list<array{month: string, label: string, value: float}>]` — for each of the last 12 months, the **minimum** daily total across all `type = 'cash'` account snapshots in that month. Months without snapshots are omitted; unavailable when no month qualifies.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php` (add `use App\Models\Goal; use App\Models\Setting;` if missing):

```php
test('savings summary reports emergency fund coverage against the configured target', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create(['user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30, 'emergency_fund_target_months' => 6]);

    Account::factory()->emergencyFund()->for($user)->create(['balance' => 90000]);
    $spending = Account::factory()->for($user)->create(['type' => 'cash']);

    foreach (['2026-08-02', '2026-07-02', '2026-06-02'] as $date) {
        \App\Models\Transaction::factory()->for($user)->create([
            'account_id' => $spending->id, 'type' => 'expense', 'amount' => 30000, 'transacted_at' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->savingsSummary($user);

    expect($result['available'])->toBeTrue()
        ->and($result['emergencyFund']['available'])->toBeTrue()
        ->and($result['emergencyFund']['balance'])->toBe(90000.0)
        ->and($result['emergencyFund']['avgMonthlyExpenses'])->toBe(30000.0)
        ->and($result['emergencyFund']['monthsCovered'])->toBe(3.0)
        ->and($result['emergencyFund']['targetMonths'])->toBe(6)
        ->and($result['emergencyFund']['progressPct'])->toBe(50.0);

    Carbon::setTestNow();
});

test('savings summary marks the emergency fund unavailable when no account is flagged', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 50000]);

    $result = app(TrajectoryReportService::class)->savingsSummary($user);

    expect($result['emergencyFund']['available'])->toBeFalse()
        ->and($result['emergencyFund']['reason'])->toBeString();

    Carbon::setTestNow();
});

test('savings summary computes the monthly contribution each dated goal requires', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();

    Goal::factory()->for($user)->create([
        'name' => 'Japan trip', 'target' => 100000, 'saved' => 25000,
        'per_month' => 10000, 'by_date' => '2026-12-31',
    ]);
    Goal::factory()->for($user)->create([
        'name' => 'Someday', 'target' => 50000, 'saved' => 1000, 'per_month' => 2000, 'by_date' => null,
    ]);
    Goal::factory()->for($user)->create(['name' => 'Done', 'target' => 1000, 'saved' => 1000, 'per_month' => 0]);

    $goals = app(TrajectoryReportService::class)->savingsSummary($user)['goals'];

    expect($goals)->toHaveCount(2);

    $japan = collect($goals)->firstWhere('name', 'Japan trip');
    $someday = collect($goals)->firstWhere('name', 'Someday');

    // 2026-08-05 -> 2026-12-31 is 148 days; ceil(148 / 30.4375) = 5 months.
    expect($japan['monthsRemaining'])->toBe(5)
        ->and($japan['requiredMonthly'])->toBe(15000.0)
        ->and($japan['onTrack'])->toBeFalse()
        ->and($japan['progressPct'])->toBe(25.0)
        ->and($someday['requiredMonthly'])->toBeNull()
        ->and($someday['onTrack'])->toBeNull();

    Carbon::setTestNow();
});

test('savings summary reports the lowest cash balance reached each month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 6000]);
    $investment = Account::factory()->investment()->for($user)->create(['balance' => 500000]);

    foreach ([['2026-07-10', 5000], ['2026-07-20', 3000], ['2026-08-01', 8000], ['2026-08-05', 6000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $cash->id, 'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    AccountBalanceSnapshot::create([
        'user_id' => $user->id, 'account_id' => $investment->id, 'balance' => 500000, 'snapshot_date' => '2026-08-05',
    ]);

    $buffer = app(TrajectoryReportService::class)->savingsSummary($user)['cashBuffer'];

    expect($buffer['available'])->toBeTrue()
        ->and($buffer['trend'])->toBe([
            ['month' => '2026-07', 'label' => 'Jul 2026', 'value' => 3000.0],
            ['month' => '2026-08', 'label' => 'Aug 2026', 'value' => 6000.0],
        ]);

    Carbon::setTestNow();
});

test('savings summary is unavailable with no emergency fund, goals or snapshots', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();

    $result = app(TrajectoryReportService::class)->savingsSummary($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString()
        ->and($result['goals'])->toBe([]);

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="savings summary"`
Expected: FAIL with `Call to undefined method App\Services\TrajectoryReportService::savingsSummary()`.

- [ ] **Step 3: Write minimal implementation**

Add `use App\Models\Account;` and `use App\Models\Goal;` to the service imports, then add:

```php
    /**
     * @return array<string, mixed>
     */
    public function savingsSummary(User $user): array
    {
        $emergencyFund = $this->emergencyFundSection($user);
        $goals = $this->goalSection($user);
        $cashBuffer = $this->cashBufferSection($user);

        $available = $emergencyFund['available'] || $goals !== [] || $cashBuffer['available'];

        return [
            'available' => $available,
            ...($available ? [] : ['reason' => 'Flag an account as your emergency fund, add a savings goal, or let daily snapshots build up.']),
            'emergencyFund' => $emergencyFund,
            'goals' => $goals,
            'cashBuffer' => $cashBuffer,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function emergencyFundSection(User $user): array
    {
        $accounts = $user->accounts()->where('is_emergency_fund', true)->get();

        if ($accounts->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'No account is flagged as your emergency fund yet.',
                'balance' => 0.0,
                'avgMonthlyExpenses' => 0.0,
                'monthsCovered' => null,
                'targetMonths' => (int) ($user->setting?->emergency_fund_target_months ?? 6),
                'progressPct' => null,
            ];
        }

        $balance = round((float) $accounts->sum('balance'), 2);

        $expenses = array_map(
            fn (Carbon $month) => $this->monthlyTotals($user, $month)['expenses'],
            $this->monthWindow(3),
        );

        $average = round(array_sum($expenses) / max(1, count($expenses)), 2);
        $targetMonths = (int) ($user->setting?->emergency_fund_target_months ?? 6);
        $monthsCovered = $average > 0 ? round($balance / $average, 2) : null;

        return [
            'available' => true,
            'balance' => $balance,
            'avgMonthlyExpenses' => $average,
            'monthsCovered' => $monthsCovered,
            'targetMonths' => $targetMonths,
            'progressPct' => $monthsCovered !== null && $targetMonths > 0
                ? round(min(100, $monthsCovered / $targetMonths * 100), 1)
                : null,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function goalSection(User $user): array
    {
        $today = Carbon::now()->startOfDay();

        return $user->goals()
            ->whereColumn('saved', '<', 'target')
            ->orderBy('position')
            ->get()
            ->map(function (Goal $goal) use ($today) {
                $remaining = round((float) $goal->target - (float) $goal->saved, 2);
                $perMonth = round((float) $goal->per_month, 2);

                $monthsRemaining = null;
                $requiredMonthly = null;

                if ($goal->by_date !== null) {
                    $days = max(0.0, (float) $today->diffInDays(Carbon::parse($goal->by_date)->startOfDay(), false));
                    $monthsRemaining = max(1, (int) ceil($days / 30.4375));
                    $requiredMonthly = round($remaining / $monthsRemaining, 2);
                }

                return [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'icon' => $goal->icon,
                    'color' => $goal->color,
                    'bg' => $goal->bg,
                    'saved' => round((float) $goal->saved, 2),
                    'target' => round((float) $goal->target, 2),
                    'progressPct' => (float) $goal->target > 0
                        ? round((float) $goal->saved / (float) $goal->target * 100, 1)
                        : 0.0,
                    'perMonth' => $perMonth,
                    'byDate' => $goal->by_date?->toDateString(),
                    'monthsRemaining' => $monthsRemaining,
                    'requiredMonthly' => $requiredMonthly,
                    'onTrack' => $requiredMonthly !== null ? $perMonth >= $requiredMonthly : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * Lowest total cash balance reached in each of the last 12 months.
     *
     * @return array<string, mixed>
     */
    protected function cashBufferSection(User $user): array
    {
        $cashAccountIds = $user->accounts()->where('type', 'cash')->pluck('id');

        $windowStart = Carbon::now()->startOfMonth()->subMonths(11)->toDateString();

        $dailyTotals = AccountBalanceSnapshot::where('user_id', $user->id)
            ->whereIn('account_id', $cashAccountIds)
            ->where('snapshot_date', '>=', $windowStart)
            ->get(['balance', 'snapshot_date'])
            ->groupBy(fn (AccountBalanceSnapshot $snapshot) => $snapshot->snapshot_date->toDateString())
            ->map(fn ($group) => round((float) $group->sum('balance'), 2));

        $trend = [];

        foreach ($this->monthWindow(12) as $month) {
            $key = $month->format('Y-m');

            $inMonth = $dailyTotals->filter(fn ($value, $date) => str_starts_with($date, $key));

            if ($inMonth->isEmpty()) {
                continue;
            }

            $trend[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'value' => round((float) $inMonth->min(), 2),
            ];
        }

        if ($trend === []) {
            return [
                'available' => false,
                'reason' => 'No cash balance snapshots recorded yet.',
                'trend' => [],
            ];
        }

        return ['available' => true, 'trend' => $trend];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="savings summary"`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TrajectoryReportService.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add emergency fund, goal and cash buffer summaries"
```

---

### Task 14: `TrajectoryReportService::investmentsSummary()`

**Files:**
- Modify: `app/Services/TrajectoryReportService.php`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: `accounts.cost_basis` (Task 2), `settings.fi_number` / `fi_expected_return_rate` / `investment_monthly_contribution_target` (Task 1), `monthWindow()` (Task 10).
- Produces:
  - `public function investmentsSummary(User $user): array`
  - Shape: `['available' => bool, 'reason'? => string, 'totalValue' => float, 'totalCostBasis' => float, 'costBasisComplete' => bool, 'gainLoss' => float|null, 'gainLossPct' => float|null, 'contributionsThisMonth' => float, 'contributionTarget' => float|null, 'contributionProgressPct' => float|null, 'vehicles' => list<array{id: int, name: string, institution: string|null, value: float, costBasis: float|null, gainLoss: float|null, allocationPct: float}>, 'fire' => array]`
  - `costBasisComplete` is `false` when any investment account has a null `cost_basis`; `gainLoss`/`gainLossPct` are then `null` rather than a misleading partial figure.
  - "Contributions" = `transactions` of type `transfer` whose `to_account_id` is an investment account, summed over the calendar month.
  - `fire`: `['available' => bool, 'reason'? => string, 'fiNumber' => float|null, 'progressPct' => float|null, 'expectedReturnRate' => float, 'avgMonthlyContribution' => float, 'yearsToFi' => int|null]`. Unavailable when `settings.fi_number` is null.
  - **Years-to-FI (growing-annuity future value):** with `P = totalValue`, `r = fi_expected_return_rate / 100` (default `0.06`), `C = trailing-3-month average monthly contribution × 12`, loop `t = 1..100` and return the first `t` where `FV(t) >= fi_number`, where `FV(t) = P(1+r)^t + C((1+r)^t − 1)/r` (and `FV(t) = P + Ct` when `r == 0`). Returns `0` when already at or past the FI number, `null` when unreachable within 100 years.
  - Unavailable overall when the user has no `type = 'investment'` accounts.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('investments summary reports value, cost basis and allocation per vehicle', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'investment_monthly_contribution_target' => 15000,
    ]);

    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 100000]);
    $indexFund = Account::factory()->investment()->for($user)->create(['name' => 'Index Fund', 'balance' => 300000, 'cost_basis' => 250000]);
    Account::factory()->investment()->for($user)->create(['name' => 'Bonds', 'balance' => 200000, 'cost_basis' => 180000]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $cash->id, 'to_account_id' => $indexFund->id,
        'type' => 'transfer', 'amount' => 20000, 'transacted_at' => '2026-08-03',
    ]);

    $result = app(TrajectoryReportService::class)->investmentsSummary($user);

    expect($result['available'])->toBeTrue()
        ->and($result['totalValue'])->toBe(500000.0)
        ->and($result['totalCostBasis'])->toBe(430000.0)
        ->and($result['costBasisComplete'])->toBeTrue()
        ->and($result['gainLoss'])->toBe(70000.0)
        ->and($result['gainLossPct'])->toBe(16.3)
        ->and($result['contributionsThisMonth'])->toBe(20000.0)
        ->and($result['contributionTarget'])->toBe(15000.0)
        ->and($result['contributionProgressPct'])->toBe(133.3)
        ->and($result['vehicles'])->toHaveCount(2)
        ->and(collect($result['vehicles'])->firstWhere('name', 'Index Fund')['allocationPct'])->toBe(60.0)
        ->and(collect($result['vehicles'])->firstWhere('name', 'Bonds')['allocationPct'])->toBe(40.0);

    Carbon::setTestNow();
});

test('investments summary withholds gain when any cost basis is missing', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->investment()->for($user)->create(['balance' => 300000, 'cost_basis' => 250000]);
    Account::factory()->investment()->for($user)->create(['balance' => 200000, 'cost_basis' => null]);

    $result = app(TrajectoryReportService::class)->investmentsSummary($user);

    expect($result['costBasisComplete'])->toBeFalse()
        ->and($result['gainLoss'])->toBeNull()
        ->and($result['gainLossPct'])->toBeNull()
        ->and($result['totalValue'])->toBe(500000.0);

    Carbon::setTestNow();
});

test('fire progress projects years to financial independence', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'fi_number' => 1000000, 'fi_expected_return_rate' => 0,
    ]);

    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 100000]);
    $fund = Account::factory()->investment()->for($user)->create(['balance' => 500000, 'cost_basis' => 500000]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $cash->id, 'to_account_id' => $fund->id,
        'type' => 'transfer', 'amount' => 20000, 'transacted_at' => '2026-08-03',
    ]);

    $fire = app(TrajectoryReportService::class)->investmentsSummary($user)['fire'];

    // 20000 over the trailing 3 months = 6666.67/month = 80000.04/year.
    // 500000 + 80000.04t >= 1,000,000 -> t = 7.
    expect($fire['available'])->toBeTrue()
        ->and($fire['fiNumber'])->toBe(1000000.0)
        ->and($fire['progressPct'])->toBe(50.0)
        ->and($fire['avgMonthlyContribution'])->toBe(6666.67)
        ->and($fire['yearsToFi'])->toBe(7);

    Carbon::setTestNow();
});

test('fire progress is null years away when contributions never reach the target', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'fi_number' => 100000000, 'fi_expected_return_rate' => 0,
    ]);
    Account::factory()->investment()->for($user)->create(['balance' => 1000, 'cost_basis' => 1000]);

    $fire = app(TrajectoryReportService::class)->investmentsSummary($user)['fire'];

    expect($fire['available'])->toBeTrue()
        ->and($fire['yearsToFi'])->toBeNull();

    Carbon::setTestNow();
});

test('fire progress is unavailable without a configured fi number', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->investment()->for($user)->create(['balance' => 1000, 'cost_basis' => 1000]);

    $fire = app(TrajectoryReportService::class)->investmentsSummary($user)['fire'];

    expect($fire['available'])->toBeFalse()
        ->and($fire['reason'])->toBeString();

    Carbon::setTestNow();
});

test('investments summary is unavailable with no investment accounts', function () {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['type' => 'cash']);

    $result = app(TrajectoryReportService::class)->investmentsSummary($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="investments summary"`
Expected: FAIL with `Call to undefined method App\Services\TrajectoryReportService::investmentsSummary()`.

- [ ] **Step 3: Write minimal implementation**

Add to `app/Services/TrajectoryReportService.php`:

```php
    /**
     * @return array<string, mixed>
     */
    public function investmentsSummary(User $user): array
    {
        $accounts = $user->accounts()->where('type', 'investment')->orderBy('name')->get();

        if ($accounts->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'No investment accounts yet — add one to track growth toward financial independence.',
            ];
        }

        $totalValue = round((float) $accounts->sum('balance'), 2);
        $costBasisComplete = $accounts->every(fn (Account $account) => $account->cost_basis !== null);
        $totalCostBasis = round((float) $accounts->sum('cost_basis'), 2);

        $gainLoss = $costBasisComplete ? round($totalValue - $totalCostBasis, 2) : null;
        $gainLossPct = $costBasisComplete && $totalCostBasis > 0
            ? round(($totalValue - $totalCostBasis) / $totalCostBasis * 100, 1)
            : null;

        $vehicles = $accounts->map(function (Account $account) use ($totalValue) {
            $value = round((float) $account->balance, 2);
            $costBasis = $account->cost_basis !== null ? round((float) $account->cost_basis, 2) : null;

            return [
                'id' => $account->id,
                'name' => $account->name,
                'institution' => $account->institution,
                'value' => $value,
                'costBasis' => $costBasis,
                'gainLoss' => $costBasis !== null ? round($value - $costBasis, 2) : null,
                'allocationPct' => $totalValue > 0 ? round($value / $totalValue * 100, 1) : 0.0,
            ];
        })->values()->all();

        $accountIds = $accounts->pluck('id');
        $contributionsThisMonth = $this->investmentContributions($user, $accountIds, Carbon::now()->startOfMonth());

        $setting = $user->setting;
        $target = $setting?->investment_monthly_contribution_target !== null
            ? round((float) $setting->investment_monthly_contribution_target, 2)
            : null;

        $trailing = array_map(
            fn (Carbon $month) => $this->investmentContributions($user, $accountIds, $month),
            $this->monthWindow(3),
        );
        $avgMonthlyContribution = round(array_sum($trailing) / max(1, count($trailing)), 2);

        return [
            'available' => true,
            'totalValue' => $totalValue,
            'totalCostBasis' => $totalCostBasis,
            'costBasisComplete' => $costBasisComplete,
            'gainLoss' => $gainLoss,
            'gainLossPct' => $gainLossPct,
            'contributionsThisMonth' => $contributionsThisMonth,
            'contributionTarget' => $target,
            'contributionProgressPct' => $target !== null && $target > 0
                ? round($contributionsThisMonth / $target * 100, 1)
                : null,
            'vehicles' => $vehicles,
            'fire' => $this->fireSection($user, $totalValue, $avgMonthlyContribution),
        ];
    }

    /**
     * Transfers into the given investment accounts within one calendar month.
     *
     * @param  \Illuminate\Support\Collection<int, int>  $accountIds
     */
    protected function investmentContributions(User $user, Collection $accountIds, Carbon $month): float
    {
        return round((float) $user->transactions()
            ->where('type', 'transfer')
            ->whereIn('to_account_id', $accountIds)
            ->whereBetween('transacted_at', [
                $month->copy()->startOfMonth()->toDateString(),
                $month->copy()->endOfMonth()->toDateString(),
            ])
            ->sum('amount'), 2);
    }

    /**
     * @return array<string, mixed>
     */
    protected function fireSection(User $user, float $totalValue, float $avgMonthlyContribution): array
    {
        $setting = $user->setting;
        $rate = (float) ($setting?->fi_expected_return_rate ?? 6.0) / 100;

        if ($setting?->fi_number === null) {
            return [
                'available' => false,
                'reason' => 'Set your FI number in settings to track progress toward financial independence.',
                'fiNumber' => null,
                'progressPct' => null,
                'expectedReturnRate' => round($rate * 100, 2),
                'avgMonthlyContribution' => $avgMonthlyContribution,
                'yearsToFi' => null,
            ];
        }

        $fiNumber = round((float) $setting->fi_number, 2);

        return [
            'available' => true,
            'fiNumber' => $fiNumber,
            'progressPct' => $fiNumber > 0 ? round($totalValue / $fiNumber * 100, 1) : null,
            'expectedReturnRate' => round($rate * 100, 2),
            'avgMonthlyContribution' => $avgMonthlyContribution,
            'yearsToFi' => $this->yearsToFinancialIndependence($totalValue, $avgMonthlyContribution * 12, $rate, $fiNumber),
        ];
    }

    /**
     * First whole year t (1..100) where the growing-annuity future value reaches the FI number:
     * FV(t) = P(1+r)^t + C((1+r)^t - 1)/r, or P + Ct when r is zero.
     */
    protected function yearsToFinancialIndependence(float $present, float $annualContribution, float $rate, float $target): ?int
    {
        if ($present >= $target) {
            return 0;
        }

        for ($year = 1; $year <= 100; $year++) {
            $futureValue = $rate == 0.0
                ? $present + $annualContribution * $year
                : $present * (1 + $rate) ** $year + $annualContribution * (((1 + $rate) ** $year) - 1) / $rate;

            if ($futureValue >= $target) {
                return $year;
            }
        }

        return null;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="investments summary|fire progress"`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TrajectoryReportService.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add investments and FIRE progress summary"
```

---

### Task 15: `TrajectoryReportService::monthlyFlow()`

**Files:**
- Modify: `app/Services/TrajectoryReportService.php`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: `monthlyTotals()` (Task 11), `debtPaymentTotals()` (Task 12), `recurring_bills.last_paid_at` (already present via `database/migrations/2026_07_30_105328_add_last_paid_at_to_recurring_bills_table.php`, cast to `date` in `app/Models/RecurringBill.php:20`).
- Produces:
  - `public function monthlyFlow(User $user): array`
  - Shape: `['available' => bool, 'reason'? => string, 'monthLabel' => string, 'income' => float, 'expenses' => float, 'net' => float, 'budgetTotal' => float, 'budgetUsedPct' => float|null, 'overBudget' => array{top: list<array{id: int, name: string, icon: string, color: string, limit: float, actual: float, overAmount: float, overPct: float}>, remainingCount: int, remainingTotal: float}, 'fixedVsVariable' => array{fixed: float, variable: float, fixedPct: float|null}]`
  - **Documented simplification:** this method always uses the **current calendar month**, not the Reports page period navigator and not `Setting::cycleForDate()`. The trajectory report is a long-horizon view, so a stable calendar month keeps its month-over-month series comparable. State this in a PHPDoc on the method.
  - Over-budget: categories with `limit_amount > 0` whose month actual exceeds the limit, ranked by **percent** over (`overPct = (actual − limit) / limit × 100`), top 3 in `top`; the rest collapse into `remainingCount` and `remainingTotal` (sum of their `overAmount`). Categories with `limit_amount = 0` are excluded entirely.
  - Fixed = `recurring_bills.amount` for bills whose `last_paid_at` falls in the month, plus `debt_payments.amount` for the month. Variable = `max(0, expenses − fixed)`.
  - `budgetUsedPct` and `fixedPct` are `null` when their denominator is 0.
  - Unavailable when the month has no income, no expenses, and no budget categories.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php` (add `use App\Models\BudgetCategory; use App\Models\RecurringBill;`):

```php
test('monthly flow reports income, expenses and budget usage for the calendar month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();

    $categories = collect([
        ['name' => 'Food', 'limit_amount' => 5000, 'spend' => 7500],
        ['name' => 'Transport', 'limit_amount' => 2000, 'spend' => 4000],
        ['name' => 'Fun', 'limit_amount' => 1000, 'spend' => 3000],
        ['name' => 'Misc', 'limit_amount' => 500, 'spend' => 625],
        ['name' => 'Unbudgeted', 'limit_amount' => 0, 'spend' => 900],
    ])->map(function (array $row) use ($user, $account) {
        $category = BudgetCategory::factory()->for($user)->create([
            'name' => $row['name'], 'limit_amount' => $row['limit_amount'],
        ]);

        \App\Models\Transaction::factory()->for($user)->create([
            'account_id' => $account->id, 'budget_category_id' => $category->id,
            'type' => 'expense', 'amount' => $row['spend'], 'transacted_at' => '2026-08-03',
        ]);

        return $category;
    });

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'income', 'amount' => 50000, 'transacted_at' => '2026-08-01',
    ]);

    $result = app(TrajectoryReportService::class)->monthlyFlow($user);

    expect($result['available'])->toBeTrue()
        ->and($result['monthLabel'])->toBe('August 2026')
        ->and($result['income'])->toBe(50000.0)
        ->and($result['expenses'])->toBe(16025.0)
        ->and($result['net'])->toBe(33975.0)
        ->and($result['budgetTotal'])->toBe(8500.0)
        ->and($result['budgetUsedPct'])->toBe(188.5)
        ->and(array_column($result['overBudget']['top'], 'name'))->toBe(['Fun', 'Transport', 'Food'])
        ->and($result['overBudget']['top'][0]['overPct'])->toBe(200.0)
        ->and($result['overBudget']['top'][0]['overAmount'])->toBe(2000.0)
        ->and($result['overBudget']['remainingCount'])->toBe(1)
        ->and($result['overBudget']['remainingTotal'])->toBe(125.0)
        ->and($categories)->toHaveCount(5);

    Carbon::setTestNow();
});

test('monthly flow splits spending into fixed and variable', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 5000, 'interest_rate' => null]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'expense', 'amount' => 16025, 'transacted_at' => '2026-08-03',
    ]);

    RecurringBill::factory()->for($user)->create(['amount' => 2000, 'last_paid_at' => '2026-08-01']);
    RecurringBill::factory()->for($user)->create(['amount' => 999, 'last_paid_at' => '2026-07-01']);

    DebtPayment::factory()->create([
        'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id,
        'amount' => 5000, 'principal_amount' => 5000, 'interest_amount' => 0, 'paid_at' => '2026-08-02',
    ]);

    $split = app(TrajectoryReportService::class)->monthlyFlow($user)['fixedVsVariable'];

    expect($split['fixed'])->toBe(7000.0)
        ->and($split['variable'])->toBe(9025.0)
        ->and($split['fixedPct'])->toBe(43.7);

    Carbon::setTestNow();
});

test('monthly flow is unavailable with no activity and no budget', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();

    $result = app(TrajectoryReportService::class)->monthlyFlow($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="monthly flow"`
Expected: FAIL with `Call to undefined method App\Services\TrajectoryReportService::monthlyFlow()`.

- [ ] **Step 3: Write minimal implementation**

Add `use App\Models\BudgetCategory;` to the service imports, then add:

```php
    /**
     * Income, spending and budget pressure for the CURRENT CALENDAR MONTH.
     *
     * Deliberately not the Reports period navigator and not Setting::cycleForDate(): the
     * trajectory report is a long-horizon view, and a stable calendar month keeps every
     * month-over-month series on this page directly comparable.
     *
     * @return array<string, mixed>
     */
    public function monthlyFlow(User $user): array
    {
        $month = Carbon::now()->startOfMonth();
        $start = $month->copy()->startOfMonth()->toDateString();
        $end = $month->copy()->endOfMonth()->toDateString();

        $totals = $this->monthlyTotals($user, $month);
        $categories = $user->budgetCategories()->orderBy('name')->get();

        if ($totals['income'] <= 0 && $totals['expenses'] <= 0 && $categories->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'Nothing recorded for '.$month->format('F Y').' yet.',
            ];
        }

        $budgetTotal = round((float) $categories->sum('limit_amount'), 2);

        $actuals = $user->transactions()
            ->where('type', 'expense')
            ->whereNotNull('budget_category_id')
            ->whereBetween('transacted_at', [$start, $end])
            ->selectRaw('budget_category_id, SUM(amount) as total')
            ->groupBy('budget_category_id')
            ->pluck('total', 'budget_category_id');

        $over = $categories
            ->filter(fn (BudgetCategory $category) => (float) $category->limit_amount > 0)
            ->map(function (BudgetCategory $category) use ($actuals) {
                $limit = round((float) $category->limit_amount, 2);
                $actual = round((float) ($actuals[$category->id] ?? 0), 2);

                return [
                    'id' => $category->id,
                    'name' => $category->name,
                    'icon' => $category->icon,
                    'color' => $category->color,
                    'limit' => $limit,
                    'actual' => $actual,
                    'overAmount' => round($actual - $limit, 2),
                    'overPct' => round(($actual - $limit) / $limit * 100, 1),
                ];
            })
            ->filter(fn (array $row) => $row['overAmount'] > 0)
            ->sortByDesc('overPct')
            ->values();

        $top = $over->take(3)->all();
        $rest = $over->slice(3);

        $fixedBills = round((float) $user->recurringBills()
            ->whereBetween('last_paid_at', [$start, $end])
            ->sum('amount'), 2);

        $fixed = round($fixedBills + $this->debtPaymentTotals($user, $month)['amount'], 2);
        $variable = round(max(0, $totals['expenses'] - $fixed), 2);

        return [
            'available' => true,
            'monthLabel' => $month->format('F Y'),
            'income' => $totals['income'],
            'expenses' => $totals['expenses'],
            'net' => round($totals['income'] - $totals['expenses'], 2),
            'budgetTotal' => $budgetTotal,
            'budgetUsedPct' => $budgetTotal > 0 ? round($totals['expenses'] / $budgetTotal * 100, 1) : null,
            'overBudget' => [
                'top' => $top,
                'remainingCount' => $rest->count(),
                'remainingTotal' => round((float) $rest->sum('overAmount'), 2),
            ],
            'fixedVsVariable' => [
                'fixed' => $fixed,
                'variable' => $variable,
                'fixedPct' => $totals['expenses'] > 0 ? round($fixed / $totals['expenses'] * 100, 1) : null,
            ],
        ];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="monthly flow"`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TrajectoryReportService.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add monthly flow summary with over-budget and fixed vs variable split"
```

---

### Task 16: `TrajectoryReportService::signatureMetric()`

**Files:**
- Modify: `app/Services/TrajectoryReportService.php`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: `monthWindow()`, `lastBalancePerMonth()` (Task 10), `investmentContributions()` (Task 14), `debtPaymentTotals()` (Task 12).
- Produces:
  - `public function signatureMetric(User $user): array`
  - Shape: `['available' => bool, 'reason'? => string, 'currentValue' => float|null, 'trend' => list<array{month: string, label: string, value: float, investmentGain: float, debtInterest: float}>, 'crossedZeroMonth' => string|null, 'crossedZeroLabel' => string|null]`
  - Per month: `investmentGain = (lastInvestmentSnapshotTotal − firstInvestmentSnapshotTotal) − contributionsThatMonth`; `value = investmentGain − debtInterestThatMonth`. This is the headline "is my money working harder than my debt?" number.
  - `crossedZeroMonth` is the `YYYY-MM` of the first month whose `value > 0` and whose immediately preceding trend month had `value <= 0` — `null` when no such crossing exists (including when the very first month is already positive).
  - Months with no investment snapshot are omitted from the trend; unavailable when no month qualifies.
  - New private helper alongside `lastBalancePerMonth`: `private function firstBalancePerMonth(Collection $snapshots, string $ownerKey): array` — identical shape, but keeps each owner's *earliest* snapshot in the month.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('signature metric nets investment gain against debt interest each month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $cash = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 50000]);
    $fund = Account::factory()->investment()->for($user)->create(['balance' => 112000, 'cost_basis' => 100000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 5000, 'interest_rate' => null]);

    foreach ([['2026-07-01', 100000], ['2026-07-31', 110000], ['2026-08-01', 110000], ['2026-08-05', 112000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $fund->id, 'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $cash->id, 'to_account_id' => $fund->id,
        'type' => 'transfer', 'amount' => 4000, 'transacted_at' => '2026-07-15',
    ]);

    foreach ([['2026-07-05', 1000], ['2026-08-02', 3000]] as [$date, $interest]) {
        DebtPayment::factory()->create([
            'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $cash->id,
            'amount' => 5000 + $interest, 'principal_amount' => 5000, 'interest_amount' => $interest,
            'paid_at' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->signatureMetric($user);

    expect($result['available'])->toBeTrue()
        ->and($result['trend'])->toHaveCount(2)
        ->and($result['trend'][0])->toBe([
            'month' => '2026-07', 'label' => 'Jul 2026',
            'value' => 5000.0, 'investmentGain' => 6000.0, 'debtInterest' => 1000.0,
        ])
        ->and($result['trend'][1]['value'])->toBe(-1000.0)
        ->and($result['currentValue'])->toBe(-1000.0)
        ->and($result['crossedZeroMonth'])->toBeNull();

    Carbon::setTestNow();
});

test('signature metric reports the month it first crossed above zero', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $cash = Account::factory()->for($user)->create(['type' => 'cash']);
    $fund = Account::factory()->investment()->for($user)->create(['balance' => 110000, 'cost_basis' => 100000]);
    $debt = Debt::factory()->for($user)->create(['balance' => 50000, 'per_month' => 5000, 'interest_rate' => null]);

    foreach ([['2026-06-01', 100000], ['2026-06-30', 100500], ['2026-07-01', 100500], ['2026-07-31', 110000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $fund->id, 'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    foreach ([['2026-06-05', 2000], ['2026-07-05', 1000]] as [$date, $interest]) {
        DebtPayment::factory()->create([
            'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $cash->id,
            'amount' => 5000 + $interest, 'principal_amount' => 5000, 'interest_amount' => $interest,
            'paid_at' => $date,
        ]);
    }

    $result = app(TrajectoryReportService::class)->signatureMetric($user);

    expect($result['trend'][0]['value'])->toBe(-1500.0)
        ->and($result['trend'][1]['value'])->toBe(8500.0)
        ->and($result['crossedZeroMonth'])->toBe('2026-07')
        ->and($result['crossedZeroLabel'])->toBe('Jul 2026');

    Carbon::setTestNow();
});

test('signature metric is unavailable without investment snapshots', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    Account::factory()->investment()->for($user)->create(['balance' => 1000, 'cost_basis' => 1000]);

    $result = app(TrajectoryReportService::class)->signatureMetric($user);

    expect($result['available'])->toBeFalse()
        ->and($result['reason'])->toBeString();

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="signature metric"`
Expected: FAIL with `Call to undefined method App\Services\TrajectoryReportService::signatureMetric()`.

- [ ] **Step 3: Write minimal implementation**

Add to `app/Services/TrajectoryReportService.php`:

```php
    /**
     * The headline "is my money outrunning my debt?" figure: investment gain net of
     * contributions, minus the interest bled on debt, for each of the last 12 months.
     *
     * @return array<string, mixed>
     */
    public function signatureMetric(User $user): array
    {
        $investmentIds = $user->accounts()->where('type', 'investment')->pluck('id');

        if ($investmentIds->isEmpty()) {
            return [
                'available' => false,
                'reason' => 'No investment accounts yet — this metric compares investment growth against debt interest.',
            ];
        }

        $windowStart = Carbon::now()->startOfMonth()->subMonths(11)->toDateString();

        $snapshots = AccountBalanceSnapshot::where('user_id', $user->id)
            ->whereIn('account_id', $investmentIds)
            ->where('snapshot_date', '>=', $windowStart)
            ->orderBy('snapshot_date')
            ->get();

        $firstByMonth = $this->firstBalancePerMonth($snapshots, 'account_id');
        $lastByMonth = $this->lastBalancePerMonth($snapshots, 'account_id');

        $trend = [];

        foreach ($this->monthWindow(12) as $month) {
            $key = $month->format('Y-m');

            if (! array_key_exists($key, $firstByMonth)) {
                continue;
            }

            $gain = round(
                $lastByMonth[$key] - $firstByMonth[$key] - $this->investmentContributions($user, $investmentIds, $month),
                2,
            );
            $interest = $this->debtPaymentTotals($user, $month)['interest'];

            $trend[] = [
                'month' => $key,
                'label' => $month->format('M Y'),
                'value' => round($gain - $interest, 2),
                'investmentGain' => $gain,
                'debtInterest' => $interest,
            ];
        }

        if ($trend === []) {
            return [
                'available' => false,
                'reason' => 'No investment balance snapshots recorded yet.',
            ];
        }

        $crossedIndex = null;

        for ($index = 1; $index < count($trend); $index++) {
            if ($trend[$index]['value'] > 0 && $trend[$index - 1]['value'] <= 0) {
                $crossedIndex = $index;

                break;
            }
        }

        return [
            'available' => true,
            'currentValue' => $trend[count($trend) - 1]['value'],
            'trend' => $trend,
            'crossedZeroMonth' => $crossedIndex !== null ? $trend[$crossedIndex]['month'] : null,
            'crossedZeroLabel' => $crossedIndex !== null ? $trend[$crossedIndex]['label'] : null,
        ];
    }

    /**
     * Sum of each owner's earliest snapshot within each calendar month.
     *
     * @param  Collection<int, AccountBalanceSnapshot|DebtBalanceSnapshot>  $snapshots
     * @param  'account_id'|'debt_id'  $ownerKey
     * @return array<string, float>
     */
    protected function firstBalancePerMonth(Collection $snapshots, string $ownerKey): array
    {
        $earliest = [];

        foreach ($snapshots as $snapshot) {
            $month = $snapshot->snapshot_date->format('Y-m');
            $owner = $snapshot->{$ownerKey};
            $date = $snapshot->snapshot_date->toDateString();

            if (! isset($earliest[$month][$owner]) || $earliest[$month][$owner]['date'] > $date) {
                $earliest[$month][$owner] = ['date' => $date, 'balance' => (float) $snapshot->balance];
            }
        }

        $totals = [];

        foreach ($earliest as $month => $owners) {
            $totals[$month] = round(array_sum(array_column($owners, 'balance')), 2);
        }

        return $totals;
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="signature metric"`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/TrajectoryReportService.php tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add signature investment-gain-minus-debt-interest metric"
```

---

### Task 17: `FinanceTrajectoryController`, route and Wayfinder action

**Files:**
- Create: `app/Http/Controllers/FinanceTrajectoryController.php`
- Modify: `routes/web.php` (insert directly after the `/reports` route, around line 76, inside the same `auth`+`verified` group)
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: all seven public `TrajectoryReportService` methods (Tasks 10–16).
- Produces:
  - `public function index(Request $request): Response` — validates `extra_monthly` as `['nullable', 'numeric', 'min:0']`, then Inertia-renders the `Trajectory` component with props `netWorth`, `savingsRate`, `debt`, `savings`, `investments`, `monthlyFlow`, `signature`, and `extraMonthly` (the echoed float, `0.0` when absent).
  - Named route `trajectory` at `GET /trajectory`.
  - After this task, run `php artisan wayfinder:generate` so `resources/js/actions/App/Http/Controllers/FinanceTrajectoryController.ts` exists for `Trajectory.vue` to import in Task 18.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('guest is redirected from the trajectory page', function () {
    $this->get(route('trajectory'))->assertRedirect(route('login'));
});

test('trajectory page renders every report section', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 10000]);
    Debt::factory()->for($user)->create(['balance' => 5000, 'per_month' => 1000, 'interest_rate' => null]);

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->component('Trajectory')
            ->where('netWorth.current', 5000)
            ->where('debt.available', true)
            ->where('extraMonthly', 0)
            ->has('savingsRate')
            ->has('savings')
            ->has('investments')
            ->has('monthlyFlow')
            ->has('signature')
        );

    Carbon::setTestNow();
});

test('trajectory page applies an extra monthly payment to the debt projection', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Debt::factory()->for($user)->create(['balance' => 10000, 'per_month' => 1000, 'interest_rate' => null]);

    $this->actingAs($user)
        ->get(route('trajectory', ['extra_monthly' => 1000]))
        ->assertInertia(fn ($page) => $page
            ->where('extraMonthly', 1000)
            ->where('debt.debtFreeMonths', 5)
            ->where('debt.baselineDebtFreeMonths', 10)
            ->where('debt.monthsSaved', 5)
        );

    Carbon::setTestNow();
});

test('trajectory page rejects a negative extra monthly payment', function () {
    $user = User::factory()->create();
    $user->markEmailAsVerified();

    $this->actingAs($user)
        ->get(route('trajectory', ['extra_monthly' => -50]))
        ->assertSessionHasErrors('extra_monthly');
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="trajectory page"`
Expected: FAIL with `Route [trajectory] not defined.`

- [ ] **Step 3: Write minimal implementation**

Run `php artisan make:controller FinanceTrajectoryController --no-interaction`, then write `app/Http/Controllers/FinanceTrajectoryController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Services\TrajectoryReportService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class FinanceTrajectoryController extends Controller
{
    public function __construct(private TrajectoryReportService $trajectory) {}

    public function index(Request $request): Response
    {
        $validated = $request->validate([
            'extra_monthly' => ['nullable', 'numeric', 'min:0'],
        ]);

        $user = $request->user();
        $extraMonthly = isset($validated['extra_monthly']) ? (float) $validated['extra_monthly'] : 0.0;

        return Inertia::render('Trajectory', [
            'extraMonthly' => $extraMonthly,
            'netWorth' => $this->trajectory->netWorth($user),
            'savingsRate' => $this->trajectory->savingsRate($user),
            'debt' => $this->trajectory->debtSummary($user, $extraMonthly),
            'savings' => $this->trajectory->savingsSummary($user),
            'investments' => $this->trajectory->investmentsSummary($user),
            'monthlyFlow' => $this->trajectory->monthlyFlow($user),
            'signature' => $this->trajectory->signatureMetric($user),
        ]);
    }
}
```

In `routes/web.php`, add `use App\Http\Controllers\FinanceTrajectoryController;` to the imports and insert immediately after the `/reports` line:

```php
    Route::get('/trajectory', [FinanceTrajectoryController::class, 'index'])->name('trajectory');
```

Then generate the frontend action:

```bash
php artisan wayfinder:generate
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="trajectory page|redirected from the trajectory"`
Expected: FAIL on the first two tests with `Inertia page component [Trajectory] not found` **only if** SSR/page-existence checking is enabled; otherwise PASS. If it fails for that reason, create a placeholder `resources/js/pages/Trajectory.vue` containing `<template><div /></template>` and re-run — Task 18 replaces it with the real page.
Expected final state: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/FinanceTrajectoryController.php routes/web.php resources/js/actions resources/js/routes tests/Feature/FinanceTrajectoryReportTest.php resources/js/pages/Trajectory.vue
git commit -m "feat: add trajectory route and controller"
```

---

### Task 18: `Trajectory.vue` page shell + net worth section end-to-end

**Files:**
- Create: `resources/js/components/trajectory/format.ts`
- Create: `resources/js/components/trajectory/UnavailableNote.vue`
- Create: `resources/js/components/trajectory/NetWorthCard.vue`
- Create (or replace the Task 17 placeholder): `resources/js/pages/Trajectory.vue`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: the Inertia props from Task 17, the generated action `@/actions/App/Http/Controllers/FinanceTrajectoryController`, and `@/components/reports/ReportLineChart.vue` (props `labels: string[]`, `datasets: Dataset[]`).
- Produces:
  - `format.ts` exports `formatMoney(value: number): string`, `formatPct(value: number | null): string`, `formatSigned(value: number): string`.
  - `UnavailableNote.vue` — props `{ reason?: string }`, renders the muted empty-state line.
  - `NetWorthCard.vue` — props `{ netWorth: NetWorth }`.
  - `Trajectory.vue` exports the TypeScript prop types every section component reuses (`TrendPoint`, `NetWorth`) and declares no layout (resolved globally in `resources/js/app.ts:12`).
- Styling: inline `style=` for visual detail, Tailwind only for the shared wrapper and grid utilities — matching `resources/js/pages/Reports.vue`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('trajectory page passes a renderable net worth trend to the front end', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $account = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 10000]);

    foreach ([['2026-07-31', 8000], ['2026-08-05', 10000]] as [$date, $balance]) {
        AccountBalanceSnapshot::create([
            'user_id' => $user->id, 'account_id' => $account->id,
            'balance' => $balance, 'snapshot_date' => $date,
        ]);
    }

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->component('Trajectory')
            ->where('netWorth.available', true)
            ->has('netWorth.trend', 2)
            ->where('netWorth.trend.0.label', 'Jul 2026')
            ->where('netWorth.changePct', 25.0)
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="renderable net worth trend"`
Expected: FAIL — `netWorth.changePct` is `25.0` only if the trend is computed; if the placeholder page from Task 17 is still in place this passes on the backend, so also run `npm run build` and expect it to fail with `Failed to resolve import "@/components/trajectory/NetWorthCard.vue"` once the real `Trajectory.vue` below is written. Write the page first, confirm the build error, then add the components.

- [ ] **Step 3: Write minimal implementation**

`resources/js/components/trajectory/format.ts`:

```ts
export function formatMoney(value: number): string {
    const abs = Math.abs(value);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return value < 0 ? `-${formatted}` : formatted;
}

export function formatSigned(value: number): string {
    return (value >= 0 ? '+' : '') + formatMoney(value);
}

export function formatPct(value: number | null): string {
    return value === null ? '—' : `${value.toFixed(1)}%`;
}
```

`resources/js/components/trajectory/UnavailableNote.vue`:

```vue
<script setup lang="ts">
defineProps<{ reason?: string }>();
</script>

<template>
    <div style="display: flex; align-items: center; gap: 8px; color: #8a8172; font-size: 13.5px; padding: 10px 0">
        <span class="material-symbols-rounded" style="font-size: 18px">info</span>
        <span>{{ reason ?? 'Not enough data yet.' }}</span>
    </div>
</template>
```

`resources/js/components/trajectory/NetWorthCard.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import { formatMoney, formatSigned } from '@/components/trajectory/format';
import type { NetWorth } from '@/pages/Trajectory.vue';

const props = defineProps<{ netWorth: NetWorth }>();

const labels = computed(() => props.netWorth.trend.map(point => point.label));

const datasets = computed(() => [
    {
        label: 'Net worth',
        data: props.netWorth.trend.map(point => point.value),
        borderColor: '#2f7d5b',
        backgroundColor: 'rgba(47, 125, 91, 0.12)',
        fill: true,
    },
]);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="display: flex; align-items: flex-start; gap: 16px; flex-wrap: wrap">
            <div>
                <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Net worth</div>
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 30px; letter-spacing: -.02em; margin-top: 4px"
                     :style="{ color: netWorth.current < 0 ? '#c0512c' : '#211d17' }">
                    {{ formatMoney(netWorth.current) }}
                </div>
            </div>

            <div style="margin-left: auto; text-align: right">
                <div style="font-size: 12px; color: #8a8172">Assets {{ formatMoney(netWorth.accountTotal) }}</div>
                <div style="font-size: 12px; color: #8a8172; margin-top: 2px">Debts {{ formatMoney(netWorth.debtTotal) }}</div>
                <div v-if="netWorth.available && netWorth.changeAmount !== undefined"
                     style="font-size: 13.5px; font-weight: 600; margin-top: 6px"
                     :style="{ color: netWorth.changeAmount >= 0 ? '#2f7d5b' : '#c0512c' }">
                    {{ formatSigned(netWorth.changeAmount) }}
                    <span v-if="netWorth.changePct !== null && netWorth.changePct !== undefined" style="color: #8a8172; font-weight: 500">
                        ({{ netWorth.changePct.toFixed(1) }}%)
                    </span>
                </div>
            </div>
        </div>

        <div v-if="netWorth.available" style="margin-top: 14px">
            <ReportLineChart :labels="labels" :datasets="datasets" />
        </div>
        <UnavailableNote v-else :reason="netWorth.reason" />
    </section>
</template>
```

`resources/js/pages/Trajectory.vue`:

```vue
<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import NetWorthCard from '@/components/trajectory/NetWorthCard.vue';

export type TrendPoint = { month: string; label: string; value: number };

export type NetWorth = {
    available: boolean;
    reason?: string;
    current: number;
    accountTotal: number;
    debtTotal: number;
    trend: TrendPoint[];
    changeAmount?: number;
    changePct?: number | null;
};

defineProps<{
    netWorth: NetWorth;
    extraMonthly: number;
}>();
</script>

<template>
    <Head title="Trajectory" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">
        <div>
            <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Trajectory</h1>
            <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Are you actually getting ahead?</p>
        </div>

        <NetWorthCard :net-worth="netWorth" />
    </div>
</template>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="renderable net worth trend"` — Expected: PASS.
Run: `npm run build` — Expected: build succeeds with no unresolved imports and no TypeScript errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Trajectory.vue resources/js/components/trajectory tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add trajectory page shell with net worth card"
```

---

### Task 19: Debt section components

**Files:**
- Create: `resources/js/components/trajectory/DebtFreeCard.vue`
- Create: `resources/js/components/trajectory/DebtTable.vue`
- Create: `resources/js/components/trajectory/InterestBleedingCard.vue`
- Create: `resources/js/components/trajectory/PrincipalInterestTrend.vue`
- Create: `resources/js/components/trajectory/DtiCard.vue`
- Modify: `resources/js/pages/Trajectory.vue` (add the `DebtSummary` types and render the section)
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: the `debt` prop from Task 17 (`debtSummary()` shape from Task 12), `format.ts` and `UnavailableNote.vue` (Task 18), `@/components/reports/ReportBarChart.vue` (props `labels: string[]`, `datasets: { label?, data: number[], backgroundColor, borderRadius? }[]`, `showLegend?: boolean`) and `@/actions/App/Http/Controllers/FinanceTrajectoryController`.
- Produces: `DebtFreeCard.vue` owns the what-if input and issues the only navigation on this page — `router.get(index({ query: { extra_monthly } }).url, {}, { preserveScroll: true, preserveState: true, replace: true })`, mirroring `Reports.vue`'s navigation helpers. All other debt components are pure presentation.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('trajectory page exposes every field the debt section renders', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $account = Account::factory()->for($user)->create();
    $debt = Debt::factory()->for($user)->create(['name' => 'Card', 'balance' => 10000, 'per_month' => 1000, 'interest_rate' => 18]);

    DebtPayment::factory()->create([
        'user_id' => $user->id, 'debt_id' => $debt->id, 'account_id' => $account->id,
        'amount' => 1000, 'principal_amount' => 850, 'interest_amount' => 150, 'paid_at' => '2026-08-02',
    ]);

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->where('debt.available', true)
            ->where('debt.debts.0.name', 'Card')
            ->where('debt.debts.0.interestRate', 18)
            ->where('debt.interestThisMonth', 150)
            ->where('debt.interestYearToDate', 150)
            ->has('debt.principalInterestTrend', 12)
            ->has('debt.debtToIncome.trend', 6)
            ->has('debt.debtFreeDate')
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="every field the debt section renders"`
Expected: PASS on the backend (the service already produces these), then run `npm run build` and expect FAIL with `Failed to resolve import "@/components/trajectory/DebtFreeCard.vue"` after the `Trajectory.vue` edit below. Apply the `Trajectory.vue` edit first, confirm the build failure, then add the components.

- [ ] **Step 3: Write minimal implementation**

`resources/js/components/trajectory/DebtFreeCard.vue`:

```vue
<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import { index } from '@/actions/App/Http/Controllers/FinanceTrajectoryController';
import { formatMoney } from '@/components/trajectory/format';
import type { DebtSummary } from '@/pages/Trajectory.vue';

const props = defineProps<{ debt: DebtSummary }>();

const extra = ref<string>(props.debt.extraMonthly ? String(props.debt.extraMonthly) : '');

watch(() => props.debt.extraMonthly, value => {
    extra.value = value ? String(value) : '';
});

function applyExtra() {
    const parsed = Number(extra.value);
    const value = Number.isFinite(parsed) && parsed > 0 ? parsed : 0;

    router.get(index({ query: { extra_monthly: value } }).url, {}, {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Debt free</div>

        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; margin-top: 4px"
             :style="{ color: debt.onTrack ? '#211d17' : '#c0512c' }">
            {{ debt.debtFreeDate ?? 'Not on this plan' }}
        </div>

        <div v-if="!debt.onTrack" style="font-size: 13px; color: #c0512c; margin-top: 4px">
            At least one debt's payment doesn't cover its own interest.
        </div>

        <div v-else-if="debt.monthsSaved" style="font-size: 13px; color: #2f7d5b; margin-top: 4px">
            {{ debt.monthsSaved }} month(s) sooner than the baseline plan.
        </div>

        <div style="display: flex; align-items: center; gap: 8px; margin-top: 14px; flex-wrap: wrap">
            <label for="extra-monthly" style="font-size: 13px; color: #6b6355">What if I paid extra each month?</label>
            <input
                id="extra-monthly"
                v-model="extra"
                type="number"
                min="0"
                step="100"
                inputmode="decimal"
                @keyup.enter="applyExtra"
                style="width: 130px; height: 36px; padding: 0 10px; border: 1px solid #e0dacd; border-radius: 10px; background: #fdfbf5; font-size: 14px; color: #211d17"
            />
            <button
                type="button"
                @click="applyExtra"
                style="height: 36px; padding: 0 16px; border: none; border-radius: 10px; background: #211d17; color: #fdfbf5; font-size: 13.5px; font-weight: 600; cursor: pointer"
            >Apply</button>
        </div>

        <div style="font-size: 12px; color: #8a8172; margin-top: 10px">
            Remaining {{ formatMoney(debt.totalRemaining) }} · Scheduled {{ formatMoney(debt.totalMonthly) }}/mo
        </div>
    </section>
</template>
```

`resources/js/components/trajectory/DebtTable.vue`:

```vue
<script setup lang="ts">
import { formatMoney } from '@/components/trajectory/format';
import type { DebtRow } from '@/pages/Trajectory.vue';

defineProps<{ debts: DebtRow[] }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Highest interest first</div>

        <div style="margin-top: 12px; overflow-x: auto">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 520px">
                <thead>
                    <tr style="color: #8a8172; text-align: left">
                        <th style="padding: 6px 8px; font-weight: 500">Debt</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Rate</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Balance</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Monthly</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Paid off</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="row in debts" :key="row.id" style="border-top: 1px solid #e0dacd">
                        <td style="padding: 9px 8px">
                            <span style="display: inline-flex; align-items: center; gap: 8px">
                                <span class="material-symbols-rounded"
                                      :style="{ fontSize: '18px', color: row.color, background: row.bg, borderRadius: '8px', padding: '4px' }">{{ row.icon }}</span>
                                <span style="color: #211d17; font-weight: 600">{{ row.name }}</span>
                            </span>
                        </td>
                        <td style="padding: 9px 8px; text-align: right; color: #6b6355">
                            {{ row.interestRate === null ? '—' : `${row.interestRate}% ${row.interestRatePeriod}` }}
                        </td>
                        <td style="padding: 9px 8px; text-align: right; color: #211d17">{{ formatMoney(row.balance) }}</td>
                        <td style="padding: 9px 8px; text-align: right; color: #6b6355">{{ formatMoney(row.perMonth) }}</td>
                        <td style="padding: 9px 8px; text-align: right"
                            :style="{ color: row.payoffDate ? '#2f7d5b' : '#c0512c' }">
                            {{ row.payoffDate ?? 'Never at this rate' }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
```

`resources/js/components/trajectory/InterestBleedingCard.vue`:

```vue
<script setup lang="ts">
import { formatMoney } from '@/components/trajectory/format';

defineProps<{ interestThisMonth: number; interestYearToDate: number }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Interest bleeding out</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; color: #c0512c; margin-top: 4px">
            {{ formatMoney(interestThisMonth) }}
        </div>
        <div style="font-size: 13px; color: #6b6355; margin-top: 4px">this month</div>
        <div style="font-size: 13px; color: #8a8172; margin-top: 10px; padding-top: 10px; border-top: 1px solid #e0dacd">
            {{ formatMoney(interestYearToDate) }} so far this year
        </div>
    </section>
</template>
```

`resources/js/components/trajectory/PrincipalInterestTrend.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import ReportBarChart from '@/components/reports/ReportBarChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { SplitPoint } from '@/pages/Trajectory.vue';

const props = defineProps<{ trend: SplitPoint[] }>();

const hasData = computed(() => props.trend.some(point => point.principal > 0 || point.interest > 0));
const labels = computed(() => props.trend.map(point => point.label));

const datasets = computed(() => [
    { label: 'Principal', data: props.trend.map(p => p.principal), backgroundColor: '#2f7d5b', borderRadius: 4 },
    { label: 'Interest', data: props.trend.map(p => p.interest), backgroundColor: '#c0512c', borderRadius: 4 },
]);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Principal vs interest</div>
        <div v-if="hasData" style="margin-top: 12px">
            <ReportBarChart :labels="labels" :datasets="datasets" :show-legend="true" />
        </div>
        <UnavailableNote v-else reason="No debt payments recorded yet — make a payment to start the split." />
    </section>
</template>
```

`resources/js/components/trajectory/DtiCard.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney, formatPct } from '@/components/trajectory/format';
import type { DebtToIncome } from '@/pages/Trajectory.vue';

const props = defineProps<{ dti: DebtToIncome }>();

const tone = computed(() => {
    if (props.dti.ratio === null) return '#8a8172';
    if (props.dti.ratio <= 20) return '#2f7d5b';
    if (props.dti.ratio <= 36) return '#d98a2b';
    return '#c0512c';
});
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Debt to income</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; margin-top: 4px" :style="{ color: tone }">
            {{ formatPct(dti.ratio) }}
        </div>
        <div style="font-size: 13px; color: #6b6355; margin-top: 4px">
            {{ formatMoney(dti.monthlyDebtPayments) }} of {{ formatMoney(dti.income) }}
            <span v-if="dti.isEstimated" style="color: #8a8172">(scheduled, no payments logged yet)</span>
        </div>

        <div style="display: flex; align-items: flex-end; gap: 6px; height: 46px; margin-top: 14px">
            <div v-for="point in dti.trend" :key="point.month" style="flex: 1; display: flex; flex-direction: column; align-items: center; gap: 4px">
                <div :style="{
                    width: '100%',
                    height: `${Math.min(100, point.ratio ?? 0)}%`,
                    minHeight: '2px',
                    background: point.ratio === null ? '#e0dacd' : tone,
                    borderRadius: '4px 4px 0 0',
                }" />
            </div>
        </div>
        <div style="display: flex; gap: 6px; margin-top: 4px">
            <div v-for="point in dti.trend" :key="`${point.month}-label`" style="flex: 1; text-align: center; font-size: 10.5px; color: #8a8172">
                {{ point.label.slice(0, 3) }}
            </div>
        </div>
    </section>
</template>
```

In `resources/js/pages/Trajectory.vue`, add the types and imports and render the debt section:

```ts
export type DebtRow = {
    id: number; name: string; icon: string; color: string; bg: string;
    balance: number; perMonth: number;
    interestRate: number | null; interestRatePeriod: string;
    months: number | null; payoffDate: string | null;
};

export type SplitPoint = { month: string; label: string; principal: number; interest: number };

export type DebtToIncome = {
    ratio: number | null; monthlyDebtPayments: number; income: number; isEstimated: boolean;
    trend: { month: string; label: string; ratio: number | null }[];
};

export type DebtSummary = {
    available: boolean; reason?: string;
    totalRemaining: number; totalMonthly: number; extraMonthly: number;
    debts: DebtRow[];
    debtFreeMonths: number | null; debtFreeDate: string | null; onTrack: boolean;
    baselineDebtFreeMonths: number | null; monthsSaved: number | null;
    interestThisMonth: number; interestYearToDate: number;
    principalInterestTrend: SplitPoint[];
    debtToIncome: DebtToIncome;
};
```

Add `debt: DebtSummary;` to `defineProps`, import the five components, and append to the template after `<NetWorthCard>`:

```vue
        <template v-if="debt.available">
            <div class="grid gap-3 lg:grid-cols-3">
                <DebtFreeCard :debt="debt" />
                <InterestBleedingCard :interest-this-month="debt.interestThisMonth" :interest-year-to-date="debt.interestYearToDate" />
                <DtiCard :dti="debt.debtToIncome" />
            </div>
            <DebtTable :debts="debt.debts" />
            <PrincipalInterestTrend :trend="debt.principalInterestTrend" />
        </template>
        <UnavailableNote v-else :reason="debt.reason" />
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="every field the debt section renders"` — Expected: PASS.
Run: `npm run build` — Expected: build succeeds with no unresolved imports and no TypeScript errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Trajectory.vue resources/js/components/trajectory tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add debt trajectory section with what-if payoff input"
```

---

### Task 20: Savings and investments section components

**Files:**
- Create: `resources/js/components/reports/ReportDonutChart.vue`
- Create: `resources/js/components/trajectory/EmergencyFundProgress.vue`
- Create: `resources/js/components/trajectory/SavingsGoalsList.vue`
- Create: `resources/js/components/trajectory/CashBufferTrend.vue`
- Create: `resources/js/components/trajectory/InvestmentContributions.vue`
- Create: `resources/js/components/trajectory/InvestmentVehicleTable.vue`
- Create: `resources/js/components/trajectory/AllocationChart.vue`
- Create: `resources/js/components/trajectory/FireProgress.vue`
- Modify: `resources/js/pages/Trajectory.vue`
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: the `savings` and `investments` props (Tasks 13–14, 17), `format.ts` + `UnavailableNote.vue` (Task 18), `ReportLineChart.vue`.
- Produces: `ReportDonutChart.vue` — a doughnut wrapper mirroring `resources/js/components/reports/ReportLineChart.vue` exactly (module-scope `ChartJS.register`, fixed-height wrapper div), registering `ArcElement` + `DoughnutController` instead of the line/point elements. Props: `labels: string[]`, `data: number[]`, `colors: string[]`.

> `ReportDonutChart.vue` lives in `components/reports/`, not `components/trajectory/`, so it sits beside its sibling chart wrappers and is reusable by the Reports page.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('trajectory page exposes every field the savings and investment sections render', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    Setting::create([
        'user_id' => $user->id, 'theme' => 'ocean', 'budget_cycle_days' => 30,
        'fi_number' => 25000000, 'fi_expected_return_rate' => 6,
        'emergency_fund_target_months' => 6, 'investment_monthly_contribution_target' => 15000,
    ]);

    Account::factory()->emergencyFund()->for($user)->create(['balance' => 90000]);
    Account::factory()->investment()->for($user)->create(['name' => 'Index Fund', 'balance' => 300000, 'cost_basis' => 250000]);
    Goal::factory()->for($user)->create(['name' => 'Japan trip', 'target' => 100000, 'saved' => 25000, 'per_month' => 10000, 'by_date' => '2026-12-31']);

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->where('savings.emergencyFund.available', true)
            ->where('savings.emergencyFund.targetMonths', 6)
            ->where('savings.goals.0.name', 'Japan trip')
            ->where('savings.goals.0.requiredMonthly', 15000)
            ->where('savings.cashBuffer.available', false)
            ->where('investments.available', true)
            ->where('investments.vehicles.0.name', 'Index Fund')
            ->where('investments.vehicles.0.allocationPct', 100)
            ->where('investments.contributionTarget', 15000)
            ->where('investments.fire.available', true)
            ->where('investments.fire.fiNumber', 25000000)
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="savings and investment sections render"`
Expected: PASS on the backend, then `npm run build` FAILS with `Failed to resolve import "@/components/trajectory/EmergencyFundProgress.vue"` once the `Trajectory.vue` edit is applied. Apply the page edit first, confirm the build failure, then add the components.

- [ ] **Step 3: Write minimal implementation**

`resources/js/components/reports/ReportDonutChart.vue`:

```vue
<script setup lang="ts">
import { Doughnut } from 'vue-chartjs';
import { ArcElement, Chart as ChartJS, DoughnutController, Legend, Title, Tooltip } from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, ArcElement, DoughnutController);

defineProps<{
    labels: string[];
    data: number[];
    colors: string[];
}>();

const options = {
    responsive: true,
    maintainAspectRatio: false,
    cutout: '62%',
    plugins: { legend: { display: true, position: 'right' as const } },
};
</script>

<template>
    <div style="height: 240px">
        <Doughnut :data="{ labels, datasets: [{ data, backgroundColor: colors, borderWidth: 0 }] }" :options="options" />
    </div>
</template>
```

`resources/js/components/trajectory/EmergencyFundProgress.vue`:

```vue
<script setup lang="ts">
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import { formatMoney } from '@/components/trajectory/format';
import type { EmergencyFund } from '@/pages/Trajectory.vue';

defineProps<{ emergencyFund: EmergencyFund }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Emergency fund</div>

        <template v-if="emergencyFund.available">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; color: #211d17; margin-top: 4px">
                {{ emergencyFund.monthsCovered === null ? '—' : `${emergencyFund.monthsCovered.toFixed(1)} months` }}
                <span style="font-size: 15px; font-weight: 500; color: #8a8172">of {{ emergencyFund.targetMonths }}</span>
            </div>

            <div style="height: 10px; border-radius: 999px; background: #f0ebe0; overflow: hidden; margin-top: 12px">
                <div :style="{
                    width: `${emergencyFund.progressPct ?? 0}%`,
                    height: '100%',
                    background: (emergencyFund.progressPct ?? 0) >= 100 ? '#2f7d5b' : '#d98a2b',
                }" />
            </div>

            <div style="font-size: 13px; color: #6b6355; margin-top: 10px">
                {{ formatMoney(emergencyFund.balance) }} saved · {{ formatMoney(emergencyFund.avgMonthlyExpenses) }}/mo average spend
            </div>
        </template>
        <UnavailableNote v-else :reason="emergencyFund.reason" />
    </section>
</template>
```

`resources/js/components/trajectory/SavingsGoalsList.vue`:

```vue
<script setup lang="ts">
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import { formatMoney } from '@/components/trajectory/format';
import type { GoalRow } from '@/pages/Trajectory.vue';

defineProps<{ goals: GoalRow[] }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Savings goals</div>

        <UnavailableNote v-if="goals.length === 0" reason="No open savings goals right now." />

        <div v-else style="display: flex; flex-direction: column; gap: 14px; margin-top: 12px">
            <div v-for="goal in goals" :key="goal.id">
                <div style="display: flex; align-items: center; gap: 8px">
                    <span class="material-symbols-rounded"
                          :style="{ fontSize: '18px', color: goal.color, background: goal.bg, borderRadius: '8px', padding: '4px' }">{{ goal.icon }}</span>
                    <span style="font-weight: 600; color: #211d17; font-size: 14px">{{ goal.name }}</span>
                    <span style="margin-left: auto; font-size: 13px; color: #6b6355">
                        {{ formatMoney(goal.saved) }} / {{ formatMoney(goal.target) }}
                    </span>
                </div>

                <div style="height: 8px; border-radius: 999px; background: #f0ebe0; overflow: hidden; margin-top: 8px">
                    <div :style="{ width: `${Math.min(100, goal.progressPct)}%`, height: '100%', background: goal.color }" />
                </div>

                <div style="font-size: 12.5px; margin-top: 6px" :style="{ color: goal.onTrack === false ? '#c0512c' : '#8a8172' }">
                    <template v-if="goal.requiredMonthly !== null">
                        Needs {{ formatMoney(goal.requiredMonthly) }}/mo for {{ goal.monthsRemaining }} month(s) ·
                        putting in {{ formatMoney(goal.perMonth) }}/mo
                    </template>
                    <template v-else>
                        No target date · putting in {{ formatMoney(goal.perMonth) }}/mo
                    </template>
                </div>
            </div>
        </div>
    </section>
</template>
```

`resources/js/components/trajectory/CashBufferTrend.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { CashBuffer } from '@/pages/Trajectory.vue';

const props = defineProps<{ cashBuffer: CashBuffer }>();

const labels = computed(() => props.cashBuffer.trend.map(point => point.label));

const datasets = computed(() => [
    {
        label: 'Lowest cash balance',
        data: props.cashBuffer.trend.map(point => point.value),
        borderColor: '#3f7fb0',
        backgroundColor: 'rgba(63, 127, 176, 0.12)',
        fill: true,
    },
]);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Cash buffer low point</div>
        <div v-if="cashBuffer.available" style="margin-top: 12px">
            <ReportLineChart :labels="labels" :datasets="datasets" />
        </div>
        <UnavailableNote v-else :reason="cashBuffer.reason" />
    </section>
</template>
```

`resources/js/components/trajectory/InvestmentContributions.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import { formatMoney } from '@/components/trajectory/format';

const props = defineProps<{
    contributionsThisMonth: number;
    contributionTarget: number | null;
    contributionProgressPct: number | null;
}>();

const met = computed(() => (props.contributionProgressPct ?? 0) >= 100);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Invested this month</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; color: #211d17; margin-top: 4px">
            {{ formatMoney(contributionsThisMonth) }}
        </div>

        <template v-if="contributionTarget !== null">
            <div style="height: 10px; border-radius: 999px; background: #f0ebe0; overflow: hidden; margin-top: 12px">
                <div :style="{
                    width: `${Math.min(100, contributionProgressPct ?? 0)}%`,
                    height: '100%',
                    background: met ? '#2f7d5b' : '#d98a2b',
                }" />
            </div>
            <div style="font-size: 13px; color: #6b6355; margin-top: 8px">
                Target {{ formatMoney(contributionTarget) }}/mo
            </div>
        </template>
        <div v-else style="font-size: 13px; color: #8a8172; margin-top: 8px">
            Set a monthly investing target in settings to track this.
        </div>
    </section>
</template>
```

`resources/js/components/trajectory/InvestmentVehicleTable.vue`:

```vue
<script setup lang="ts">
import { formatMoney, formatSigned } from '@/components/trajectory/format';
import type { Vehicle } from '@/pages/Trajectory.vue';

defineProps<{ vehicles: Vehicle[]; costBasisComplete: boolean }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">By vehicle</div>

        <div v-if="!costBasisComplete" style="font-size: 12.5px; color: #d98a2b; margin-top: 6px">
            Add a cost basis to every investment account to see total gain.
        </div>

        <div style="margin-top: 12px; overflow-x: auto">
            <table style="width: 100%; border-collapse: collapse; font-size: 13.5px; min-width: 460px">
                <thead>
                    <tr style="color: #8a8172; text-align: left">
                        <th style="padding: 6px 8px; font-weight: 500">Account</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Value</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Cost basis</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Gain</th>
                        <th style="padding: 6px 8px; font-weight: 500; text-align: right">Share</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="vehicle in vehicles" :key="vehicle.id" style="border-top: 1px solid #e0dacd">
                        <td style="padding: 9px 8px; color: #211d17; font-weight: 600">
                            {{ vehicle.name }}
                            <span v-if="vehicle.institution" style="color: #8a8172; font-weight: 400"> · {{ vehicle.institution }}</span>
                        </td>
                        <td style="padding: 9px 8px; text-align: right; color: #211d17">{{ formatMoney(vehicle.value) }}</td>
                        <td style="padding: 9px 8px; text-align: right; color: #6b6355">
                            {{ vehicle.costBasis === null ? '—' : formatMoney(vehicle.costBasis) }}
                        </td>
                        <td style="padding: 9px 8px; text-align: right"
                            :style="{ color: (vehicle.gainLoss ?? 0) >= 0 ? '#2f7d5b' : '#c0512c' }">
                            {{ vehicle.gainLoss === null ? '—' : formatSigned(vehicle.gainLoss) }}
                        </td>
                        <td style="padding: 9px 8px; text-align: right; color: #6b6355">{{ vehicle.allocationPct.toFixed(1) }}%</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
```

`resources/js/components/trajectory/AllocationChart.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import ReportDonutChart from '@/components/reports/ReportDonutChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import type { Vehicle } from '@/pages/Trajectory.vue';

const props = defineProps<{ vehicles: Vehicle[] }>();

const palette = ['#3f7fb0', '#2f7d5b', '#a85fc0', '#d98a2b', '#c0512c', '#6b6355'];

const labels = computed(() => props.vehicles.map(vehicle => vehicle.name));
const data = computed(() => props.vehicles.map(vehicle => vehicle.value));
const colors = computed(() => props.vehicles.map((_, index) => palette[index % palette.length]));
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Allocation</div>
        <div v-if="vehicles.length > 0" style="margin-top: 12px">
            <ReportDonutChart :labels="labels" :data="data" :colors="colors" />
        </div>
        <UnavailableNote v-else reason="No investment accounts to allocate." />
    </section>
</template>
```

`resources/js/components/trajectory/FireProgress.vue`:

```vue
<script setup lang="ts">
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import { formatMoney } from '@/components/trajectory/format';
import type { Fire } from '@/pages/Trajectory.vue';

defineProps<{ fire: Fire; totalValue: number }>();
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Financial independence</div>

        <template v-if="fire.available">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; color: #211d17; margin-top: 4px">
                {{ (fire.progressPct ?? 0).toFixed(1) }}%
                <span style="font-size: 15px; font-weight: 500; color: #8a8172">
                    of {{ formatMoney(fire.fiNumber ?? 0) }}
                </span>
            </div>

            <div style="height: 10px; border-radius: 999px; background: #f0ebe0; overflow: hidden; margin-top: 12px">
                <div :style="{ width: `${Math.min(100, fire.progressPct ?? 0)}%`, height: '100%', background: '#2f7d5b' }" />
            </div>

            <div style="font-size: 13px; color: #6b6355; margin-top: 10px">
                {{ formatMoney(totalValue) }} invested ·
                {{ fire.yearsToFi === null ? 'not reachable at the current pace' : `about ${fire.yearsToFi} year(s) away` }}
            </div>
            <div style="font-size: 12px; color: #8a8172; margin-top: 4px">
                Assuming {{ fire.expectedReturnRate }}% a year and {{ formatMoney(fire.avgMonthlyContribution) }}/mo.
            </div>
        </template>
        <UnavailableNote v-else :reason="fire.reason" />
    </section>
</template>
```

In `resources/js/pages/Trajectory.vue`, add the types, props and section markup:

```ts
export type EmergencyFund = {
    available: boolean; reason?: string;
    balance: number; avgMonthlyExpenses: number;
    monthsCovered: number | null; targetMonths: number; progressPct: number | null;
};

export type GoalRow = {
    id: number; name: string; icon: string; color: string; bg: string;
    saved: number; target: number; progressPct: number; perMonth: number;
    byDate: string | null; monthsRemaining: number | null; requiredMonthly: number | null; onTrack: boolean | null;
};

export type CashBuffer = { available: boolean; reason?: string; trend: TrendPoint[] };

export type SavingsSummary = {
    available: boolean; reason?: string;
    emergencyFund: EmergencyFund; goals: GoalRow[]; cashBuffer: CashBuffer;
};

export type Vehicle = {
    id: number; name: string; institution: string | null;
    value: number; costBasis: number | null; gainLoss: number | null; allocationPct: number;
};

export type Fire = {
    available: boolean; reason?: string;
    fiNumber: number | null; progressPct: number | null;
    expectedReturnRate: number; avgMonthlyContribution: number; yearsToFi: number | null;
};

export type InvestmentsSummary = {
    available: boolean; reason?: string;
    totalValue: number; totalCostBasis: number; costBasisComplete: boolean;
    gainLoss: number | null; gainLossPct: number | null;
    contributionsThisMonth: number; contributionTarget: number | null; contributionProgressPct: number | null;
    vehicles: Vehicle[]; fire: Fire;
};
```

Add `savings: SavingsSummary;` and `investments: InvestmentsSummary;` to `defineProps`, import the seven trajectory components, and append to the template:

```vue
        <div class="grid gap-3 lg:grid-cols-2">
            <EmergencyFundProgress :emergency-fund="savings.emergencyFund" />
            <SavingsGoalsList :goals="savings.goals" />
        </div>
        <CashBufferTrend :cash-buffer="savings.cashBuffer" />

        <template v-if="investments.available">
            <div class="grid gap-3 lg:grid-cols-2">
                <InvestmentContributions
                    :contributions-this-month="investments.contributionsThisMonth"
                    :contribution-target="investments.contributionTarget"
                    :contribution-progress-pct="investments.contributionProgressPct"
                />
                <FireProgress :fire="investments.fire" :total-value="investments.totalValue" />
            </div>
            <div class="grid gap-3 lg:grid-cols-2">
                <InvestmentVehicleTable :vehicles="investments.vehicles" :cost-basis-complete="investments.costBasisComplete" />
                <AllocationChart :vehicles="investments.vehicles" />
            </div>
        </template>
        <UnavailableNote v-else :reason="investments.reason" />
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter="savings and investment sections render"` — Expected: PASS.
Run: `npm run build` — Expected: build succeeds with no unresolved imports and no TypeScript errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Trajectory.vue resources/js/components/trajectory resources/js/components/reports/ReportDonutChart.vue tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: add savings and investment sections to the trajectory page"
```

---

### Task 21: Savings rate, monthly flow and signature metric components

**Files:**
- Create: `resources/js/components/trajectory/SavingsRateCard.vue`
- Create: `resources/js/components/trajectory/MonthlyFlowCard.vue`
- Create: `resources/js/components/trajectory/SignatureMetricCard.vue`
- Modify: `resources/js/pages/Trajectory.vue` (final composition — the signature metric leads the page, above net worth)
- Test: `tests/Feature/FinanceTrajectoryReportTest.php`

**Interfaces:**
- Consumes: the `savingsRate`, `monthlyFlow` and `signature` props (Tasks 11, 15, 16, 17), `format.ts` + `UnavailableNote.vue` (Task 18), `ReportLineChart.vue`.
- Produces: the completed page. After this task `Trajectory.vue` declares all eight props and renders, in order: `SignatureMetricCard`, `NetWorthCard`, `SavingsRateCard` + `MonthlyFlowCard`, the debt section, the savings section, the investments section.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/FinanceTrajectoryReportTest.php`:

```php
test('trajectory page exposes every field the flow and signature sections render', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-05'));

    $user = User::factory()->create();
    $user->markEmailAsVerified();
    $account = Account::factory()->for($user)->create(['type' => 'cash', 'balance' => 40000]);
    $category = BudgetCategory::factory()->for($user)->create(['name' => 'Food', 'limit_amount' => 5000]);

    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'type' => 'income', 'amount' => 50000, 'transacted_at' => '2026-08-01',
    ]);
    \App\Models\Transaction::factory()->for($user)->create([
        'account_id' => $account->id, 'budget_category_id' => $category->id,
        'type' => 'expense', 'amount' => 7500, 'transacted_at' => '2026-08-03',
    ]);

    $this->actingAs($user)
        ->get(route('trajectory'))
        ->assertInertia(fn ($page) => $page
            ->where('savingsRate.available', true)
            ->where('savingsRate.rate', 85.0)
            ->has('savingsRate.trend', 6)
            ->where('monthlyFlow.available', true)
            ->where('monthlyFlow.monthLabel', 'August 2026')
            ->where('monthlyFlow.overBudget.top.0.name', 'Food')
            ->where('monthlyFlow.fixedVsVariable.variable', 7500)
            ->where('signature.available', false)
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="flow and signature sections render"`
Expected: PASS on the backend, then `npm run build` FAILS with `Failed to resolve import "@/components/trajectory/SignatureMetricCard.vue"` once the `Trajectory.vue` edit is applied. Apply the page edit first, confirm the build failure, then add the components.

- [ ] **Step 3: Write minimal implementation**

`resources/js/components/trajectory/SavingsRateCard.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import { formatMoney, formatPct } from '@/components/trajectory/format';
import type { SavingsRate } from '@/pages/Trajectory.vue';

const props = defineProps<{ savingsRate: SavingsRate }>();

const labels = computed(() => (props.savingsRate.trend ?? []).map(point => point.label));

const datasets = computed(() => [
    {
        label: 'Savings rate %',
        data: (props.savingsRate.trend ?? []).map(point => point.rate),
        borderColor: '#2f7d5b',
        spanGaps: true,
    },
]);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">Savings rate</div>

        <template v-if="savingsRate.available">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 26px; margin-top: 4px"
                 :style="{ color: (savingsRate.rate ?? 0) >= 0 ? '#2f7d5b' : '#c0512c' }">
                {{ formatPct(savingsRate.rate) }}
            </div>
            <div style="font-size: 13px; color: #6b6355; margin-top: 4px">
                Kept {{ formatMoney(savingsRate.net) }} of {{ formatMoney(savingsRate.income) }}
                · 6-month average {{ formatPct(savingsRate.averageRate) }}
            </div>
            <div style="margin-top: 12px">
                <ReportLineChart :labels="labels" :datasets="datasets" />
            </div>
        </template>
        <UnavailableNote v-else :reason="savingsRate.reason" />
    </section>
</template>
```

`resources/js/components/trajectory/MonthlyFlowCard.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import { formatMoney, formatPct } from '@/components/trajectory/format';
import type { MonthlyFlow } from '@/pages/Trajectory.vue';

const props = defineProps<{ monthlyFlow: MonthlyFlow }>();

const fixedWidth = computed(() => {
    const split = props.monthlyFlow.fixedVsVariable;
    if (!split) return 0;
    const total = split.fixed + split.variable;
    return total > 0 ? (split.fixed / total) * 100 : 0;
});
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">
            This month · {{ monthlyFlow.monthLabel ?? '' }}
        </div>

        <template v-if="monthlyFlow.available">
            <div style="display: flex; gap: 18px; flex-wrap: wrap; margin-top: 8px">
                <div>
                    <div style="font-size: 12px; color: #8a8172">In</div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #2f7d5b">{{ formatMoney(monthlyFlow.income) }}</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #8a8172">Out</div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #c0512c">{{ formatMoney(monthlyFlow.expenses) }}</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #8a8172">Net</div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px"
                         :style="{ color: monthlyFlow.net >= 0 ? '#2f7d5b' : '#c0512c' }">{{ formatMoney(monthlyFlow.net) }}</div>
                </div>
                <div>
                    <div style="font-size: 12px; color: #8a8172">Budget used</div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #211d17">{{ formatPct(monthlyFlow.budgetUsedPct) }}</div>
                </div>
            </div>

            <div style="margin-top: 16px">
                <div style="font-size: 12px; color: #8a8172">Fixed vs variable spending</div>
                <div style="display: flex; height: 12px; border-radius: 999px; overflow: hidden; background: #f0ebe0; margin-top: 6px">
                    <div :style="{ width: `${fixedWidth}%`, background: '#6b6355' }" />
                    <div :style="{ width: `${100 - fixedWidth}%`, background: '#d98a2b' }" />
                </div>
                <div style="font-size: 12.5px; color: #6b6355; margin-top: 6px">
                    Fixed {{ formatMoney(monthlyFlow.fixedVsVariable.fixed) }} ·
                    Variable {{ formatMoney(monthlyFlow.fixedVsVariable.variable) }}
                </div>
            </div>

            <div v-if="monthlyFlow.overBudget.top.length > 0" style="margin-top: 16px; padding-top: 12px; border-top: 1px solid #e0dacd">
                <div style="font-size: 12px; color: #8a8172">Furthest over budget</div>
                <div v-for="row in monthlyFlow.overBudget.top" :key="row.id"
                     style="display: flex; align-items: center; gap: 8px; margin-top: 8px; font-size: 13.5px">
                    <span class="material-symbols-rounded" :style="{ fontSize: '18px', color: row.color }">{{ row.icon }}</span>
                    <span style="color: #211d17; font-weight: 600">{{ row.name }}</span>
                    <span style="margin-left: auto; color: #c0512c; font-weight: 600">
                        +{{ formatMoney(row.overAmount) }} ({{ row.overPct.toFixed(0) }}%)
                    </span>
                </div>
                <div v-if="monthlyFlow.overBudget.remainingCount > 0" style="font-size: 12.5px; color: #8a8172; margin-top: 8px">
                    and {{ monthlyFlow.overBudget.remainingCount }} more over by {{ formatMoney(monthlyFlow.overBudget.remainingTotal) }} in total
                </div>
            </div>
        </template>
        <UnavailableNote v-else :reason="monthlyFlow.reason" />
    </section>
</template>
```

`resources/js/components/trajectory/SignatureMetricCard.vue`:

```vue
<script setup lang="ts">
import { computed } from 'vue';
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
import UnavailableNote from '@/components/trajectory/UnavailableNote.vue';
import { formatMoney, formatSigned } from '@/components/trajectory/format';
import type { SignatureMetric } from '@/pages/Trajectory.vue';

const props = defineProps<{ signature: SignatureMetric }>();

const labels = computed(() => (props.signature.trend ?? []).map(point => point.label));

const datasets = computed(() => [
    {
        label: 'Investment gain minus debt interest',
        data: (props.signature.trend ?? []).map(point => point.value),
        borderColor: '#211d17',
        backgroundColor: 'rgba(33, 29, 23, 0.08)',
        fill: true,
    },
]);

const winning = computed(() => (props.signature.currentValue ?? 0) > 0);
</script>

<template>
    <section style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-size: 12px; color: #8a8172; text-transform: uppercase; letter-spacing: .06em">
            Is your money outrunning your debt?
        </div>

        <template v-if="signature.available">
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 30px; letter-spacing: -.02em; margin-top: 4px"
                 :style="{ color: winning ? '#2f7d5b' : '#c0512c' }">
                {{ formatSigned(signature.currentValue ?? 0) }}
            </div>
            <div style="font-size: 13.5px; color: #6b6355; margin-top: 4px">
                {{ winning
                    ? 'Your investments earned more this month than your debt cost you.'
                    : 'Your debt cost you more this month than your investments earned.' }}
            </div>
            <div v-if="signature.crossedZeroLabel" style="font-size: 12.5px; color: #2f7d5b; margin-top: 6px">
                First pulled ahead in {{ signature.crossedZeroLabel }}.
            </div>

            <div style="margin-top: 14px">
                <ReportLineChart :labels="labels" :datasets="datasets" />
            </div>

            <div v-if="signature.trend.length > 0" style="font-size: 12px; color: #8a8172; margin-top: 8px">
                Latest month: {{ formatMoney(signature.trend[signature.trend.length - 1].investmentGain) }} gained,
                {{ formatMoney(signature.trend[signature.trend.length - 1].debtInterest) }} paid in interest.
            </div>
        </template>
        <UnavailableNote v-else :reason="signature.reason" />
    </section>
</template>
```

In `resources/js/pages/Trajectory.vue`, add the remaining types:

```ts
export type SavingsRatePoint = { month: string; label: string; income: number; expenses: number; rate: number | null };

export type SavingsRate = {
    available: boolean; reason?: string;
    income: number; expenses: number; net: number;
    rate: number | null; trend: SavingsRatePoint[]; averageRate: number | null;
};

export type OverBudgetRow = {
    id: number; name: string; icon: string; color: string;
    limit: number; actual: number; overAmount: number; overPct: number;
};

export type MonthlyFlow = {
    available: boolean; reason?: string; monthLabel?: string;
    income: number; expenses: number; net: number;
    budgetTotal: number; budgetUsedPct: number | null;
    overBudget: { top: OverBudgetRow[]; remainingCount: number; remainingTotal: number };
    fixedVsVariable: { fixed: number; variable: number; fixedPct: number | null };
};

export type SignaturePoint = { month: string; label: string; value: number; investmentGain: number; debtInterest: number };

export type SignatureMetric = {
    available: boolean; reason?: string;
    currentValue: number | null; trend: SignaturePoint[];
    crossedZeroMonth: string | null; crossedZeroLabel: string | null;
};
```

Add `savingsRate: SavingsRate; monthlyFlow: MonthlyFlow; signature: SignatureMetric;` to `defineProps`, import the three components, and set the final template body order inside the page wrapper: header, `<SignatureMetricCard :signature="signature" />`, `<NetWorthCard :net-worth="netWorth" />`, then

```vue
        <div class="grid gap-3 lg:grid-cols-2">
            <SavingsRateCard :savings-rate="savingsRate" />
            <MonthlyFlowCard :monthly-flow="monthlyFlow" />
        </div>
```

followed by the debt, savings and investment blocks added in Tasks 19–20.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=FinanceTrajectoryReportTest` — Expected: PASS (all tests).
Run: `npm run build` — Expected: build succeeds with no unresolved imports and no TypeScript errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Trajectory.vue resources/js/components/trajectory tests/Feature/FinanceTrajectoryReportTest.php
git commit -m "feat: complete the trajectory page with flow and signature metric cards"
```

---

### Task 22: Demo data for the trajectory report

**Files:**
- Modify: `database/seeders/DatabaseSeeder.php` (add one call at the end of `run()` and one private method)
- Modify: `tests/Feature/SeederTest.php`
- Test: `tests/Feature/SeederTest.php`

**Interfaces:**
- Consumes: every model and column added in Tasks 1–5.
- Produces: the demo user gains an emergency-fund account, two investment accounts with a cost basis, three debts at different interest rates, two goals (one dated), 12 months of backdated income/expense/contribution transactions, 12 months of `DebtPayment` rows with a principal/interest split, and 12 months of month-end balance snapshots for every account and debt — enough for every section of the page to render with real trends.

**Decision — extend the monolith, do not add a seeder class.** `database/seeders/DatabaseSeeder.php` is a single 134-line `run()` with no sub-seeders anywhere in the app, and `CLAUDE.md` says to stick to the existing structure. Adding ~120 lines inline would make `run()` unwieldy, so the new data goes in a **private method on the same class** — the file stays the single seeder the app already has, and `run()` grows by one line. Snapshots are written directly rather than by shelling out to `finance:snapshot --backfill`, so the demo data is deterministic and does not depend on the reconciliation guard.

- [ ] **Step 1: Write the failing test**

Replace the body of `it('seeds the expected records')` in `tests/Feature/SeederTest.php` and add a second test (add the imports `App\Models\AccountBalanceSnapshot`, `App\Models\Debt`, `App\Models\DebtBalanceSnapshot`, `App\Models\DebtPayment`, `App\Models\Goal`, `App\Models\Setting`):

```php
it('seeds the expected records', function (): void {
    $this->seed(DatabaseSeeder::class);

    expect(User::where('email', 'elle@example.com')->exists())->toBeTrue();
    expect(Task::count())->toBe(9);
    expect(Event::count())->toBe(3);
    expect(Habit::count())->toBe(4);
    expect(Account::count())->toBe(6);
    expect(BudgetMonth::count())->toBe(1);
    expect(HabitLog::where('done', true)->count())->toBeGreaterThanOrEqual(16);
});

it('seeds trajectory demo data with history for every report section', function (): void {
    $this->seed(DatabaseSeeder::class);

    $user = User::where('email', 'elle@example.com')->firstOrFail();
    $setting = Setting::where('user_id', $user->id)->firstOrFail();

    expect((float) $setting->fi_number)->toBeGreaterThan(0.0)
        ->and($setting->emergency_fund_target_months)->toBe(6)
        ->and(Account::where('user_id', $user->id)->where('is_emergency_fund', true)->count())->toBe(1)
        ->and(Account::where('user_id', $user->id)->where('type', 'investment')->whereNotNull('cost_basis')->count())->toBe(2)
        ->and(Debt::where('user_id', $user->id)->count())->toBe(3)
        ->and(Debt::where('user_id', $user->id)->whereNotNull('interest_rate')->count())->toBe(3)
        ->and(Goal::where('user_id', $user->id)->whereNotNull('by_date')->count())->toBe(1)
        ->and(DebtPayment::where('user_id', $user->id)->count())->toBe(36)
        ->and(AccountBalanceSnapshot::where('user_id', $user->id)->count())->toBe(72)
        ->and(DebtBalanceSnapshot::where('user_id', $user->id)->count())->toBe(36);
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter=SeederTest`
Expected: FAIL — `Account::count()` is `3`, not `6`, and `DebtPayment::count()` is `0`.

- [ ] **Step 3: Write minimal implementation**

Add the imports `App\Models\AccountBalanceSnapshot`, `App\Models\Debt`, `App\Models\DebtBalanceSnapshot`, `App\Models\DebtPayment`, `App\Models\Goal`, `App\Models\Transaction` to `database/seeders/DatabaseSeeder.php`, call the new method as the final statement of `run()`:

```php
        $this->seedTrajectoryDemoData($user, $today);
```

and add the method to the same class:

```php
    /**
     * Twelve months of finance history so every section of the trajectory report has
     * something real to draw: an emergency fund, investments with a cost basis, debts
     * at different rates, dated goals, and month-end balance snapshots.
     */
    private function seedTrajectoryDemoData(User $user, Carbon $today): void
    {
        $user->setting()->update([
            'fi_number' => 25000000.00,
            'fi_expected_return_rate' => 6.00,
            'emergency_fund_target_months' => 6,
            'investment_monthly_contribution_target' => 15000.00,
        ]);

        $emergency = Account::create([
            'user_id' => $user->id, 'name' => 'Emergency Fund', 'institution' => 'BDO',
            'last4' => '9021', 'balance' => 150000.00, 'type' => 'cash', 'is_emergency_fund' => true,
        ]);

        $indexFund = Account::create([
            'user_id' => $user->id, 'name' => 'Index Fund', 'institution' => 'COL Financial',
            'last4' => '3310', 'balance' => 420000.00, 'cost_basis' => 350000.00, 'type' => 'investment',
        ]);

        $retirement = Account::create([
            'user_id' => $user->id, 'name' => 'Retirement', 'institution' => 'Sun Life',
            'last4' => '7742', 'balance' => 280000.00, 'cost_basis' => 250000.00, 'type' => 'investment',
        ]);

        $checking = Account::where('user_id', $user->id)->where('name', 'Checking')->firstOrFail();

        $debts = collect([
            ['name' => 'Credit card', 'icon' => 'credit_card', 'color' => '#c0512c', 'bg' => '#fbe2d6',
                'balance' => 85000.00, 'original' => 120000.00, 'per_month' => 8000.00, 'interest_rate' => 18.00, 'due_day' => 15],
            ['name' => 'Car loan', 'icon' => 'directions_car', 'color' => '#3f7fb0', 'bg' => '#dcebf5',
                'balance' => 240000.00, 'original' => 400000.00, 'per_month' => 12000.00, 'interest_rate' => 7.50, 'due_day' => 5],
            ['name' => 'Student loan', 'icon' => 'school', 'color' => '#a85fc0', 'bg' => '#efe0f5',
                'balance' => 95000.00, 'original' => 200000.00, 'per_month' => 5000.00, 'interest_rate' => 4.00, 'due_day' => 20],
        ])->map(fn (array $row, int $index) => Debt::create([
            ...$row,
            'user_id' => $user->id,
            'interest_rate_period' => 'annual',
            'position' => $index,
        ]));

        Goal::create([
            'user_id' => $user->id, 'name' => 'Japan trip', 'icon' => 'flight', 'color' => '#d98a2b', 'bg' => '#f8ecd6',
            'saved' => 45000.00, 'target' => 150000.00, 'per_month' => 10000.00,
            'by_date' => $today->copy()->addMonths(5)->toDateString(), 'position' => 0,
        ]);

        Goal::create([
            'user_id' => $user->id, 'name' => 'New laptop', 'icon' => 'laptop_mac', 'color' => '#2f7d5b', 'bg' => '#dcf0e6',
            'saved' => 30000.00, 'target' => 90000.00, 'per_month' => 5000.00, 'by_date' => null, 'position' => 1,
        ]);

        $accountBalances = [
            $checking->id => 2480.00,
            $emergency->id => 150000.00,
            $indexFund->id => 420000.00,
            $retirement->id => 280000.00,
        ];

        for ($offset = 11; $offset >= 0; $offset--) {
            $month = $today->copy()->startOfMonth()->subMonths($offset);
            $monthEnd = $month->copy()->endOfMonth()->min($today);

            Transaction::create([
                'user_id' => $user->id, 'account_id' => $checking->id, 'type' => 'income',
                'amount' => 85000.00, 'merchant' => 'Salary',
                'transacted_at' => $month->copy()->startOfMonth()->toDateString(),
            ]);

            Transaction::create([
                'user_id' => $user->id, 'account_id' => $checking->id, 'type' => 'expense',
                'amount' => 42000.00, 'merchant' => 'Living costs',
                'transacted_at' => $month->copy()->startOfMonth()->addDays(9)->toDateString(),
            ]);

            Transaction::create([
                'user_id' => $user->id, 'account_id' => $checking->id, 'to_account_id' => $indexFund->id,
                'type' => 'transfer', 'amount' => 12000.00, 'merchant' => 'Index fund top-up',
                'transacted_at' => $month->copy()->startOfMonth()->addDays(14)->toDateString(),
            ]);

            foreach ($debts as $debt) {
                $paidAt = $month->copy()->startOfMonth()->addDays(($debt->due_day ?? 15) - 1);
                $amount = (float) $debt->per_month;
                $interest = round($amount * ((float) $debt->interest_rate / 100) / 12 * 12, 2);
                $interest = round(min($interest, $amount * 0.35), 2);

                $transaction = Transaction::create([
                    'user_id' => $user->id, 'account_id' => $checking->id, 'debt_id' => $debt->id,
                    'type' => 'expense', 'amount' => $amount, 'merchant' => $debt->name,
                    'transacted_at' => $paidAt->toDateString(),
                ]);

                DebtPayment::create([
                    'user_id' => $user->id, 'debt_id' => $debt->id, 'transaction_id' => $transaction->id,
                    'account_id' => $checking->id, 'amount' => $amount,
                    'principal_amount' => round($amount - $interest, 2), 'interest_amount' => $interest,
                    'paid_at' => $paidAt->toDateString(),
                ]);

                DebtBalanceSnapshot::create([
                    'user_id' => $user->id, 'debt_id' => $debt->id,
                    'balance' => round((float) $debt->balance + ((float) $debt->original - (float) $debt->balance) * ($offset / 11), 2),
                    'snapshot_date' => $monthEnd->toDateString(),
                ]);
            }

            foreach ($accountBalances as $accountId => $currentBalance) {
                AccountBalanceSnapshot::create([
                    'user_id' => $user->id, 'account_id' => $accountId,
                    'balance' => round($currentBalance * (1 - 0.02 * $offset), 2),
                    'snapshot_date' => $monthEnd->toDateString(),
                ]);
            }
        }
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --compact --filter=SeederTest`
Expected: PASS (2 tests).
Then sanity-check the page against real demo data: `php artisan migrate:fresh --seed` and open `/trajectory` (use `get-absolute-url` for the Herd URL).

- [ ] **Step 5: Commit**

```bash
git add database/seeders/DatabaseSeeder.php tests/Feature/SeederTest.php
git commit -m "feat: seed twelve months of trajectory demo data"
```

---

### Task 23: Final formatting and full-suite verification

**Files:**
- Modify: every PHP file touched by Tasks 1–22 (formatting only)

**Interfaces:**
- Consumes: everything.
- Produces: a formatted, fully green tree. This is the **only** task that runs Pint.

- [ ] **Step 1: Run the full test suite as a baseline**

Run: `php artisan test --compact`
Expected: PASS, with no failures in `FinanceTrajectoryReportTest`, `RecordFinanceSnapshotTest`, `DebtTest`, `TransactionTest`, `SeederTest`, `ReportTest` or any other pre-existing suite.

- [ ] **Step 2: Format all changed PHP files**

Run: `vendor/bin/pint --dirty --format agent`
Expected: files reformatted in place; no errors.

- [ ] **Step 3: Re-run static analysis and the build**

Run: `vendor/bin/phpstan analyse --memory-limit=512M` (Larastan is installed; if the project has no configured level this is a no-op — skip only if the command reports no configuration)
Run: `npm run build`
Expected: both succeed.

- [ ] **Step 4: Re-run the full test suite after formatting**

Run: `php artisan test --compact`
Expected: PASS — identical result to Step 1.

- [ ] **Step 5: Commit**

```bash
git add -A
git commit -m "style: apply Pint formatting across the trajectory report feature"
```

---

## Self-Review

### Spec coverage

| # | Spec bullet | Task(s) |
| --- | --- | --- |
| 1 | `settings`: `fi_number`, `fi_expected_return_rate`, `emergency_fund_target_months`, `investment_monthly_contribution_target` + model fillable/casts | 1 |
| 2 | `accounts`: `cost_basis`, `is_emergency_fund` + model fillable/casts + extended `AccountFactory` (`investment()`, `emergencyFund()` states) | 2 |
| 3 | `transactions.debt_id` nullable FK `nullOnDelete()`, `Transaction::debt()`, `Debt::transactions()` | 3 |
| 4 | `debt_payments` table + `DebtPayment` model + factory + relations + `Debt::debtPayments()` | 4 |
| 5 | `account_balance_snapshots` table + model + factory + `Account::balanceSnapshots()` | 5 |
| 6 | `debt_balance_snapshots` table + model + factory + `Debt::balanceSnapshots()` | 5 |
| 7 | `DebtController::makePayment` interest/principal split, `DebtPayment` row, `transactions.debt_id` stamp | 6 |
| 7b | `TransactionController::destroy` restores `debts.balance` from the payment's principal | 7 |
| 8 | `finance:snapshot` command, per-user daily upsert, registered in `routes/console.php` (first scheduled job, `Schedule` import added) | 8 |
| 9 | `finance:snapshot --backfill=N`: accounts reconstructed backwards from transactions; debts reconstructed forwards with ±1.00 reconciliation guard, `warn()` + skip otherwise | 9 |
| 10 | `TrajectoryReportService` skeleton + `['available' => false, 'reason' => …]` contract | 10 |
| 10a | `netWorth()` — AccountController formula + 12-month snapshot trend, unavailable under 2 months | 10 |
| 10b | `savingsRate()` — current month + 6-month trend, `null` rate when income is 0 | 11 |
| 10c | `debtSummary()` — rate-sorted debts, extracted shared amortization with avalanche `$extraMonthly`, per-debt per-month interest guard, interest this month/YTD, principal-vs-interest trend, DTI + 6-month trend | 12 |
| 10d | `savingsSummary()` — emergency fund months vs target, named goals with required monthly, 12-month cash-buffer minimum | 13 |
| 10e | `investmentsSummary()` — value/cost basis/`costBasisComplete`/gain, contributions vs target, per-vehicle allocation, FIRE progress, growing-annuity years-to-FI | 14 |
| 10f | `monthlyFlow()` — always the current calendar month, top 3 over-budget by percent, rest collapsed, fixed vs variable | 15 |
| 10g | `signatureMetric()` — investment gain net of contributions minus debt interest, 12-month trend, `crossedZeroMonth` | 16 |
| 11 | `FinanceTrajectoryController::index()` with `extra_monthly` validation, route `GET /trajectory` named `trajectory` | 17 |
| 12 | `Trajectory.vue` + all 17 trajectory components, `Reports.vue` styling convention | 18, 19, 20, 21 |
| 13 | Demo/seed data + `DebtPaymentFactory`, `AccountBalanceSnapshotFactory`, `DebtBalanceSnapshotFactory` | 4, 5, 22 |
| 14 | `FinanceTrajectoryReportTest.php` (all service calculations + empty states) and `RecordFinanceSnapshotTest.php` (plain recording, backfill reconciliation, skip-and-warn) | 8–22 (tests are written first in every task) |

### Component coverage (spec bullet 12)

| Component | Task |
| --- | --- |
| `Trajectory.vue` | 18 (shell), 19–21 (composition) |
| `NetWorthCard.vue` | 18 |
| `DebtFreeCard.vue` (what-if input) | 19 |
| `DebtTable.vue` | 19 |
| `InterestBleedingCard.vue` | 19 |
| `PrincipalInterestTrend.vue` | 19 |
| `DtiCard.vue` | 19 |
| `EmergencyFundProgress.vue` | 20 |
| `SavingsGoalsList.vue` | 20 |
| `CashBufferTrend.vue` | 20 |
| `InvestmentContributions.vue` | 20 |
| `InvestmentVehicleTable.vue` | 20 |
| `AllocationChart.vue` | 20 |
| `FireProgress.vue` | 20 |
| `ReportDonutChart.vue` | 20 |
| `SavingsRateCard.vue` | 21 |
| `MonthlyFlowCard.vue` | 21 |
| `SignatureMetricCard.vue` | 21 |

### Gaps found during self-review, and how they were closed

| Gap | Resolution |
| --- | --- |
| Spec bullet 7b restores `debts.balance` by `principal_amount`, but the existing `makePayment` decrements by the **full** payment amount — the pair would be asymmetric and would inflate balances on every delete of an interest-bearing payment. | Task 6 changes `makePayment` to decrement by `principal_amount`. Backward compatible: `DebtFactory` defaults `interest_rate` to `null`, so every pre-existing `DebtTest` expectation is unchanged. |
| Spec bullet 7b only covered unlinked debts; deleting a payment on a **credit-linked** debt would still leave the linked credit account (and therefore the synced debt) understated. | Task 7 mirrors `makePayment`'s branch: linked debts get the full amount added back to the credit account followed by `syncLinkedDebt()`. |
| A strict `['available' => false]` for `netWorth()` would hide the current net worth figure, which is always computable, just because the snapshot history is short. | Task 10 documents the single exception: `netWorth()`'s unavailable payload still carries `current`, `accountTotal`, `debtTotal`, and consumers gate only the trend UI on `available`. |
| The spec's component list had no shared money formatter or empty-state element, which would mean duplicating `formatMoney` across 17 files. | Task 18 adds `resources/js/components/trajectory/format.ts` and `UnavailableNote.vue`; both are listed in the File Structure table. |
| `ReportDonutChart.vue` was listed under `components/trajectory/`, but its siblings `ReportLineChart.vue` / `ReportBarChart.vue` live in `components/reports/`. | Task 20 places it in `resources/js/components/reports/` so the three chart wrappers stay together and the donut is reusable by the Reports page. |
| Nothing in the spec regenerated Wayfinder actions, so `Trajectory.vue` would have had no importable action. | Task 17 Step 3 runs `php artisan wayfinder:generate` and commits the generated files. |
| Seeder architecture was left open ("monolith vs sub-seeder"). | Task 22 decides explicitly: a **private method on the existing `DatabaseSeeder`**, keeping the app's single-seeder convention while keeping `run()` short. Snapshots are written directly rather than via `finance:snapshot --backfill`, so demo data is deterministic and never hits the reconciliation skip. |
| `SeederTest` asserts `Account::count() === 3`, which the new demo accounts break. | Task 22 updates that assertion to `6` and adds a second test covering the trajectory demo records. |
| The avalanche simulation could loop forever, and re-running the `perMonth <= interest` guard only once would wrongly condemn a debt that becomes payable after the extra is redirected to it. | Task 12 caps the loop at 600 months (matching `computePayoff`) and re-evaluates the guard per debt, per month. Rollover of a cleared debt's `perMonth` is explicitly out of scope and documented. |
