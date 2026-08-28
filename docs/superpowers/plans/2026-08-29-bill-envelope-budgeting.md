# Bill/Envelope Budgeting Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the debt-first, zero-based Bill/Envelope budgeting feature — a `/budget` page where a user's income gets assigned in order (debt minimums, then fixed bills, then spending envelopes), with leftover income and underspent envelopes suggested (never auto-applied) toward the payoff engine's priority debt.

**Architecture:** Additive to the existing `DebtFocus` schema. New `Bill`/`BillPeriod` and `Envelope`/`EnvelopePeriod` model pairs (template + per-period snapshot row), a `BudgetService` that lazily creates period rows and detects underspend-sweep candidates, a `BudgetController` following the `LedgerController`/`PayoffPlanController` conventions already in the codebase, and one new nullable `envelope_id` column on the existing `Transaction` model so envelope "spent" figures derive live from tagged ledger transactions rather than being tracked separately.

**Tech Stack:** Laravel 13 / PHP 8.4, Inertia v3 + Vue 3, Pest 4, Eloquent, Wayfinder-generated route/action helpers.

**Spec:** [docs/superpowers/specs/2026-08-28-bill-envelope-budgeting-design.md](../specs/2026-08-28-bill-envelope-budgeting-design.md)

## Global Constraints

- Budgeting period = calendar month. No per-user configurable cycle.
- Period rows (`BillPeriod`/`EnvelopePeriod`) are created **lazily** on first view of a month — no scheduler.
- Every new foreign id accepted from a request is validated with the ownership-scoped pattern already used in `LedgerController`/`PayoffPlanController`: `Rule::exists('table', 'id')->where('user_id', $userId)`.
- Money amounts follow the existing `Transaction.amount` bounds: `min:0.01`, `max:99999999.99` (matches the `decimal(10,2)` column precision).
- Dates: server-side period boundaries are plain `year`/`month` integers, never UTC-derived; any frontend date input defaults via `new Date().toLocaleDateString('en-CA')`, matching the fix already applied across the app (commit `d28df20`).
- The underspend sweep and leftover-income suggestion are **confirm-tap only** — never auto-applied.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes in every task before considering it done.

---

## Task 1: Extract priority debt ordering into `PayoffEngine`

`PayoffPlanController` has a private `orderByPriority()` that sorts debts by avalanche/snowball priority for display. The new `BudgetController` needs the same ordering to find the "priority linked debt" for sweep/extra-payment suggestions. Promote it to a public method on `PayoffEngine` (the single place payoff-ordering logic should live, per the existing design spec's "one engine, not per-debt logic scattered across pages" principle) and have `PayoffPlanController` call it instead of its own copy.

**Files:**
- Modify: `app/Services/DebtFocus/PayoffEngine.php`
- Modify: `app/Http/Controllers/PayoffPlanController.php`
- Test: `tests/Feature/DebtFocus/PayoffEngineTest.php`

**Interfaces:**
- Produces: `PayoffEngine::orderByPriority(Collection<int, Debt> $debts, string $strategy): Collection<int, Debt>` — used by `BudgetController` in Task 4.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DebtFocus/PayoffEngineTest.php`:

```php
test('orderByPriority sorts by highest monthly rate first under avalanche', function () {
    $user = User::factory()->create();
    $lowRate = Debt::factory()->for($user)->create(['name' => 'Low rate', 'monthly_rate' => 5]);
    $highRate = Debt::factory()->for($user)->create(['name' => 'High rate', 'monthly_rate' => 20]);

    $ordered = (new PayoffEngine)->orderByPriority(collect([$lowRate, $highRate]), 'avalanche');

    expect($ordered->pluck('name')->all())->toBe(['High rate', 'Low rate']);
});

test('orderByPriority sorts by lowest balance first under snowball', function () {
    $user = User::factory()->create();
    $bigBalance = Debt::factory()->for($user)->create(['name' => 'Big balance', 'balance' => 5000]);
    $smallBalance = Debt::factory()->for($user)->create(['name' => 'Small balance', 'balance' => 500]);

    $ordered = (new PayoffEngine)->orderByPriority(collect([$bigBalance, $smallBalance]), 'snowball');

    expect($ordered->pluck('name')->all())->toBe(['Small balance', 'Big balance']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=orderByPriority`
Expected: FAIL — `Call to undefined method App\Services\DebtFocus\PayoffEngine::orderByPriority()`

- [ ] **Step 3: Add the method to `PayoffEngine`**

In `app/Services/DebtFocus/PayoffEngine.php`, add this public method (place it after `simulate()`):

```php
    /**
     * @param  Collection<int, Debt>  $debts
     * @return Collection<int, Debt>
     */
    public function orderByPriority(Collection $debts, string $strategy): Collection
    {
        $ordered = $debts->values()->all();

        usort($ordered, fn (Debt $a, Debt $b) => $strategy === 'snowball'
            ? [(float) $a->balance, $a->id] <=> [(float) $b->balance, $b->id]
            : [(float) ($b->monthly_rate ?? -1), $a->id] <=> [(float) ($a->monthly_rate ?? -1), $b->id]);

        return collect($ordered);
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --compact --filter=orderByPriority`
Expected: PASS

- [ ] **Step 5: Update `PayoffPlanController` to use the shared method and remove its own copy**

In `app/Http/Controllers/PayoffPlanController.php`:

Change:
```php
        $ordered = $this->orderByPriority($debts, $settings->strategy);
```
to:
```php
        $ordered = $engine->orderByPriority($debts, $settings->strategy);
```

Delete the entire private `orderByPriority()` method at the bottom of the class (it now lives on `PayoffEngine`).

- [ ] **Step 6: Run the full DebtFocus test suite to verify no regression**

Run: `php artisan test --compact tests/Feature/DebtFocus`
Expected: PASS — including the pre-existing `'orders debts by highest monthly rate first under the avalanche strategy'` and `'orders debts by lowest starting balance first under the snowball strategy'` tests in `PayoffPlanControllerTest.php`, which exercise the same behavior through `/payoff-plan` and must still pass unchanged.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/DebtFocus/PayoffEngine.php app/Http/Controllers/PayoffPlanController.php tests/Feature/DebtFocus/PayoffEngineTest.php
git commit -m "refactor: promote debt priority ordering to PayoffEngine"
```

---

## Task 2: Bill/Envelope schema — migrations, models, factories, User relations

**Files:**
- Create: `database/migrations/2026_08_29_100000_create_debt_focus_bills_table.php`
- Create: `database/migrations/2026_08_29_100100_create_debt_focus_bill_periods_table.php`
- Create: `database/migrations/2026_08_29_100200_create_debt_focus_envelopes_table.php`
- Create: `database/migrations/2026_08_29_100300_create_debt_focus_envelope_periods_table.php`
- Create: `database/migrations/2026_08_29_100400_add_envelope_id_to_debt_focus_transactions_table.php`
- Create: `app/Models/DebtFocus/Bill.php`
- Create: `app/Models/DebtFocus/BillPeriod.php`
- Create: `app/Models/DebtFocus/Envelope.php`
- Create: `app/Models/DebtFocus/EnvelopePeriod.php`
- Modify: `app/Models/DebtFocus/Transaction.php`
- Modify: `app/Models/User.php`
- Create: `database/factories/DebtFocus/BillFactory.php`
- Create: `database/factories/DebtFocus/BillPeriodFactory.php`
- Create: `database/factories/DebtFocus/EnvelopeFactory.php`
- Create: `database/factories/DebtFocus/EnvelopePeriodFactory.php`
- Test: `tests/Feature/DebtFocus/BudgetSchemaTest.php`

**Interfaces:**
- Produces: `Bill` (`id, user_id, name, expected_amount, due_day, active`), `BillPeriod` (`id, bill_id, year, month, expected_amount, actual_amount, paid_at`), `Envelope` (`id, user_id, name, icon, default_target, active`), `EnvelopePeriod` (`id, envelope_id, year, month, target, sweep_offered_at, sweep_accepted`). `Transaction.envelope_id` (nullable, `nullOnDelete`). `User::debtFocusBills(): HasMany<Bill>`, `User::debtFocusEnvelopes(): HasMany<Envelope>`. Used by `BudgetService` (Task 3) and `BudgetController` (Tasks 4-8).

- [ ] **Step 1: Write the failing schema/model test**

Create `tests/Feature/DebtFocus/BudgetSchemaTest.php`:

```php
<?php

use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Transaction;
use App\Models\User;

test('a bill has many bill periods', function () {
    $bill = Bill::factory()->create();
    BillPeriod::factory()->for($bill)->create();
    BillPeriod::factory()->for($bill)->create();

    expect($bill->periods)->toHaveCount(2);
});

test('deleting a bill cascades its periods', function () {
    $bill = Bill::factory()->create();
    $period = BillPeriod::factory()->for($bill)->create();

    $bill->delete();

    expect(BillPeriod::find($period->id))->toBeNull();
});

test('an envelope has many envelope periods', function () {
    $envelope = Envelope::factory()->create();
    EnvelopePeriod::factory()->for($envelope)->create();

    expect($envelope->periods)->toHaveCount(1);
});

test('deleting an envelope cascades its periods and nulls tagged transactions', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create();
    $period = EnvelopePeriod::factory()->for($envelope)->create();
    $transaction = Transaction::factory()->for($user)->create(['envelope_id' => $envelope->id]);

    $envelope->delete();

    expect(EnvelopePeriod::find($period->id))->toBeNull();
    expect($transaction->fresh()->envelope_id)->toBeNull();
});

test('bill and envelope active flags default to true and cast to boolean', function () {
    $bill = Bill::factory()->create();
    $envelope = Envelope::factory()->create();

    expect($bill->active)->toBeTrue();
    expect($envelope->active)->toBeTrue();
});

test('a user has many debt focus bills and envelopes', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create();
    Envelope::factory()->for($user)->create();

    expect($user->debtFocusBills)->toHaveCount(1);
    expect($user->debtFocusEnvelopes)->toHaveCount(1);
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --compact --filter=BudgetSchemaTest`
Expected: FAIL — classes/tables don't exist yet.

- [ ] **Step 3: Create the migrations**

`database/migrations/2026_08_29_100000_create_debt_focus_bills_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_focus_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('expected_amount', 10, 2);
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_focus_bills');
    }
};
```

`database/migrations/2026_08_29_100100_create_debt_focus_bill_periods_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_focus_bill_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('debt_focus_bills')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('expected_amount', 10, 2);
            $table->decimal('actual_amount', 10, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['bill_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_focus_bill_periods');
    }
};
```

`database/migrations/2026_08_29_100200_create_debt_focus_envelopes_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_focus_envelopes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('icon')->nullable();
            $table->decimal('default_target', 10, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_focus_envelopes');
    }
};
```

`database/migrations/2026_08_29_100300_create_debt_focus_envelope_periods_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_focus_envelope_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('debt_focus_envelopes')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('target', 10, 2);
            $table->timestamp('sweep_offered_at')->nullable();
            $table->boolean('sweep_accepted')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_focus_envelope_periods');
    }
};
```

`database/migrations/2026_08_29_100400_add_envelope_id_to_debt_focus_transactions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->foreignId('envelope_id')->nullable()->after('to_account_id')
                ->constrained('debt_focus_envelopes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->dropForeign(['envelope_id']);
            $table->dropColumn('envelope_id');
        });
    }
};
```

- [ ] **Step 4: Create the models**

`app/Models/DebtFocus/Bill.php`:

```php
<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\BillFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Bill extends Model
{
    /** @use HasFactory<BillFactory> */
    use HasFactory;

    protected $table = 'debt_focus_bills';

    protected $fillable = [
        'user_id', 'name', 'expected_amount', 'due_day', 'active',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'active' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<BillPeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(BillPeriod::class);
    }
}
```

`app/Models/DebtFocus/BillPeriod.php`:

```php
<?php

namespace App\Models\DebtFocus;

use Database\Factories\DebtFocus\BillPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillPeriod extends Model
{
    /** @use HasFactory<BillPeriodFactory> */
    use HasFactory;

    protected $table = 'debt_focus_bill_periods';

    protected $fillable = [
        'bill_id', 'year', 'month', 'expected_amount', 'actual_amount', 'paid_at',
    ];

    protected $casts = [
        'expected_amount' => 'decimal:2',
        'actual_amount' => 'decimal:2',
        'paid_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Bill, $this>
     */
    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
```

`app/Models/DebtFocus/Envelope.php`:

```php
<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\EnvelopeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Envelope extends Model
{
    /** @use HasFactory<EnvelopeFactory> */
    use HasFactory;

    protected $table = 'debt_focus_envelopes';

    protected $fillable = [
        'user_id', 'name', 'icon', 'default_target', 'active',
    ];

    protected $casts = [
        'default_target' => 'decimal:2',
        'active' => 'boolean',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<EnvelopePeriod, $this>
     */
    public function periods(): HasMany
    {
        return $this->hasMany(EnvelopePeriod::class);
    }
}
```

`app/Models/DebtFocus/EnvelopePeriod.php`:

```php
<?php

namespace App\Models\DebtFocus;

use Database\Factories\DebtFocus\EnvelopePeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EnvelopePeriod extends Model
{
    /** @use HasFactory<EnvelopePeriodFactory> */
    use HasFactory;

    protected $table = 'debt_focus_envelope_periods';

    protected $fillable = [
        'envelope_id', 'year', 'month', 'target', 'sweep_offered_at', 'sweep_accepted',
    ];

    protected $casts = [
        'target' => 'decimal:2',
        'sweep_offered_at' => 'datetime',
        'sweep_accepted' => 'boolean',
    ];

    /**
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
}
```

- [ ] **Step 5: Add `envelope_id` to the `Transaction` model**

In `app/Models/DebtFocus/Transaction.php`, change the `$fillable` array:

```php
    protected $fillable = [
        'user_id', 'type', 'account_id', 'from_account_id', 'to_account_id', 'amount', 'description', 'occurred_at', 'envelope_id',
    ];
```

Add this relation after `toAccount()`:

```php
    /**
     * @return BelongsTo<Envelope, $this>
     */
    public function envelope(): BelongsTo
    {
        return $this->belongsTo(Envelope::class);
    }
```

- [ ] **Step 6: Add `debtFocusBills`/`debtFocusEnvelopes` relations to `User`**

In `app/Models/User.php`, add to the import block (after the existing `DebtFocus` imports):

```php
use App\Models\DebtFocus\Bill as DebtFocusBill;
use App\Models\DebtFocus\Envelope as DebtFocusEnvelope;
```

Add these two methods after `debtFocusAccounts()`:

```php
    /**
     * @return HasMany<DebtFocusBill, $this>
     */
    public function debtFocusBills(): HasMany
    {
        return $this->hasMany(DebtFocusBill::class);
    }

    /**
     * @return HasMany<DebtFocusEnvelope, $this>
     */
    public function debtFocusEnvelopes(): HasMany
    {
        return $this->hasMany(DebtFocusEnvelope::class);
    }
```

- [ ] **Step 7: Create the factories**

`database/factories/DebtFocus/BillFactory.php`:

```php
<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Bill;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Bill> */
class BillFactory extends Factory
{
    protected $model = Bill::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'expected_amount' => fake()->randomFloat(2, 20, 300),
            'due_day' => fake()->numberBetween(1, 28),
            'active' => true,
        ];
    }
}
```

`database/factories/DebtFocus/BillPeriodFactory.php`:

```php
<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<BillPeriod> */
class BillPeriodFactory extends Factory
{
    protected $model = BillPeriod::class;

    public function definition(): array
    {
        return [
            'bill_id' => Bill::factory(),
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'expected_amount' => fake()->randomFloat(2, 20, 300),
            'actual_amount' => null,
            'paid_at' => null,
        ];
    }
}
```

`database/factories/DebtFocus/EnvelopeFactory.php`:

```php
<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Envelope;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Envelope> */
class EnvelopeFactory extends Factory
{
    protected $model = Envelope::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'icon' => null,
            'default_target' => fake()->randomFloat(2, 20, 300),
            'active' => true,
        ];
    }
}
```

`database/factories/DebtFocus/EnvelopePeriodFactory.php`:

```php
<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EnvelopePeriod> */
class EnvelopePeriodFactory extends Factory
{
    protected $model = EnvelopePeriod::class;

    public function definition(): array
    {
        return [
            'envelope_id' => Envelope::factory(),
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'target' => fake()->randomFloat(2, 20, 300),
            'sweep_offered_at' => null,
            'sweep_accepted' => null,
        ];
    }
}
```

- [ ] **Step 8: Run migrations and the test**

Run: `php artisan migrate`
Run: `php artisan test --compact --filter=BudgetSchemaTest`
Expected: PASS (6 tests)

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations app/Models/DebtFocus/Bill.php app/Models/DebtFocus/BillPeriod.php app/Models/DebtFocus/Envelope.php app/Models/DebtFocus/EnvelopePeriod.php app/Models/DebtFocus/Transaction.php app/Models/User.php database/factories/DebtFocus tests/Feature/DebtFocus/BudgetSchemaTest.php
git commit -m "feat: add Bill/Envelope schema for budgeting"
```

---

## Task 3: `BudgetService` — lazy period creation, spend derivation, sweep detection

**Files:**
- Create: `app/Services/DebtFocus/BudgetService.php`
- Test: `tests/Feature/DebtFocus/BudgetServiceTest.php`

**Interfaces:**
- Consumes: `Bill`, `BillPeriod`, `Envelope`, `EnvelopePeriod`, `Transaction` models from Task 2.
- Produces: `BudgetService::periodFor(User $user, int $year, int $month): array{bills: Collection<int,BillPeriod>, envelopes: Collection<int,EnvelopePeriod>, sweepCandidates: Collection<int,EnvelopePeriod>}`, `BudgetService::spentForPeriod(EnvelopePeriod $period): float`, `BudgetService::periodBounds(int $year, int $month): array{0: string, 1: string}`. Used by `BudgetController` (Tasks 4-8).

Note: sweep detection only looks one month back from the month currently being opened for the first time — a user who skips a month entirely (never opens it) won't be offered a sweep for what they missed in between. This is an accepted scope boundary, not a bug to fix here.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DebtFocus/BudgetServiceTest.php`:

```php
<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\User;
use App\Services\DebtFocus\BudgetService;
use App\Services\DebtFocus\LedgerService;

test('periodFor lazily creates a bill period snapshotting the template amount', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 120]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['bills'])->toHaveCount(1);
    expect((float) $period['bills']->first()->expected_amount)->toBe(120.0);
    expect(BillPeriod::where('bill_id', $bill->id)->where('year', 2026)->where('month', 8)->exists())->toBeTrue();
});

test('periodFor does not recreate an existing period row', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create();
    $existing = BillPeriod::factory()->for($bill)->create(['year' => 2026, 'month' => 8, 'actual_amount' => 50]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['bills']->first()->id)->toBe($existing->id);
    expect((float) $period['bills']->first()->actual_amount)->toBe(50.0);
});

test('periodFor skips inactive bills and envelopes', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['active' => false]);
    Envelope::factory()->for($user)->create(['active' => false]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['bills'])->toHaveCount(0);
    expect($period['envelopes'])->toHaveCount(0);
});

test('a template edit does not change an already-created period snapshot', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['expected_amount' => 100]);
    (new BudgetService)->periodFor($user, 2026, 8);

    $bill->update(['expected_amount' => 200]);
    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect((float) $period['bills']->first()->expected_amount)->toBe(100.0);
});

test('spentForPeriod sums expense transactions tagged to the envelope within the period', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create();
    $period = EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 8]);
    $ledger = new LedgerService;
    $ledger->recordExpense($account, 30, 'Groceries', now()->setDate(2026, 8, 5), $envelope->id);
    $ledger->recordExpense($account, 20, 'Snacks', now()->setDate(2026, 8, 15), $envelope->id);
    $ledger->recordExpense($account, 999, 'Other month', now()->setDate(2026, 7, 15), $envelope->id);

    $spent = (new BudgetService)->spentForPeriod($period);

    expect($spent)->toBe(50.0);
});

test('opening a new month offers a sweep for an underspent, unoffered prior-month envelope', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 100]);
    $priorPeriod = EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 7, 'target' => 100]);
    (new LedgerService)->recordExpense($account, 40, 'Groceries', now()->setDate(2026, 7, 10), $envelope->id);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['sweepCandidates'])->toHaveCount(1);
    expect($period['sweepCandidates']->first()->id)->toBe($priorPeriod->id);
    expect($priorPeriod->fresh()->sweep_offered_at)->not->toBeNull();
});

test('an envelope that was fully spent is not offered as a sweep candidate', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 7, 'target' => 100]);
    (new LedgerService)->recordExpense($account, 100, 'All of it', now()->setDate(2026, 7, 10), $envelope->id);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['sweepCandidates'])->toHaveCount(0);
});

test('an already-offered sweep is not offered again on a later visit', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 7, 'target' => 100, 'sweep_offered_at' => now()]);

    $period = (new BudgetService)->periodFor($user, 2026, 8);

    expect($period['sweepCandidates'])->toHaveCount(0);
});

test('revisiting the same month does not re-run sweep detection', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 1000]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    EnvelopePeriod::factory()->for($envelope)->create(['year' => 2026, 'month' => 7, 'target' => 100]);
    (new LedgerService)->recordExpense($account, 40, 'Groceries', now()->setDate(2026, 7, 10), $envelope->id);

    (new BudgetService)->periodFor($user, 2026, 8);
    $second = (new BudgetService)->periodFor($user, 2026, 8);

    expect($second['sweepCandidates'])->toHaveCount(0);
});

test('periodBounds returns the first and last date of the given month', function () {
    [$start, $end] = (new BudgetService)->periodBounds(2026, 2);

    expect($start)->toBe('2026-02-01');
    expect($end)->toBe('2026-02-28');
});
```

This test file calls `LedgerService::recordExpense()` with a 5th `$envelopeId` argument that doesn't exist yet — that's added in Task 9. **Skip running this file until Task 9 is done**, or temporarily comment out the `spentForPeriod`/sweep tests that call `recordExpense` with 5 arguments. The cleanest path: write this whole file now, but only run the two tests that don't need the 5th argument (`periodFor lazily creates...`, `periodFor does not recreate...`, `periodFor skips inactive...`, `a template edit does not change...`, `periodBounds returns...`) at the end of this task; run the full file again at the end of Task 9 once `recordExpense`'s signature supports tagging.

- [ ] **Step 2: Run the runnable subset to verify it fails**

Run: `php artisan test --compact --filter="periodFor|periodBounds"`
Expected: FAIL — `App\Services\DebtFocus\BudgetService` doesn't exist.

- [ ] **Step 3: Write `BudgetService`**

Create `app/Services/DebtFocus/BudgetService.php`:

```php
<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class BudgetService
{
    /**
     * @return array{bills: Collection<int, BillPeriod>, envelopes: Collection<int, EnvelopePeriod>, sweepCandidates: Collection<int, EnvelopePeriod>}
     */
    public function periodFor(User $user, int $year, int $month): array
    {
        $isFirstViewOfMonth = ! $this->anyPeriodExists($user, $year, $month);

        $bills = $user->debtFocusBills()->where('active', true)->get()
            ->map(fn (Bill $bill) => BillPeriod::firstOrCreate(
                ['bill_id' => $bill->id, 'year' => $year, 'month' => $month],
                ['expected_amount' => $bill->expected_amount],
            ));

        $envelopes = $user->debtFocusEnvelopes()->where('active', true)->get()
            ->map(fn (Envelope $envelope) => EnvelopePeriod::firstOrCreate(
                ['envelope_id' => $envelope->id, 'year' => $year, 'month' => $month],
                ['target' => $envelope->default_target],
            ));

        $sweepCandidates = $isFirstViewOfMonth
            ? $this->detectSweepCandidates($user, $year, $month)
            : collect();

        return ['bills' => $bills, 'envelopes' => $envelopes, 'sweepCandidates' => $sweepCandidates];
    }

    public function spentForPeriod(EnvelopePeriod $period): float
    {
        [$start, $end] = $this->periodBounds($period->year, $period->month);

        return (float) Transaction::query()
            ->where('envelope_id', $period->envelope_id)
            ->where('type', 'expense')
            ->whereBetween('occurred_at', [$start, $end])
            ->sum('amount');
    }

    /**
     * @return array{0: string, 1: string}
     */
    public function periodBounds(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfMonth();

        return [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()];
    }

    private function anyPeriodExists(User $user, int $year, int $month): bool
    {
        $billPeriodExists = BillPeriod::whereIn('bill_id', $user->debtFocusBills()->pluck('id'))
            ->where('year', $year)->where('month', $month)->exists();

        if ($billPeriodExists) {
            return true;
        }

        return EnvelopePeriod::whereIn('envelope_id', $user->debtFocusEnvelopes()->pluck('id'))
            ->where('year', $year)->where('month', $month)->exists();
    }

    /**
     * @return Collection<int, EnvelopePeriod>
     */
    private function detectSweepCandidates(User $user, int $year, int $month): Collection
    {
        [$prevYear, $prevMonth] = $month === 1 ? [$year - 1, 12] : [$year, $month - 1];

        $candidates = EnvelopePeriod::whereIn('envelope_id', $user->debtFocusEnvelopes()->pluck('id'))
            ->where('year', $prevYear)->where('month', $prevMonth)
            ->whereNull('sweep_offered_at')
            ->get();

        $underspent = $candidates->filter(fn (EnvelopePeriod $period) => (float) $period->target > $this->spentForPeriod($period));

        $underspent->each(fn (EnvelopePeriod $period) => $period->update(['sweep_offered_at' => now()]));

        return $underspent->values();
    }
}
```

- [ ] **Step 4: Run the runnable subset to verify it passes**

Run: `php artisan test --compact --filter="periodFor lazily creates|periodFor does not recreate|periodFor skips inactive|a template edit|periodBounds returns"`
Expected: PASS (5 tests)

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/DebtFocus/BudgetService.php tests/Feature/DebtFocus/BudgetServiceTest.php
git commit -m "feat: add BudgetService for lazy period creation and sweep detection"
```

(The remaining tests in `BudgetServiceTest.php` that call `recordExpense` with an envelope id are verified at the end of Task 9.)

---

## Task 4: `BudgetController::index` — assemble the `/budget` page

**Files:**
- Create: `app/Http/Controllers/BudgetController.php` (this task only adds `index()` and its private helpers; Tasks 5-8 add more methods to this same file)
- Modify: `routes/web.php`
- Test: `tests/Feature/DebtFocus/BudgetControllerTest.php` (this task only adds the `index` tests; Tasks 5-8 append more)

**Interfaces:**
- Consumes: `BudgetService::periodFor()`/`spentForPeriod()`/`periodBounds()` (Task 3), `PayoffEngine::orderByPriority()` (Task 1).
- Produces: `BudgetController::index()`, and private helpers `liveDebts(User): Collection<int,Debt>`, `resolvePriorityLinkedDebt(Collection<int,Debt>, string, PayoffEngine): ?Debt`, `incomeForPeriod(User, BudgetService, int, int): float`, `computeLeftover(float, float, Collection, Collection): float` — reused by Tasks 5-8.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DebtFocus/BudgetControllerTest.php`:

```php
<?php

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\Settings;
use App\Models\User;
use App\Services\DebtFocus\LedgerService;

test('guests are redirected to login', function () {
    $this->get('/budget')->assertRedirect('/login');
});

test('renders empty state for a user with nothing set up', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['debtMinimums'])->toBe([]);
    expect($props['bills'])->toBe([]);
    expect($props['envelopes'])->toBe([]);
    expect($props['sweepCandidates'])->toBe([]);
    expect($props['priorityDebt'])->toBeNull();
    expect((float) $props['leftover'])->toBe(0.0);
});

test('defaults to the current year and month when none are given', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['year'])->toBe((int) now()->year);
    expect($props['month'])->toBe((int) now()->month);
});

test('shows debt minimums pulled live from active debts', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['name' => 'Maya CC', 'minimum_payment' => 75]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $minimums = $response->viewData('page')['props']['debtMinimums'];

    expect($minimums)->toHaveCount(1);
    expect($minimums[0]['name'])->toBe('Maya CC');
    expect((float) $minimums[0]['minimum_payment'])->toBe(75.0);
});

test('shows bill and envelope periods for the requested month', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Electricity', 'expected_amount' => 60]);
    Envelope::factory()->for($user)->create(['name' => 'Groceries', 'default_target' => 200]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();
    $props = $response->viewData('page')['props'];

    expect($props['bills'])->toHaveCount(1);
    expect($props['bills'][0]['name'])->toBe('Electricity');
    expect((float) $props['bills'][0]['expected_amount'])->toBe(60.0);
    expect($props['envelopes'])->toHaveCount(1);
    expect($props['envelopes'][0]['name'])->toBe('Groceries');
    expect((float) $props['envelopes'][0]['target'])->toBe(200.0);
    expect((float) $props['envelopes'][0]['spent'])->toBe(0.0);
});

test('computes leftover as income minus minimums, bills, and envelope targets', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create();
    (new LedgerService)->recordIncome($checking, 3000, 'Paycheck', now()->setDate(2026, 8, 1));
    Debt::factory()->for($user)->create(['minimum_payment' => 200]);
    Bill::factory()->for($user)->create(['expected_amount' => 100]);
    Envelope::factory()->for($user)->create(['default_target' => 500]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect((float) $response->viewData('page')['props']['leftover'])->toBe(2200.0);
});

test('leftover never goes negative when assignments exceed income', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['minimum_payment' => 5000]);

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect((float) $response->viewData('page')['props']['leftover'])->toBe(0.0);
});

test('priorityDebt is the highest-priority debt that has a linked account', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    Debt::factory()->for($user)->create(['name' => 'Unlinked, high rate', 'monthly_rate' => 30, 'linked_account_id' => null]);
    $linkedAccount = Account::factory()->for($user)->liability()->create();
    $linked = Debt::factory()->for($user)->create(['name' => 'Linked, lower rate', 'monthly_rate' => 10, 'linked_account_id' => $linkedAccount->id]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();

    expect($response->viewData('page')['props']['priorityDebt']['id'])->toBe($linked->id);
});

test('priorityDebt is null when no debt has a linked account', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['linked_account_id' => null]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();

    expect($response->viewData('page')['props']['priorityDebt'])->toBeNull();
});

test('bill and envelope templates are listed regardless of active status', function () {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Active bill', 'active' => true]);
    Bill::factory()->for($user)->create(['name' => 'Inactive bill', 'active' => false]);

    $response = $this->actingAs($user)->get('/budget')->assertOk();
    $templates = collect($response->viewData('page')['props']['billTemplates'])->pluck('name');

    expect($templates->all())->toContain('Active bill', 'Inactive bill');
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=BudgetControllerTest`
Expected: FAIL — route `/budget` doesn't exist (404).

- [ ] **Step 3: Write `BudgetController`**

Create `app/Http/Controllers/BudgetController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Bill;
use App\Models\DebtFocus\BillPeriod;
use App\Models\DebtFocus\Debt;
use App\Models\DebtFocus\Envelope;
use App\Models\DebtFocus\EnvelopePeriod;
use App\Models\DebtFocus\Transaction;
use App\Models\User;
use App\Services\DebtFocus\BudgetService;
use App\Services\DebtFocus\PayoffEngine;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

class BudgetController extends Controller
{
    public function index(Request $request, BudgetService $budget, PayoffEngine $engine): Response
    {
        $user = $request->user();
        $year = (int) $request->integer('year', (int) now()->year);
        $month = (int) $request->integer('month', (int) now()->month);

        $period = $budget->periodFor($user, $year, $month);

        $debts = $this->liveDebts($user);
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($debts, $settings->strategy, $engine);

        $minimumsTotal = (float) $debts->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        $income = $this->incomeForPeriod($user, $budget, $year, $month);
        $leftover = $this->computeLeftover($income, $minimumsTotal, $period['bills'], $period['envelopes']);

        $assetAccounts = $user->debtFocusAccounts()->where('is_liability', false)->orderBy('name')->get()
            ->map(fn (Account $account) => ['id' => $account->id, 'name' => $account->name])
            ->values();

        return Inertia::render('Budget', [
            'year' => $year,
            'month' => $month,
            'monthLabel' => Carbon::create($year, $month, 1)->format('F Y'),
            'debtMinimums' => $debts->map(fn (Debt $debt) => [
                'id' => $debt->id,
                'name' => $debt->name,
                'minimum_payment' => (float) $debt->minimum_payment,
            ])->values(),
            'bills' => $period['bills']->map(fn (BillPeriod $billPeriod) => [
                'id' => $billPeriod->id,
                'bill_id' => $billPeriod->bill_id,
                'name' => $billPeriod->bill->name,
                'expected_amount' => (float) $billPeriod->expected_amount,
                'actual_amount' => $billPeriod->actual_amount !== null ? (float) $billPeriod->actual_amount : null,
                'due_day' => $billPeriod->bill->due_day,
                'paid_at' => $billPeriod->paid_at?->format('M j, Y'),
            ])->values(),
            'envelopes' => $period['envelopes']->map(fn (EnvelopePeriod $envelopePeriod) => [
                'id' => $envelopePeriod->id,
                'envelope_id' => $envelopePeriod->envelope_id,
                'name' => $envelopePeriod->envelope->name,
                'icon' => $envelopePeriod->envelope->icon,
                'target' => (float) $envelopePeriod->target,
                'spent' => round($budget->spentForPeriod($envelopePeriod), 2),
            ])->values(),
            'sweepCandidates' => $period['sweepCandidates']->map(fn (EnvelopePeriod $envelopePeriod) => [
                'id' => $envelopePeriod->id,
                'envelope_name' => $envelopePeriod->envelope->name,
                'freed_amount' => round((float) $envelopePeriod->target - $budget->spentForPeriod($envelopePeriod), 2),
            ])->values(),
            'leftover' => round($leftover, 2),
            'priorityDebt' => $priorityDebt !== null ? ['id' => $priorityDebt->id, 'name' => $priorityDebt->name] : null,
            'assetAccounts' => $assetAccounts,
            'billTemplates' => $user->debtFocusBills()->orderBy('name')->get()->map(fn (Bill $bill) => [
                'id' => $bill->id,
                'name' => $bill->name,
                'expected_amount' => (float) $bill->expected_amount,
                'due_day' => $bill->due_day,
                'active' => $bill->active,
            ])->values(),
            'envelopeTemplates' => $user->debtFocusEnvelopes()->orderBy('name')->get()->map(fn (Envelope $envelope) => [
                'id' => $envelope->id,
                'name' => $envelope->name,
                'icon' => $envelope->icon,
                'default_target' => (float) $envelope->default_target,
                'active' => $envelope->active,
            ])->values(),
        ]);
    }

    /**
     * @return EloquentCollection<int, Debt>
     */
    private function liveDebts(User $user): EloquentCollection
    {
        $debts = $user->debtFocusDebts()->with('linkedAccount')->get();

        foreach ($debts as $debt) {
            if ($debt->linkedAccount !== null) {
                $debt->balance = $debt->linkedAccount->balance;
            }
        }

        return $debts;
    }

    /**
     * @param  Collection<int, Debt>  $debts
     */
    private function resolvePriorityLinkedDebt(Collection $debts, string $strategy, PayoffEngine $engine): ?Debt
    {
        foreach ($engine->orderByPriority($debts, $strategy) as $debt) {
            if ($debt->linked_account_id !== null) {
                return $debt;
            }
        }

        return null;
    }

    private function incomeForPeriod(User $user, BudgetService $budget, int $year, int $month): float
    {
        [$start, $end] = $budget->periodBounds($year, $month);

        return (float) Transaction::query()
            ->where('user_id', $user->id)
            ->where('type', 'income')
            ->whereBetween('occurred_at', [$start, $end])
            ->sum('amount');
    }

    /**
     * @param  Collection<int, BillPeriod>  $billPeriods
     * @param  Collection<int, EnvelopePeriod>  $envelopePeriods
     */
    private function computeLeftover(float $income, float $minimumsTotal, Collection $billPeriods, Collection $envelopePeriods): float
    {
        $billsTotal = (float) $billPeriods->sum(fn (BillPeriod $billPeriod) => (float) $billPeriod->expected_amount);
        $envelopesTotal = (float) $envelopePeriods->sum(fn (EnvelopePeriod $envelopePeriod) => (float) $envelopePeriod->target);

        return max(0.0, $income - $minimumsTotal - $billsTotal - $envelopesTotal);
    }
}
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add the import:

```php
use App\Http\Controllers\BudgetController;
```

Add this route right after the `payoff-plan.settings.update` line:

```php
    Route::get('/budget', [BudgetController::class, 'index'])->name('budget.index');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=BudgetControllerTest`
Expected: PASS (10 tests)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/BudgetController.php routes/web.php tests/Feature/DebtFocus/BudgetControllerTest.php
git commit -m "feat: add /budget page assembling debt minimums, bills, and envelopes"
```

---

## Task 5: Bill template CRUD

**Files:**
- Modify: `app/Http/Controllers/BudgetController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DebtFocus/BudgetControllerTest.php`

**Interfaces:**
- Produces: `BudgetController::storeBill()`, `updateBill()`, `destroyBill()`, private `billRules(): array`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DebtFocus/BudgetControllerTest.php`:

```php
test('stores a new bill for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/bills', [
        'name' => 'Internet', 'expected_amount' => 45, 'due_day' => 15, 'active' => true,
    ])->assertRedirect();

    $bill = Bill::where('user_id', $user->id)->firstOrFail();
    expect($bill->name)->toBe('Internet');
    expect((float) $bill->expected_amount)->toBe(45.0);
});

test('storing a bill requires a name and a positive expected amount', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/bills', [
        'expected_amount' => 0, 'active' => true,
    ])->assertSessionHasErrors(['name', 'expected_amount']);
});

test('updates an owned bill including deactivating it', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['name' => 'Old name', 'active' => true]);

    $this->actingAs($user)->patch("/budget/bills/{$bill->id}", [
        'name' => 'New name', 'expected_amount' => 90, 'due_day' => null, 'active' => false,
    ])->assertRedirect();

    $bill->refresh();
    expect($bill->name)->toBe('New name');
    expect($bill->active)->toBeFalse();
});

test('cannot update another user\'s bill', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $bill = Bill::factory()->for($owner)->create();

    $this->actingAs($intruder)->patch("/budget/bills/{$bill->id}", [
        'name' => 'Hacked', 'expected_amount' => 1, 'active' => true,
    ])->assertForbidden();
});

test('deletes an owned bill', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create();

    $this->actingAs($user)->delete("/budget/bills/{$bill->id}")->assertRedirect();

    expect(Bill::find($bill->id))->toBeNull();
});

test('cannot delete another user\'s bill', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $bill = Bill::factory()->for($owner)->create();

    $this->actingAs($intruder)->delete("/budget/bills/{$bill->id}")->assertForbidden();

    expect(Bill::find($bill->id))->not->toBeNull();
});

test('a deactivated bill stops getting new period rows but keeps old ones', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create(['active' => true]);
    $this->actingAs($user)->get('/budget?year=2026&month=7')->assertOk();

    $this->actingAs($user)->patch("/budget/bills/{$bill->id}", [
        'name' => $bill->name, 'expected_amount' => (float) $bill->expected_amount, 'due_day' => $bill->due_day, 'active' => false,
    ])->assertRedirect();

    $response = $this->actingAs($user)->get('/budget?year=2026&month=8')->assertOk();

    expect($response->viewData('page')['props']['bills'])->toBe([]);
    expect(\App\Models\DebtFocus\BillPeriod::where('bill_id', $bill->id)->where('month', 7)->exists())->toBeTrue();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="stores a new bill|storing a bill requires|updates an owned bill|cannot update another user's bill|deletes an owned bill|cannot delete another user's bill|a deactivated bill"`
Expected: FAIL — routes don't exist (404).

- [ ] **Step 3: Add the methods to `BudgetController`**

First add `use Illuminate\Http\RedirectResponse;` to the imports.

Add these public methods (after `index()`):

```php
    public function storeBill(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->billRules());

        $request->user()->debtFocusBills()->create($validated);

        return back();
    }

    public function updateBill(Request $request, Bill $bill): RedirectResponse
    {
        abort_if($bill->user_id !== $request->user()->id, 403);

        $bill->update($request->validate($this->billRules()));

        return back();
    }

    public function destroyBill(Request $request, Bill $bill): RedirectResponse
    {
        abort_if($bill->user_id !== $request->user()->id, 403);

        $bill->delete();

        return back();
    }
```

Add this private method at the bottom of the class:

```php
    /**
     * @return array<string, array<int, mixed>>
     */
    private function billRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'expected_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'due_day' => ['nullable', 'integer', 'min:1', 'max:31'],
            'active' => ['required', 'boolean'],
        ];
    }
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add after `budget.index`:

```php
    Route::post('/budget/bills', [BudgetController::class, 'storeBill'])->name('budget.bills.store');
    Route::patch('/budget/bills/{bill}', [BudgetController::class, 'updateBill'])->name('budget.bills.update');
    Route::delete('/budget/bills/{bill}', [BudgetController::class, 'destroyBill'])->name('budget.bills.destroy');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=BudgetControllerTest`
Expected: PASS (all tests so far)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/BudgetController.php routes/web.php tests/Feature/DebtFocus/BudgetControllerTest.php
git commit -m "feat: add bill template CRUD to BudgetController"
```

---

## Task 6: Envelope template CRUD

**Files:**
- Modify: `app/Http/Controllers/BudgetController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DebtFocus/BudgetControllerTest.php`

**Interfaces:**
- Produces: `BudgetController::storeEnvelope()`, `updateEnvelope()`, `destroyEnvelope()`, private `envelopeRules(): array`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DebtFocus/BudgetControllerTest.php`:

```php
test('stores a new envelope for the authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/envelopes', [
        'name' => 'Groceries', 'icon' => 'shopping_cart', 'default_target' => 250, 'active' => true,
    ])->assertRedirect();

    $envelope = Envelope::where('user_id', $user->id)->firstOrFail();
    expect($envelope->name)->toBe('Groceries');
    expect((float) $envelope->default_target)->toBe(250.0);
});

test('storing an envelope requires a name and a positive target', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->post('/budget/envelopes', [
        'default_target' => 0, 'active' => true,
    ])->assertSessionHasErrors(['name', 'default_target']);
});

test('updates an owned envelope', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create(['name' => 'Old name']);

    $this->actingAs($user)->patch("/budget/envelopes/{$envelope->id}", [
        'name' => 'New name', 'icon' => null, 'default_target' => 300, 'active' => true,
    ])->assertRedirect();

    expect($envelope->fresh()->name)->toBe('New name');
});

test('cannot update another user\'s envelope', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $envelope = Envelope::factory()->for($owner)->create();

    $this->actingAs($intruder)->patch("/budget/envelopes/{$envelope->id}", [
        'name' => 'Hacked', 'default_target' => 1, 'active' => true,
    ])->assertForbidden();
});

test('deletes an owned envelope', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create();

    $this->actingAs($user)->delete("/budget/envelopes/{$envelope->id}")->assertRedirect();

    expect(Envelope::find($envelope->id))->toBeNull();
});

test('cannot delete another user\'s envelope', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $envelope = Envelope::factory()->for($owner)->create();

    $this->actingAs($intruder)->delete("/budget/envelopes/{$envelope->id}")->assertForbidden();

    expect(Envelope::find($envelope->id))->not->toBeNull();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="stores a new envelope|storing an envelope requires|updates an owned envelope|cannot update another user's envelope|deletes an owned envelope|cannot delete another user's envelope"`
Expected: FAIL — routes don't exist (404).

- [ ] **Step 3: Add the methods to `BudgetController`**

Add these public methods (after `destroyBill()`):

```php
    public function storeEnvelope(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->envelopeRules());

        $request->user()->debtFocusEnvelopes()->create($validated);

        return back();
    }

    public function updateEnvelope(Request $request, Envelope $envelope): RedirectResponse
    {
        abort_if($envelope->user_id !== $request->user()->id, 403);

        $envelope->update($request->validate($this->envelopeRules()));

        return back();
    }

    public function destroyEnvelope(Request $request, Envelope $envelope): RedirectResponse
    {
        abort_if($envelope->user_id !== $request->user()->id, 403);

        $envelope->delete();

        return back();
    }
```

Add this private method next to `billRules()`:

```php
    /**
     * @return array<string, array<int, mixed>>
     */
    private function envelopeRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'icon' => ['nullable', 'string', 'max:50'],
            'default_target' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
            'active' => ['required', 'boolean'],
        ];
    }
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add after the bill routes:

```php
    Route::post('/budget/envelopes', [BudgetController::class, 'storeEnvelope'])->name('budget.envelopes.store');
    Route::patch('/budget/envelopes/{envelope}', [BudgetController::class, 'updateEnvelope'])->name('budget.envelopes.update');
    Route::delete('/budget/envelopes/{envelope}', [BudgetController::class, 'destroyEnvelope'])->name('budget.envelopes.destroy');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=BudgetControllerTest`
Expected: PASS (all tests so far)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/BudgetController.php routes/web.php tests/Feature/DebtFocus/BudgetControllerTest.php
git commit -m "feat: add envelope template CRUD to BudgetController"
```

---

## Task 7: Mark a bill period paid

**Files:**
- Modify: `app/Http/Controllers/BudgetController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DebtFocus/BudgetControllerTest.php`

**Interfaces:**
- Produces: `BudgetController::markBillPaid()`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DebtFocus/BudgetControllerTest.php`:

```php
test('marks a bill period paid with the actual amount', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create();
    $period = \App\Models\DebtFocus\BillPeriod::factory()->for($bill)->create(['actual_amount' => null, 'paid_at' => null]);

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 62.5,
    ])->assertRedirect();

    $period->refresh();
    expect((float) $period->actual_amount)->toBe(62.5);
    expect($period->paid_at)->not->toBeNull();
});

test('marking a bill paid requires a positive actual amount', function () {
    $user = User::factory()->create();
    $bill = Bill::factory()->for($user)->create();
    $period = \App\Models\DebtFocus\BillPeriod::factory()->for($bill)->create();

    $this->actingAs($user)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 0,
    ])->assertSessionHasErrors('actual_amount');
});

test('cannot mark another user\'s bill period paid', function () {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $bill = Bill::factory()->for($owner)->create();
    $period = \App\Models\DebtFocus\BillPeriod::factory()->for($bill)->create();

    $this->actingAs($intruder)->post("/budget/bill-periods/{$period->id}/pay", [
        'actual_amount' => 10,
    ])->assertForbidden();
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="marks a bill period paid|marking a bill paid requires|cannot mark another user's bill period paid"`
Expected: FAIL — route doesn't exist (404).

- [ ] **Step 3: Add the method to `BudgetController`**

Add after `destroyEnvelope()`:

```php
    public function markBillPaid(Request $request, BillPeriod $billPeriod): RedirectResponse
    {
        abort_if($billPeriod->bill->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'actual_amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.99'],
        ]);

        $billPeriod->update([
            'actual_amount' => $validated['actual_amount'],
            'paid_at' => now(),
        ]);

        return back();
    }
```

- [ ] **Step 4: Register the route**

In `routes/web.php`, add after the envelope routes:

```php
    Route::post('/budget/bill-periods/{billPeriod}/pay', [BudgetController::class, 'markBillPaid'])->name('budget.bill-periods.pay');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=BudgetControllerTest`
Expected: PASS (all tests so far)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/BudgetController.php routes/web.php tests/Feature/DebtFocus/BudgetControllerTest.php
git commit -m "feat: add markBillPaid action to BudgetController"
```

---

## Task 8: Sweep confirm/decline and extra-payment confirm

**Files:**
- Modify: `app/Http/Controllers/BudgetController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DebtFocus/BudgetControllerTest.php`

**Interfaces:**
- Consumes: `LedgerService::recordTransfer(Account $from, Account $to, float $amount, ?string $description, ?CarbonInterface $occurredAt): Transaction` (existing).
- Produces: `BudgetController::confirmSweep()`, `declineSweep()`, `confirmExtraPayment()`, private `assetAccountOwnershipRule(int): Exists`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DebtFocus/BudgetControllerTest.php`:

```php
test('confirming a sweep transfers the freed amount to the priority linked debt', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    $debt = Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'monthly_rate' => 20]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = \App\Models\DebtFocus\EnvelopePeriod::factory()->for($envelope)->create(['target' => 100, 'sweep_offered_at' => now()]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $checking->id,
    ])->assertRedirect();

    expect((float) $checking->fresh()->balance)->toBe(900.0);
    expect((float) $card->fresh()->balance)->toBe(400.0);
    expect($period->fresh()->sweep_accepted)->toBeTrue();
});

test('confirming a sweep with a liability account as the source is rejected', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $card = Account::factory()->for($user)->liability()->create();
    $debt = Debt::factory()->for($user)->create(['linked_account_id' => $card->id]);
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = \App\Models\DebtFocus\EnvelopePeriod::factory()->for($envelope)->create(['target' => 100]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $card->id,
    ])->assertSessionHasErrors('from_account_id');
});

test('confirming a sweep with no linked debt anywhere is rejected', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create();
    $envelope = Envelope::factory()->for($user)->create(['default_target' => 100]);
    $period = \App\Models\DebtFocus\EnvelopePeriod::factory()->for($envelope)->create(['target' => 100]);

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/confirm", [
        'from_account_id' => $checking->id,
    ])->assertStatus(422);
});

test('declining a sweep just records the decision with no transfer', function () {
    $user = User::factory()->create();
    $envelope = Envelope::factory()->for($user)->create();
    $period = \App\Models\DebtFocus\EnvelopePeriod::factory()->for($envelope)->create();

    $this->actingAs($user)->post("/budget/envelope-periods/{$period->id}/sweep/decline")->assertRedirect();

    expect($period->fresh()->sweep_accepted)->toBeFalse();
    expect(\App\Models\DebtFocus\Transaction::count())->toBe(0);
});

test('confirming the extra payment transfers the leftover to the priority linked debt', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    (new LedgerService)->recordIncome($checking, 2000, 'Paycheck', now()->setDate(2026, 8, 1));
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'minimum_payment' => 200]);

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => 2026, 'month' => 8,
    ])->assertRedirect();

    // leftover = income (2000) - minimumsTotal (200) - bills (0) - envelopes (0) = 1800
    expect((float) $card->fresh()->balance)->toBe(500.0 - 1800.0);
});

test('confirming the extra payment with nothing leftover is rejected', function () {
    $user = User::factory()->create();
    Settings::factory()->for($user)->create(['strategy' => 'avalanche']);
    $checking = Account::factory()->for($user)->create();
    $card = Account::factory()->for($user)->liability()->create();
    Debt::factory()->for($user)->create(['linked_account_id' => $card->id, 'minimum_payment' => 200]);

    $this->actingAs($user)->post('/budget/extra-payment/confirm', [
        'from_account_id' => $checking->id, 'year' => 2026, 'month' => 8,
    ])->assertStatus(422);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="confirming a sweep|declining a sweep|confirming the extra payment"`
Expected: FAIL — routes don't exist (404).

- [ ] **Step 3: Add the methods to `BudgetController`**

Add `use App\Services\DebtFocus\LedgerService;` and `use Illuminate\Validation\Rule;` and `use Illuminate\Validation\Rules\Exists;` to the imports.

Add after `markBillPaid()`:

```php
    public function confirmSweep(Request $request, EnvelopePeriod $envelopePeriod, BudgetService $budget, PayoffEngine $engine, LedgerService $ledger): RedirectResponse
    {
        abort_if($envelopePeriod->envelope->user_id !== $request->user()->id, 403);

        $user = $request->user();
        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', $this->assetAccountOwnershipRule($user->id)],
        ]);

        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($this->liveDebts($user), $settings->strategy, $engine);

        abort_if($priorityDebt === null, 422, 'No linked debt available for this sweep.');

        $amount = round((float) $envelopePeriod->target - $budget->spentForPeriod($envelopePeriod), 2);
        abort_if($amount <= 0.0, 422, 'Nothing to sweep for this envelope.');

        $fromAccount = Account::findOrFail($validated['from_account_id']);
        $ledger->recordTransfer($fromAccount, $priorityDebt->linkedAccount, $amount, "Envelope sweep: {$envelopePeriod->envelope->name}");

        $envelopePeriod->update(['sweep_accepted' => true]);

        return back();
    }

    public function declineSweep(Request $request, EnvelopePeriod $envelopePeriod): RedirectResponse
    {
        abort_if($envelopePeriod->envelope->user_id !== $request->user()->id, 403);

        $envelopePeriod->update(['sweep_accepted' => false]);

        return back();
    }

    public function confirmExtraPayment(Request $request, BudgetService $budget, PayoffEngine $engine, LedgerService $ledger): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'from_account_id' => ['required', 'integer', $this->assetAccountOwnershipRule($user->id)],
            'year' => ['required', 'integer', 'min:2000'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
        ]);

        $debts = $this->liveDebts($user);
        $settings = $user->debtFocusSettings()->firstOrCreate([], ['strategy' => 'avalanche', 'extra_monthly' => 0]);
        $priorityDebt = $this->resolvePriorityLinkedDebt($debts, $settings->strategy, $engine);

        abort_if($priorityDebt === null, 422, 'No linked debt available for the extra payment.');

        $period = $budget->periodFor($user, (int) $validated['year'], (int) $validated['month']);
        $minimumsTotal = (float) $debts->sum(fn (Debt $debt) => (float) $debt->minimum_payment);
        $income = $this->incomeForPeriod($user, $budget, (int) $validated['year'], (int) $validated['month']);
        $leftover = $this->computeLeftover($income, $minimumsTotal, $period['bills'], $period['envelopes']);

        abort_if($leftover <= 0.0, 422, 'No leftover amount to send.');

        $fromAccount = Account::findOrFail($validated['from_account_id']);
        $ledger->recordTransfer($fromAccount, $priorityDebt->linkedAccount, round($leftover, 2), 'Extra payment from leftover budget');

        return back();
    }
```

Add this private method next to `envelopeRules()`:

```php
    private function assetAccountOwnershipRule(int $userId): Exists
    {
        return Rule::exists('debt_focus_accounts', 'id')->where('user_id', $userId)->where('is_liability', false);
    }
```

- [ ] **Step 4: Register the routes**

In `routes/web.php`, add after `budget.bill-periods.pay`:

```php
    Route::post('/budget/envelope-periods/{envelopePeriod}/sweep/confirm', [BudgetController::class, 'confirmSweep'])->name('budget.envelope-periods.sweep.confirm');
    Route::post('/budget/envelope-periods/{envelopePeriod}/sweep/decline', [BudgetController::class, 'declineSweep'])->name('budget.envelope-periods.sweep.decline');
    Route::post('/budget/extra-payment/confirm', [BudgetController::class, 'confirmExtraPayment'])->name('budget.extra-payment.confirm');
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=BudgetControllerTest`
Expected: PASS (all tests)

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Http/Controllers/BudgetController.php routes/web.php tests/Feature/DebtFocus/BudgetControllerTest.php
git commit -m "feat: add sweep and extra-payment confirmation to BudgetController"
```

---

## Task 9: Tag ledger expenses with an envelope

**Files:**
- Modify: `app/Services/DebtFocus/LedgerService.php`
- Modify: `app/Http/Controllers/LedgerController.php`
- Test: `tests/Feature/DebtFocus/LedgerServiceTest.php`
- Test: `tests/Feature/DebtFocus/LedgerControllerTest.php`

**Interfaces:**
- Produces: `LedgerService::recordExpense(Account $account, float $amount, ?string $description = null, ?CarbonInterface $occurredAt = null, ?int $envelopeId = null): Transaction` (new trailing param, backward compatible). `LedgerController::index()` now also renders an `envelopes` prop.
- This is the change `BudgetServiceTest.php` (Task 3) and `BudgetControllerTest.php` (Tasks 4-8) were written against — running the full suite after this task confirms everything wires together.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DebtFocus/LedgerServiceTest.php`:

```php
test('recordExpense tags the transaction with an envelope id when given one', function () {
    $account = Account::factory()->create(['balance' => 200]);
    $envelope = \App\Models\DebtFocus\Envelope::factory()->create(['user_id' => $account->user_id]);

    $transaction = (new LedgerService)->recordExpense($account, 30, 'Groceries', null, $envelope->id);

    expect($transaction->envelope_id)->toBe($envelope->id);
});

test('recordExpense leaves envelope_id null when none is given', function () {
    $account = Account::factory()->create(['balance' => 200]);

    $transaction = (new LedgerService)->recordExpense($account, 30, 'Groceries');

    expect($transaction->envelope_id)->toBeNull();
});
```

Append to `tests/Feature/DebtFocus/LedgerControllerTest.php`:

```php
test('logging an expense with an owned envelope tags the transaction', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100]);
    $envelope = \App\Models\DebtFocus\Envelope::factory()->for($user)->create();

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
    $envelope = \App\Models\DebtFocus\Envelope::factory()->for($owner)->create();

    $this->actingAs($intruder)->post('/ledger/transactions', [
        'type' => 'expense', 'account_id' => $account->id, 'amount' => 20,
        'occurred_at' => '2026-08-28', 'envelope_id' => $envelope->id,
    ])->assertSessionHasErrors('envelope_id');
});

test('envelope_id is rejected on income and transfer transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['balance' => 100]);
    $envelope = \App\Models\DebtFocus\Envelope::factory()->for($user)->create();

    $this->actingAs($user)->post('/ledger/transactions', [
        'type' => 'income', 'account_id' => $account->id, 'amount' => 20,
        'occurred_at' => '2026-08-28', 'envelope_id' => $envelope->id,
    ])->assertSessionHasErrors('envelope_id');
});

test('the ledger page exposes the active envelope list', function () {
    $user = User::factory()->create();
    \App\Models\DebtFocus\Envelope::factory()->for($user)->create(['name' => 'Groceries', 'active' => true]);
    \App\Models\DebtFocus\Envelope::factory()->for($user)->create(['name' => 'Old one', 'active' => false]);

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $envelopes = collect($response->viewData('page')['props']['envelopes'])->pluck('name');

    expect($envelopes->all())->toBe(['Groceries']);
});
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter="recordExpense tags|recordExpense leaves|logging an expense with an owned envelope|cannot tag a transaction|envelope_id is rejected|the ledger page exposes"`
Expected: FAIL — `recordExpense()` doesn't accept a 5th argument, `envelope_id` isn't validated, `envelopes` prop doesn't exist.

- [ ] **Step 3: Update `LedgerService::recordExpense`**

In `app/Services/DebtFocus/LedgerService.php`, change the signature and body:

```php
    public function recordExpense(Account $account, float $amount, ?string $description = null, ?CarbonInterface $occurredAt = null, ?int $envelopeId = null): Transaction
    {
        return DB::transaction(function () use ($account, $amount, $description, $occurredAt, $envelopeId) {
            $this->applyEffect($account, $amount, positive: false);

            return Transaction::create([
                'user_id' => $account->user_id,
                'type' => 'expense',
                'account_id' => $account->id,
                'amount' => $amount,
                'description' => $description,
                'occurred_at' => $occurredAt ?? now(),
                'envelope_id' => $envelopeId,
            ]);
        });
    }
```

- [ ] **Step 4: Update `LedgerController`**

In `app/Http/Controllers/LedgerController.php`, add `use App\Models\DebtFocus\Envelope;` to the imports.

In `index()`, change the render call:

```php
        $envelopes = $request->user()->debtFocusEnvelopes()->where('active', true)->orderBy('name')->get()
            ->map(fn (Envelope $envelope) => ['id' => $envelope->id, 'name' => $envelope->name])
            ->values();

        return Inertia::render('Ledger', [
            'accounts' => $accountProps,
            'envelopes' => $envelopes,
        ]);
```

In `storeTransaction()`, add `envelope_id` to the validation rules (after `to_account_id`):

```php
            'envelope_id' => ['nullable', 'integer', 'prohibited_unless:type,expense', $this->envelopeOwnershipRule($request->user()->id)],
```

Change the `match` block's `expense` arm to pass it through:

```php
            'expense' => $ledger->recordExpense(Account::findOrFail($validated['account_id']), $amount, $description, $occurredAt, $validated['envelope_id'] ?? null),
```

Add this private method next to `accountOwnershipRule()`:

```php
    private function envelopeOwnershipRule(int $userId): Exists
    {
        return Rule::exists('debt_focus_envelopes', 'id')->where('user_id', $userId);
    }
```

- [ ] **Step 5: Run this task's tests to verify they pass**

Run: `php artisan test --compact --filter="recordExpense tags|recordExpense leaves|logging an expense with an owned envelope|cannot tag a transaction|envelope_id is rejected|the ledger page exposes"`
Expected: PASS

- [ ] **Step 6: Run the full DebtFocus suite, including the tests deferred from Tasks 3-8**

Run: `php artisan test --compact tests/Feature/DebtFocus`
Expected: PASS — this is the first run where `BudgetServiceTest.php`'s `spentForPeriod`/sweep tests (which call `recordExpense` with a 5th argument) and every `BudgetControllerTest.php` test execute together end to end.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/DebtFocus/LedgerService.php app/Http/Controllers/LedgerController.php tests/Feature/DebtFocus/LedgerServiceTest.php tests/Feature/DebtFocus/LedgerControllerTest.php
git commit -m "feat: tag ledger expenses with an envelope"
```

---

## Task 10: `Budget.vue` page and sidebar entry

**Files:**
- Create: `resources/js/pages/Budget.vue`
- Modify: `resources/js/components/AppSidebar.vue`

**Interfaces:**
- Consumes: the Inertia props shape produced by `BudgetController::index()` (Task 4) and the wayfinder-generated actions for every `BudgetController` method (Tasks 4-8).

- [ ] **Step 1: Add the sidebar entry**

In `resources/js/components/AppSidebar.vue`, add to the `'Money'` section's `items` array, right after the `Ledger` entry:

```ts
            { title: 'Budget', href: '/budget', icon: 'wallet' },
```

- [ ] **Step 2: Generate the wayfinder actions**

Run: `php artisan wayfinder:generate`
Expected: creates/updates `resources/js/actions/App/Http/Controllers/BudgetController.ts` with typed functions for `index`, `storeBill`, `updateBill`, `destroyBill`, `markBillPaid`, `storeEnvelope`, `updateEnvelope`, `destroyEnvelope`, `confirmSweep`, `declineSweep`, `confirmExtraPayment`.

- [ ] **Step 3: Write `Budget.vue`**

Create `resources/js/pages/Budget.vue`:

```vue
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import {
    confirmExtraPayment,
    confirmSweep,
    declineSweep,
    destroyBill,
    destroyEnvelope,
    markBillPaid,
    storeBill,
    storeEnvelope,
    updateBill,
    updateEnvelope,
} from '@/actions/App/Http/Controllers/BudgetController';

type DebtMinimum = { id: number; name: string; minimum_payment: number };
type BillRow = {
    id: number;
    bill_id: number;
    name: string;
    expected_amount: number;
    actual_amount: number | null;
    due_day: number | null;
    paid_at: string | null;
};
type EnvelopeRow = { id: number; envelope_id: number; name: string; icon: string | null; target: number; spent: number };
type SweepCandidate = { id: number; envelope_name: string; freed_amount: number };
type PriorityDebt = { id: number; name: string } | null;
type AssetAccount = { id: number; name: string };
type BillTemplate = { id: number; name: string; expected_amount: number; due_day: number | null; active: boolean };
type EnvelopeTemplate = { id: number; name: string; icon: string | null; default_target: number; active: boolean };

const props = defineProps<{
    year: number;
    month: number;
    monthLabel: string;
    debtMinimums: DebtMinimum[];
    bills: BillRow[];
    envelopes: EnvelopeRow[];
    sweepCandidates: SweepCandidate[];
    leftover: number;
    priorityDebt: PriorityDebt;
    assetAccounts: AssetAccount[];
    billTemplates: BillTemplate[];
    envelopeTemplates: EnvelopeTemplate[];
}>();

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return n < 0 ? `-${formatted}` : formatted;
}

// ── Month navigation ─────────────────────────────────────────────────────

function goToMonth(year: number, month: number): void {
    router.get('/budget', { year, month }, { preserveScroll: true });
}

function previousMonth(): void {
    const month = props.month === 1 ? 12 : props.month - 1;
    const year = props.month === 1 ? props.year - 1 : props.year;
    goToMonth(year, month);
}

function nextMonth(): void {
    const month = props.month === 12 ? 1 : props.month + 1;
    const year = props.month === 12 ? props.year + 1 : props.year;
    goToMonth(year, month);
}

// ── Manage bill templates ────────────────────────────────────────────────

const billsManagerOpen = ref(false);
const editingBillId = ref<number | 'new' | null>(null);
const billForm = useForm({
    name: '',
    expected_amount: 0,
    due_day: null as number | null,
    active: true,
});

function openAddBill(): void {
    editingBillId.value = 'new';
    billForm.clearErrors();
    billForm.name = '';
    billForm.expected_amount = 0;
    billForm.due_day = null;
    billForm.active = true;
}

function openEditBill(bill: BillTemplate): void {
    editingBillId.value = bill.id;
    billForm.clearErrors();
    billForm.name = bill.name;
    billForm.expected_amount = bill.expected_amount;
    billForm.due_day = bill.due_day;
    billForm.active = bill.active;
}

function cancelBillForm(): void {
    editingBillId.value = null;
    billForm.clearErrors();
}

function submitBillForm(): void {
    if (editingBillId.value === 'new') {
        billForm.post(storeBill().url, { preserveScroll: true, onSuccess: () => (editingBillId.value = null) });
    } else if (editingBillId.value !== null) {
        billForm.patch(updateBill(editingBillId.value).url, { preserveScroll: true, onSuccess: () => (editingBillId.value = null) });
    }
}

function removeBill(bill: BillTemplate): void {
    if (!window.confirm(`Delete ${bill.name}? This month's entry and its history will be removed too.`)) {
        return;
    }

    router.delete(destroyBill(bill.id).url, { preserveScroll: true });
}

// ── Manage envelope templates ────────────────────────────────────────────

const envelopesManagerOpen = ref(false);
const editingEnvelopeId = ref<number | 'new' | null>(null);
const envelopeForm = useForm({
    name: '',
    icon: null as string | null,
    default_target: 0,
    active: true,
});

function openAddEnvelope(): void {
    editingEnvelopeId.value = 'new';
    envelopeForm.clearErrors();
    envelopeForm.name = '';
    envelopeForm.icon = null;
    envelopeForm.default_target = 0;
    envelopeForm.active = true;
}

function openEditEnvelope(envelope: EnvelopeTemplate): void {
    editingEnvelopeId.value = envelope.id;
    envelopeForm.clearErrors();
    envelopeForm.name = envelope.name;
    envelopeForm.icon = envelope.icon;
    envelopeForm.default_target = envelope.default_target;
    envelopeForm.active = envelope.active;
}

function cancelEnvelopeForm(): void {
    editingEnvelopeId.value = null;
    envelopeForm.clearErrors();
}

function submitEnvelopeForm(): void {
    if (editingEnvelopeId.value === 'new') {
        envelopeForm.post(storeEnvelope().url, { preserveScroll: true, onSuccess: () => (editingEnvelopeId.value = null) });
    } else if (editingEnvelopeId.value !== null) {
        envelopeForm.patch(updateEnvelope(editingEnvelopeId.value).url, { preserveScroll: true, onSuccess: () => (editingEnvelopeId.value = null) });
    }
}

function removeEnvelope(envelope: EnvelopeTemplate): void {
    if (!window.confirm(`Delete ${envelope.name}? Past transactions tagged with it will keep their history but lose the tag.`)) {
        return;
    }

    router.delete(destroyEnvelope(envelope.id).url, { preserveScroll: true });
}

// ── Mark a bill paid ─────────────────────────────────────────────────────

const payingBillId = ref<number | null>(null);
const payForm = useForm({ actual_amount: 0 });

function openPayBill(bill: BillRow): void {
    payingBillId.value = bill.id;
    payForm.clearErrors();
    payForm.actual_amount = bill.expected_amount;
}

function cancelPayBill(): void {
    payingBillId.value = null;
    payForm.clearErrors();
}

function submitPayBill(billId: number): void {
    payForm.post(markBillPaid(billId).url, { preserveScroll: true, onSuccess: () => (payingBillId.value = null) });
}

// ── Sweep nudges and the extra-payment suggestion ────────────────────────

const sweepAccountId = ref<number | null>(props.assetAccounts[0]?.id ?? null);
const extraPaymentAccountId = ref<number | null>(props.assetAccounts[0]?.id ?? null);

function confirmSweepFor(candidate: SweepCandidate): void {
    if (sweepAccountId.value === null) {
        return;
    }

    router.post(confirmSweep(candidate.id).url, { from_account_id: sweepAccountId.value }, { preserveScroll: true });
}

function declineSweepFor(candidate: SweepCandidate): void {
    router.post(declineSweep(candidate.id).url, {}, { preserveScroll: true });
}

function confirmExtraPaymentNow(): void {
    if (extraPaymentAccountId.value === null) {
        return;
    }

    router.post(
        confirmExtraPayment().url,
        { from_account_id: extraPaymentAccountId.value, year: props.year, month: props.month },
        { preserveScroll: true },
    );
}
</script>

<template>
    <Head title="Budget" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Header -->
        <div class="mb-5 flex items-center justify-between">
            <div>
                <h1 class="font-['Bricolage_Grotesque'] text-[28px] font-bold" style="color: var(--hab-ink); letter-spacing: -.02em">
                    Budget
                </h1>
                <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">Every dollar assigned, debt first</p>
            </div>
            <div class="flex items-center gap-3">
                <button type="button" class="material-symbols-rounded" style="font-size: 20px; color: var(--hab-faint)" @click="previousMonth">
                    chevron_left
                </button>
                <span class="min-w-[110px] text-center text-[14px] font-medium" style="color: var(--hab-ink)">{{ props.monthLabel }}</span>
                <button type="button" class="material-symbols-rounded" style="font-size: 20px; color: var(--hab-faint)" @click="nextMonth">
                    chevron_right
                </button>
            </div>
        </div>

        <!-- Sweep nudges -->
        <div v-for="candidate in props.sweepCandidates" :key="candidate.id" class="mb-4 rounded-[22px] p-5"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <p class="text-[14px]" style="color: var(--hab-ink)">
                You freed {{ formatMoney(candidate.freed_amount) }} in {{ candidate.envelope_name }} — send it to
                <span class="font-semibold">{{ props.priorityDebt?.name }}</span>?
            </p>
            <div class="mt-2 flex items-center gap-2">
                <select v-model.number="sweepAccountId" class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                    style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                    <option v-for="account in props.assetAccounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                </select>
                <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                    @click="confirmSweepFor(candidate)">
                    Send it
                </button>
                <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)"
                    @click="declineSweepFor(candidate)">
                    Keep in envelope
                </button>
            </div>
        </div>

        <!-- Debt minimums -->
        <div class="mb-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <h2 class="mb-3 font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Debt minimums</h2>
            <p v-if="props.debtMinimums.length === 0" class="text-[14px]" style="color: var(--hab-muted)">
                No active debts. Add one on <a href="/payoff-plan" class="underline">Payoff Plan</a>.
            </p>
            <div v-for="(debt, i) in props.debtMinimums" :key="debt.id">
                <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                <div class="flex items-center justify-between py-1 text-[14px]">
                    <a href="/payoff-plan" style="color: var(--hab-ink)">{{ debt.name }}</a>
                    <span style="color: var(--hab-muted)">{{ formatMoney(debt.minimum_payment) }}</span>
                </div>
            </div>
        </div>

        <!-- Bills -->
        <div class="mb-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Bills</h2>
                <button type="button" class="text-[13px] font-medium" style="color: var(--hab-accent)" @click="billsManagerOpen = !billsManagerOpen">
                    Manage bills
                </button>
            </div>

            <p v-if="props.bills.length === 0" class="text-[14px]" style="color: var(--hab-muted)">No bills set up this month.</p>
            <div v-for="(bill, i) in props.bills" :key="bill.id">
                <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                <div class="flex items-center justify-between py-1">
                    <div>
                        <div class="text-[14px]" style="color: var(--hab-ink)">{{ bill.name }}</div>
                        <div class="text-[12px]" style="color: var(--hab-muted)">
                            {{ formatMoney(bill.actual_amount ?? bill.expected_amount) }}
                            <span v-if="bill.paid_at">· paid {{ bill.paid_at }}</span>
                            <span v-else-if="bill.due_day">· due day {{ bill.due_day }}</span>
                        </div>
                    </div>
                    <button v-if="!bill.paid_at && payingBillId !== bill.id" type="button" class="text-[12.5px] font-medium"
                        style="color: var(--hab-accent)" @click="openPayBill(bill)">
                        Mark paid
                    </button>
                </div>
                <div v-if="payingBillId === bill.id" class="mt-1 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="flex items-center gap-2">
                        <input v-model.number="payForm.actual_amount" type="number" min="0.01" step="0.01"
                            class="w-32 rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                            :disabled="payForm.processing" @click="submitPayBill(bill.id)">
                            Confirm
                        </button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)"
                            :disabled="payForm.processing" @click="cancelPayBill">
                            Cancel
                        </button>
                    </div>
                    <div v-if="payForm.errors.actual_amount" class="mt-2 text-[12px]" style="color: #c0512c">{{ payForm.errors.actual_amount }}</div>
                </div>
            </div>

            <div v-if="billsManagerOpen" class="mt-4 border-t pt-4" style="border-color: var(--hab-hairline)">
                <div v-for="(template, i) in props.billTemplates" :key="template.id">
                    <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                    <div v-if="editingBillId !== template.id" class="flex items-center justify-between py-1 text-[13px]">
                        <span :style="{ color: template.active ? 'var(--hab-ink)' : 'var(--hab-muted)' }">
                            {{ template.name }} — {{ formatMoney(template.expected_amount) }}
                            <span v-if="!template.active">(inactive)</span>
                        </span>
                        <div class="flex items-center gap-3">
                            <button type="button" class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)"
                                @click="openEditBill(template)">edit</button>
                            <button type="button" class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)"
                                @click="removeBill(template)">delete</button>
                        </div>
                    </div>
                </div>

                <div v-if="editingBillId !== null" class="mt-2 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <input v-model="billForm.name" type="text" placeholder="Name" class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <input v-model.number="billForm.expected_amount" type="number" min="0.01" step="0.01" placeholder="Amount"
                            class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <input v-model.number="billForm.due_day" type="number" min="1" max="31" placeholder="Due day"
                            class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <label class="flex items-center gap-2 text-[12px]" style="color: var(--hab-muted)">
                            <input v-model="billForm.active" type="checkbox" class="h-4 w-4" /> Active
                        </label>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                            :disabled="billForm.processing" @click="submitBillForm">Confirm</button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)"
                            :disabled="billForm.processing" @click="cancelBillForm">Cancel</button>
                    </div>
                    <div v-if="billForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ billForm.errors.name }}</div>
                    <div v-if="billForm.errors.expected_amount" class="mt-2 text-[12px]" style="color: #c0512c">{{ billForm.errors.expected_amount }}</div>
                </div>
                <button v-else type="button" class="mt-2 flex items-center gap-2 text-[13px] font-medium" style="color: var(--hab-accent)"
                    @click="openAddBill">
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span> Add a bill
                </button>
            </div>
        </div>

        <!-- Envelopes -->
        <div class="mb-5 rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <div class="mb-3 flex items-center justify-between">
                <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Envelopes</h2>
                <button type="button" class="text-[13px] font-medium" style="color: var(--hab-accent)"
                    @click="envelopesManagerOpen = !envelopesManagerOpen">
                    Manage envelopes
                </button>
            </div>

            <p v-if="props.envelopes.length === 0" class="text-[14px]" style="color: var(--hab-muted)">No envelopes set up this month.</p>
            <div v-for="(envelope, i) in props.envelopes" :key="envelope.id">
                <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                <div class="py-1">
                    <div class="flex items-center justify-between text-[14px]">
                        <span style="color: var(--hab-ink)">{{ envelope.name }}</span>
                        <span style="color: var(--hab-muted)">{{ formatMoney(envelope.spent) }} / {{ formatMoney(envelope.target) }}</span>
                    </div>
                    <div class="mt-1 h-1.5 w-full overflow-hidden rounded-full" style="background: var(--hab-chip-fill)">
                        <div class="h-full rounded-full" style="background: var(--hab-accent)"
                            :style="{ width: Math.min(100, envelope.target > 0 ? (envelope.spent / envelope.target) * 100 : 0) + '%' }"></div>
                    </div>
                </div>
            </div>

            <div v-if="envelopesManagerOpen" class="mt-4 border-t pt-4" style="border-color: var(--hab-hairline)">
                <div v-for="(template, i) in props.envelopeTemplates" :key="template.id">
                    <div v-if="i > 0" class="my-2 h-px" style="background: var(--hab-hairline)"></div>
                    <div v-if="editingEnvelopeId !== template.id" class="flex items-center justify-between py-1 text-[13px]">
                        <span :style="{ color: template.active ? 'var(--hab-ink)' : 'var(--hab-muted)' }">
                            {{ template.name }} — {{ formatMoney(template.default_target) }}
                            <span v-if="!template.active">(inactive)</span>
                        </span>
                        <div class="flex items-center gap-3">
                            <button type="button" class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)"
                                @click="openEditEnvelope(template)">edit</button>
                            <button type="button" class="material-symbols-rounded" style="font-size: 16px; color: var(--hab-faint)"
                                @click="removeEnvelope(template)">delete</button>
                        </div>
                    </div>
                </div>

                <div v-if="editingEnvelopeId !== null" class="mt-2 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                        <input v-model="envelopeForm.name" type="text" placeholder="Name" class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <input v-model.number="envelopeForm.default_target" type="number" min="0.01" step="0.01" placeholder="Target"
                            class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <input v-model="envelopeForm.icon" type="text" placeholder="Icon (optional)"
                            class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                            style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        <label class="flex items-center gap-2 text-[12px]" style="color: var(--hab-muted)">
                            <input v-model="envelopeForm.active" type="checkbox" class="h-4 w-4" /> Active
                        </label>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                            :disabled="envelopeForm.processing" @click="submitEnvelopeForm">Confirm</button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)"
                            :disabled="envelopeForm.processing" @click="cancelEnvelopeForm">Cancel</button>
                    </div>
                    <div v-if="envelopeForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ envelopeForm.errors.name }}</div>
                    <div v-if="envelopeForm.errors.default_target" class="mt-2 text-[12px]" style="color: #c0512c">{{ envelopeForm.errors.default_target }}</div>
                </div>
                <button v-else type="button" class="mt-2 flex items-center gap-2 text-[13px] font-medium" style="color: var(--hab-accent)"
                    @click="openAddEnvelope">
                    <span class="material-symbols-rounded" style="font-size: 16px">add</span> Add an envelope
                </button>
            </div>
        </div>

        <!-- Leftover suggestion -->
        <div v-if="props.priorityDebt && props.leftover > 0" class="rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)">
            <p class="text-[14px]" style="color: var(--hab-ink)">
                You have {{ formatMoney(props.leftover) }} left — send it to
                <span class="font-semibold">{{ props.priorityDebt.name }}</span>?
            </p>
            <div class="mt-2 flex items-center gap-2">
                <select v-model.number="extraPaymentAccountId" class="rounded-[8px] border px-2 py-1.5 text-[12.5px]"
                    style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                    <option v-for="account in props.assetAccounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                </select>
                <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white" style="background: var(--hab-accent)"
                    @click="confirmExtraPaymentNow">
                    Send it
                </button>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 4: Build the frontend**

Run: `npm run build`
Expected: builds without TypeScript or Vite errors.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Budget.vue resources/js/components/AppSidebar.vue resources/js/actions/App/Http/Controllers/BudgetController.ts
git commit -m "feat: add Budget page with bills, envelopes, and payoff suggestions"
```

---

## Task 11: Envelope picker on the Ledger "Log a transaction" form

**Files:**
- Modify: `resources/js/pages/Ledger.vue`

**Interfaces:**
- Consumes: the `envelopes` prop added to `LedgerController::index()` in Task 9.

- [ ] **Step 1: Add the `envelopes` prop type and accept it**

In `resources/js/pages/Ledger.vue`, add a type and extend `defineProps`:

```ts
type LedgerEnvelope = { id: number; name: string };
```

Change:
```ts
const props = defineProps<{ accounts: LedgerAccount[] }>();
```
to:
```ts
const props = defineProps<{ accounts: LedgerAccount[]; envelopes: LedgerEnvelope[] }>();
```

- [ ] **Step 2: Add `envelope_id` to the transaction form state**

Change the `transactionForm` declaration to add the field:

```ts
const transactionForm = useForm({
    type: 'expense' as TransactionType,
    account_id: null as number | null,
    from_account_id: null as number | null,
    to_account_id: null as number | null,
    envelope_id: null as number | null,
    amount: 0,
    description: null as string | null,
    occurred_at: new Date().toLocaleDateString('en-CA'),
});
```

In `openTransactionForm()`, reset it alongside the other fields:

```ts
    transactionForm.envelope_id = null;
```

- [ ] **Step 3: Clear `envelope_id` when switching away from Expense**

Change `onTransactionTypeChange()`:

```ts
function onTransactionTypeChange(): void {
    if (transactionForm.type === 'transfer') {
        transactionForm.account_id = null;
        transactionForm.envelope_id = null;
        transactionForm.from_account_id ??= props.accounts[0]?.id ?? null;
        transactionForm.to_account_id ??= props.accounts[1]?.id ?? null;
    } else {
        transactionForm.account_id ??= props.accounts[0]?.id ?? null;
        transactionForm.from_account_id = null;
        transactionForm.to_account_id = null;

        if (transactionForm.type !== 'expense') {
            transactionForm.envelope_id = null;
        }
    }
}
```

- [ ] **Step 4: Add the picker to the template**

In the "Log a transaction" form grid, right after the "Account" field's `</div>` (the one shown `v-if="transactionForm.type !== 'transfer'"`), add:

```html
                    <div v-if="transactionForm.type === 'expense'">
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Envelope (optional)</label>
                        <select v-model.number="transactionForm.envelope_id" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                            <option :value="null">None</option>
                            <option v-for="envelope in props.envelopes" :key="envelope.id" :value="envelope.id">{{ envelope.name }}</option>
                        </select>
                    </div>
```

Add its error display next to the other `transactionForm.errors.*` lines:

```html
                <div v-if="transactionForm.errors.envelope_id" class="mt-2 text-[12px]" style="color: #c0512c">{{ transactionForm.errors.envelope_id }}</div>
```

- [ ] **Step 5: Build the frontend**

Run: `npm run build`
Expected: builds without TypeScript or Vite errors.

- [ ] **Step 6: Manually verify via Herd**

Visit `https://lexi.test/ledger`, log an expense transaction, confirm the envelope picker appears only for Expense, and that logging one with an envelope selected shows up correctly (check `/budget` afterward — the envelope's "spent" figure should reflect it).

- [ ] **Step 7: Run the full test suite one final time**

Run: `php artisan test --compact`
Expected: PASS, project-wide.

- [ ] **Step 8: Commit**

```bash
git add resources/js/pages/Ledger.vue
git commit -m "feat: add envelope picker to the ledger transaction form"
```

---

## Post-plan: update the handoff doc

Once all 11 tasks are merged, append a "Progress since this handoff" entry to `docs/superpowers/plans/2026-08-27-money-section-debt-focus-handoff.md` describing what shipped, following the style of the existing entries for the ledger/payoff-plan UI track. This isn't a task with its own tests — it's documentation upkeep, done once, by hand, after everything above is verified working.
