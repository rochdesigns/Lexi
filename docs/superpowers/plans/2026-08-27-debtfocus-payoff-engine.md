# DebtFocus Payoff Engine Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a standalone, strategy-selectable (avalanche/snowball) debt payoff simulation engine — the first buildable piece of the debt-focus money section design, self-contained enough to ship and test on its own.

**Architecture:** A brand-new, isolated subsystem — `debt_focus_debts` table, `App\Models\DebtFocus\Debt` model, `App\Services\DebtFocus\PayoffEngine` service — kept entirely separate from the app's existing `accounts`/`debts`/`transactions`/`envelope_assignments` tables and models. Nothing existing is read, modified, or reused. The engine takes a collection of `DebtFocus\Debt` records, a strategy (`avalanche` = highest APR first, or `snowball` = lowest starting balance first), and an optional extra monthly payment, and simulates month-by-month payoff — always against the debts' *current* balances, never a stored schedule, so re-running it later against updated balances is how re-baselining works (no separate "recompute" step to build).

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` — this plan implements only the "Payoff engine" section. The `Account`/`Transaction`/`is_liability` model, `debt_type` revolving-vs-installment net-progress tracking, debt-first budgeting, the underspend sweep, and missed-payment severity are each separate future plans, not covered here.

## Global Constraints

- This is a **new, standalone subsystem** — do not read, modify, or reuse the existing `accounts`, `debts`, `transactions`, or `envelope_assignments` tables, models, or services anywhere in this plan. Per the spec, this build is deliberately kept separate from the current implementation; reconciliation is an explicitly deferred future step.
- Namespace everything under `DebtFocus`: models in `App\Models\DebtFocus`, services in `App\Services\DebtFocus`, factories in `Database\Factories\DebtFocus`, table names prefixed `debt_focus_`. This avoids any collision with the existing `App\Models\Debt`.
- No frontend/UI work in this plan — backend persistence and the simulation engine only.
- After every PHP change: `vendor/bin/pint --dirty --format agent`.
- After every task: run the targeted Pest filter. Run the full suite before the final commit of the plan.

---

### Task 1: `DebtFocus\Debt` — migration, model, factory

**Files:**
- Create: `database/migrations/2026_08_27_120000_create_debt_focus_debts_table.php`
- Create: `app/Models/DebtFocus/Debt.php`
- Create: `database/factories/DebtFocus/DebtFactory.php`
- Test: `tests/Feature/DebtFocus/DebtModelTest.php`

**Interfaces:**
- Produces: `App\Models\DebtFocus\Debt` with fillable `user_id, name, balance, apr, minimum_payment, debt_type`; casts `balance`/`apr`/`minimum_payment` to `decimal:2`. `apr` is nullable. `debt_type` is a plain string (`'revolving'` or `'installment'`), defaulting to `'installment'` at the database level when omitted.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\DebtFocus\Debt;
use App\Models\User;

test('a debt focus debt persists with the expected fields and casts', function () {
    $user = User::factory()->create();

    $debt = Debt::factory()->for($user)->create([
        'name' => 'Visa',
        'balance' => 1500.50,
        'apr' => 19.99,
        'minimum_payment' => 45,
        'debt_type' => 'revolving',
    ]);

    $fresh = $debt->fresh();

    expect($fresh->name)->toBe('Visa');
    expect((float) $fresh->balance)->toBe(1500.50);
    expect((float) $fresh->apr)->toBe(19.99);
    expect((float) $fresh->minimum_payment)->toBe(45.0);
    expect($fresh->debt_type)->toBe('revolving');
    expect($fresh->user_id)->toBe($user->id);
});

test('apr is nullable', function () {
    $user = User::factory()->create();

    $debt = Debt::factory()->for($user)->create(['apr' => null]);

    expect($debt->fresh()->apr)->toBeNull();
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=DebtModelTest`
Expected: FAIL — no migration, model, or factory exist yet.

- [ ] **Step 3: Implement the migration, model, and factory**

`database/migrations/2026_08_27_120000_create_debt_focus_debts_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_focus_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('balance', 10, 2);
            $table->decimal('apr', 5, 2)->nullable();
            $table->decimal('minimum_payment', 10, 2);
            $table->string('debt_type')->default('installment');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_focus_debts');
    }
};
```

`app/Models/DebtFocus/Debt.php`:

```php
<?php

namespace App\Models\DebtFocus;

use App\Models\User;
use Database\Factories\DebtFocus\DebtFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Debt extends Model
{
    /** @use HasFactory<DebtFactory> */
    use HasFactory;

    protected $table = 'debt_focus_debts';

    protected $fillable = [
        'user_id', 'name', 'balance', 'apr', 'minimum_payment', 'debt_type',
    ];

    protected $casts = [
        'balance' => 'decimal:2',
        'apr' => 'decimal:2',
        'minimum_payment' => 'decimal:2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

`database/factories/DebtFocus/DebtFactory.php`:

```php
<?php

namespace Database\Factories\DebtFocus;

use App\Models\DebtFocus\Debt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Debt> */
class DebtFactory extends Factory
{
    protected $model = Debt::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->words(2, true),
            'balance' => fake()->randomFloat(2, 100, 20000),
            'apr' => fake()->randomFloat(2, 0, 30),
            'minimum_payment' => fake()->randomFloat(2, 20, 500),
            'debt_type' => fake()->randomElement(['revolving', 'installment']),
        ];
    }
}
```

Run `php artisan migrate` to apply the new migration to the local database.

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=DebtModelTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_27_120000_create_debt_focus_debts_table.php app/Models/DebtFocus/Debt.php database/factories/DebtFocus/DebtFactory.php tests/Feature/DebtFocus/DebtModelTest.php
git commit -m "feat: add standalone DebtFocus\Debt model, migration, and factory"
```

---

### Task 2: `PayoffEngine` — avalanche strategy

**Files:**
- Create: `app/Services/DebtFocus/PayoffEngine.php`
- Test: `tests/Feature/DebtFocus/PayoffEngineTest.php`

**Interfaces:**
- Consumes: `App\Models\DebtFocus\Debt` (Task 1).
- Produces: `App\Services\DebtFocus\PayoffEngine::simulate(Collection $debts, string $strategy, float $extraMonthly = 0.0): array`, returning `['strategy' => string, 'months' => int|null, 'onTrack' => bool, 'perDebt' => [['debtId' => int, 'payoffMonth' => int|null, 'totalInterest' => float|null], ...]]`. `months`/`onTrack` describe the whole simulation (`onTrack` false, `months` null if any debt never clears within a 600-month cap). Only `'avalanche'` is a valid `$strategy` after this task — `'snowball'` is added in Task 3.

- [ ] **Step 1: Write the failing tests**

```php
<?php

use App\Models\DebtFocus\Debt;
use App\Models\User;
use App\Services\DebtFocus\PayoffEngine;
use InvalidArgumentException;

test('a single debt with no interest and no extra payment pays off in balance divided by minimum-payment months', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 1200, 'minimum_payment' => 100, 'apr' => null]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result['months'])->toBe(12);
    expect($result['onTrack'])->toBeTrue();
    expect($result['perDebt'][0]['debtId'])->toBe($debt->id);
    expect($result['perDebt'][0]['payoffMonth'])->toBe(12);
    expect($result['perDebt'][0]['totalInterest'])->toBeNull();
});

test('a debt whose minimum payment cannot cover accrued interest is marked not on track', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 10000, 'minimum_payment' => 5, 'apr' => 12]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result['months'])->toBeNull();
    expect($result['onTrack'])->toBeFalse();
    expect($result['perDebt'][0]['payoffMonth'])->toBeNull();
});

test('avalanche directs the extra payment at the highest-apr active debt', function () {
    $user = User::factory()->create();
    $lowRate = Debt::factory()->for($user)->create(['balance' => 500, 'minimum_payment' => 50, 'apr' => 0]);
    $highRate = Debt::factory()->for($user)->create(['balance' => 200, 'minimum_payment' => 20, 'apr' => 24]);

    $result = (new PayoffEngine)->simulate(collect([$lowRate, $highRate]), 'avalanche', extraMonthly: 200);

    $highRateResult = collect($result['perDebt'])->firstWhere('debtId', $highRate->id);
    expect($highRateResult['payoffMonth'])->toBe(1);
});

test('a fully paid-off debt is excluded and the simulation reports already-done', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create(['balance' => 0]);

    $result = (new PayoffEngine)->simulate(collect([$debt]), 'avalanche');

    expect($result)->toBe(['strategy' => 'avalanche', 'months' => 0, 'onTrack' => true, 'perDebt' => []]);
});

test('an unsupported strategy throws', function () {
    $user = User::factory()->create();
    $debt = Debt::factory()->for($user)->create();

    (new PayoffEngine)->simulate(collect([$debt]), 'lowest-hanging-fruit');
})->throws(InvalidArgumentException::class);
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=PayoffEngineTest`
Expected: FAIL — `App\Services\DebtFocus\PayoffEngine` doesn't exist yet.

- [ ] **Step 3: Implement**

`app/Services/DebtFocus/PayoffEngine.php`:

```php
<?php

namespace App\Services\DebtFocus;

use App\Models\DebtFocus\Debt;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class PayoffEngine
{
    private const MAX_MONTHS = 600;

    /**
     * @param  Collection<int, Debt>  $debts
     * @return array{
     *     strategy: string,
     *     months: int|null,
     *     onTrack: bool,
     *     perDebt: array<int, array{debtId: int, payoffMonth: int|null, totalInterest: float|null}>
     * }
     */
    public function simulate(Collection $debts, string $strategy, float $extraMonthly = 0.0): array
    {
        if (! in_array($strategy, ['avalanche'], true)) {
            throw new InvalidArgumentException("Unsupported strategy: {$strategy}");
        }

        $active = $debts->filter(fn (Debt $debt) => (float) $debt->balance > 0)->values();

        if ($active->isEmpty()) {
            return ['strategy' => $strategy, 'months' => 0, 'onTrack' => true, 'perDebt' => []];
        }

        $work = $active->keyBy('id')->map(fn (Debt $debt) => [
            'balance' => (float) $debt->balance,
            'minimumPayment' => (float) $debt->minimum_payment,
            'monthlyRate' => $debt->apr !== null ? ((float) $debt->apr / 100 / 12) : 0.0,
            'totalInterest' => $debt->apr !== null ? 0.0 : null,
            'payoffMonth' => null,
        ])->all();

        $month = 0;

        while (collect($work)->contains(fn ($d) => $d['payoffMonth'] === null) && $month < self::MAX_MONTHS) {
            $month++;

            foreach ($work as &$debt) {
                if ($debt['payoffMonth'] !== null) {
                    continue;
                }

                $interest = $debt['balance'] * $debt['monthlyRate'];
                if ($debt['totalInterest'] !== null) {
                    $debt['totalInterest'] += $interest;
                }
                $debt['balance'] += $interest;
            }
            unset($debt);

            foreach ($work as &$debt) {
                if ($debt['payoffMonth'] !== null) {
                    continue;
                }

                $debt['balance'] -= min($debt['balance'], $debt['minimumPayment']);

                if ($debt['balance'] <= 0.0) {
                    $debt['balance'] = 0.0;
                    $debt['payoffMonth'] = $month;
                }
            }
            unset($debt);

            if ($extraMonthly > 0) {
                $target = $this->priorityTarget($work, $strategy);

                if ($target !== null) {
                    $work[$target]['balance'] = max(0.0, $work[$target]['balance'] - $extraMonthly);

                    if ($work[$target]['balance'] <= 0.0) {
                        $work[$target]['payoffMonth'] = $month;
                    }
                }
            }
        }

        $allPaidOff = collect($work)->every(fn ($d) => $d['payoffMonth'] !== null);

        return [
            'strategy' => $strategy,
            'months' => $allPaidOff ? $month : null,
            'onTrack' => $allPaidOff,
            'perDebt' => $active->map(fn (Debt $debt) => [
                'debtId' => $debt->id,
                'payoffMonth' => $work[$debt->id]['payoffMonth'],
                'totalInterest' => $work[$debt->id]['totalInterest'] !== null
                    ? round($work[$debt->id]['totalInterest'], 2)
                    : null,
            ])->all(),
        ];
    }

    /**
     * @param  array<int, array{balance: float, monthlyRate: float, payoffMonth: int|null}>  $work
     */
    private function priorityTarget(array $work, string $strategy): ?int
    {
        $activeIds = array_keys(array_filter($work, fn ($d) => $d['payoffMonth'] === null));

        if ($activeIds === []) {
            return null;
        }

        usort($activeIds, fn ($a, $b) => $work[$b]['monthlyRate'] <=> $work[$a]['monthlyRate'] ?: $a <=> $b);

        return $activeIds[0];
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=PayoffEngineTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/DebtFocus/PayoffEngine.php tests/Feature/DebtFocus/PayoffEngineTest.php
git commit -m "feat: add DebtFocus PayoffEngine with avalanche strategy"
```

---

### Task 3: `PayoffEngine` — snowball strategy

**Files:**
- Modify: `app/Services/DebtFocus/PayoffEngine.php`
- Modify: `tests/Feature/DebtFocus/PayoffEngineTest.php`

**Interfaces:**
- Produces: `'snowball'` becomes a second valid `$strategy` value for `PayoffEngine::simulate()` — extra payment goes to the active debt with the lowest *starting* balance, in a fixed priority order determined once at the start of the simulation (not re-sorted as balances change), moving to the next debt in that order once the current target pays off.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DebtFocus/PayoffEngineTest.php`:

```php
test('snowball directs the extra payment at the lowest-starting-balance active debt regardless of rate', function () {
    $user = User::factory()->create();
    $highBalance = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 50, 'apr' => 24]);
    $lowBalance = Debt::factory()->for($user)->create(['balance' => 100, 'minimum_payment' => 10, 'apr' => 0]);

    $result = (new PayoffEngine)->simulate(collect([$highBalance, $lowBalance]), 'snowball', extraMonthly: 200);

    $lowBalanceResult = collect($result['perDebt'])->firstWhere('debtId', $lowBalance->id);
    expect($lowBalanceResult['payoffMonth'])->toBe(1);
});

test('snowball moves the extra payment to the next-lowest-starting-balance debt once the current target pays off', function () {
    $user = User::factory()->create();
    $tiny = Debt::factory()->for($user)->create(['balance' => 50, 'minimum_payment' => 50, 'apr' => 0]);
    $huge = Debt::factory()->for($user)->create(['balance' => 1000, 'minimum_payment' => 10, 'apr' => 0]);
    $middle = Debt::factory()->for($user)->create(['balance' => 200, 'minimum_payment' => 10, 'apr' => 0]);

    $result = (new PayoffEngine)->simulate(collect([$tiny, $huge, $middle]), 'snowball', extraMonthly: 300);

    $middleResult = collect($result['perDebt'])->firstWhere('debtId', $middle->id);
    $hugeResult = collect($result['perDebt'])->firstWhere('debtId', $huge->id);

    expect($middleResult['payoffMonth'])->toBe(1);
    expect($hugeResult['payoffMonth'])->not->toBe(1);
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --compact --filter=PayoffEngineTest`
Expected: The two new tests FAIL (`'snowball'` is rejected as unsupported); the existing avalanche tests still PASS.

- [ ] **Step 3: Implement**

In `app/Services/DebtFocus/PayoffEngine.php`, make three changes:

Change the strategy whitelist:

```php
if (! in_array($strategy, ['avalanche', 'snowball'], true)) {
```

Right after `$work` is built (before the `$month = 0;` line), compute the fixed snowball order from the *original* starting balances:

```php
$sortedByStartingBalance = $active->all();
usort($sortedByStartingBalance, fn (Debt $a, Debt $b) => [(float) $a->balance, $a->id] <=> [(float) $b->balance, $b->id]);
$snowballOrder = array_map(fn (Debt $debt) => $debt->id, $sortedByStartingBalance);
```

Update the `priorityTarget()` call to pass it through:

```php
$target = $this->priorityTarget($work, $strategy, $snowballOrder);
```

Replace `priorityTarget()` with a version that branches on strategy:

```php
/**
 * @param  array<int, array{balance: float, monthlyRate: float, payoffMonth: int|null}>  $work
 * @param  array<int, int>  $snowballOrder
 */
private function priorityTarget(array $work, string $strategy, array $snowballOrder): ?int
{
    if ($strategy === 'snowball') {
        foreach ($snowballOrder as $id) {
            if ($work[$id]['payoffMonth'] === null) {
                return $id;
            }
        }

        return null;
    }

    $activeIds = array_keys(array_filter($work, fn ($d) => $d['payoffMonth'] === null));

    if ($activeIds === []) {
        return null;
    }

    usort($activeIds, fn ($a, $b) => $work[$b]['monthlyRate'] <=> $work[$a]['monthlyRate'] ?: $a <=> $b);

    return $activeIds[0];
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=PayoffEngineTest`
Expected: PASS — both new snowball tests and all existing avalanche tests.

Then run the full suite to confirm nothing outside this plan was touched:

Run: `php artisan test --compact`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/DebtFocus/PayoffEngine.php tests/Feature/DebtFocus/PayoffEngineTest.php
git commit -m "feat: add snowball strategy to DebtFocus PayoffEngine"
```

---

## Explicitly out of scope for this plan (per the spec)

No `Account`/`Transaction`/`is_liability` model, no `debt_type` revolving-vs-installment net-progress tracking, no debt-first zero-based budgeting or envelope enforcement, no underspend-sweep nudge, no missed-payment severity distinction, and no frontend pages or routes. Each is a separate future plan built on top of this engine.
