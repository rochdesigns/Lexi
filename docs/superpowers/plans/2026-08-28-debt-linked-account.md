# Wire Debt.linked_account_id Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a nullable `linked_account_id` foreign key on `App\Models\DebtFocus\Debt` pointing at `App\Models\DebtFocus\Account`, so a revolving debt can be connected to the ledger account it's actually spent on and paid from.

**Architecture:** One migration adds the nullable FK column (`SET NULL` on delete — deleting an account shouldn't delete the debt payoff record it was linked from). One model change adds the column to `$fillable` and a `belongsTo` relation. No controller, route, or UI changes: `App\Models\DebtFocus\Account`/`Transaction`/`LedgerService` were merged the same way — backend-only, with no route calling them yet — and this follows the same precedent. The account-picker UI and the "spent vs. paid" derived queries described in the spec are separate, not-yet-scoped follow-ups that build on top of this FK once they're actually needed (no `Account`-creation UI exists yet either, so wiring a picker now would have nothing real to pick from).

**Tech Stack:** Laravel 13 / PHP 8.4, Eloquent, Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` (see the `Debt` entity section) and `docs/superpowers/plans/2026-08-27-money-section-debt-focus-handoff.md` ("Suggested next step").

## Global Constraints

- Money section rebuild stays a **from-scratch `DebtFocus` namespace**, completely separate from the existing `/money`+`/debt` implementation (`App\Http\Controllers\MoneyController`/`DebtController`) — do not touch those files, and do not reconcile or merge models/logic between the two systems.
- `Debt.debt_type` (`revolving`/`installment`) is independent of whether `linked_account_id` is set — a cut-up card can be `installment`-like behavior while still carrying a linked account. Do not add a validation or DB constraint coupling the two fields.
- Follow existing `DebtFocus` model conventions: `HasFactory`, explicit `protected $table`, `protected $fillable`, typed relation methods with `@return BelongsTo<Model, $this>` PHPDoc (see `app/Models/DebtFocus/Transaction.php` for the pattern of multiple `belongsTo` relations to the same related model).
- Run `vendor/bin/pint --dirty --format agent` after any PHP change, before considering a step done.

---

### Task 1: Add `linked_account_id` to `Debt` and wire the relation

**Files:**
- Create: `database/migrations/2026_08_28_150000_add_linked_account_id_to_debt_focus_debts_table.php`
- Modify: `app/Models/DebtFocus/Debt.php`
- Test: `tests/Feature/DebtFocus/DebtModelTest.php`

**Interfaces:**
- Consumes: `App\Models\DebtFocus\Account` (existing model, `app/Models/DebtFocus/Account.php`), `Database\Factories\DebtFocus\AccountFactory` (existing, has a `liability()` state).
- Produces: `Debt::linkedAccount(): BelongsTo<Account, $this>` — later work (spend/paid derived queries, an account-picker UI) will call this relation and read `Debt::$linked_account_id` directly.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/DebtFocus/DebtModelTest.php` (add the `Account` import alongside the existing `Debt`/`User` imports at the top of the file):

```php
use App\Models\DebtFocus\Account;
```

Then add these three tests at the end of the file:

```php
test('a debt can be linked to an account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create();

    $debt = Debt::factory()->for($user)->create([
        'debt_type' => 'revolving',
        'linked_account_id' => $account->id,
    ]);

    $fresh = $debt->fresh();

    expect($fresh->linked_account_id)->toBe($account->id);
    expect($fresh->linkedAccount)->toBeInstanceOf(Account::class);
    expect($fresh->linkedAccount->id)->toBe($account->id);
});

test('linked_account_id defaults to null when not supplied', function () {
    $user = User::factory()->create();

    $debt = Debt::factory()->for($user)->create();

    expect($debt->fresh()->linked_account_id)->toBeNull();
    expect($debt->fresh()->linkedAccount)->toBeNull();
});

test('linked_account_id is set to null when the linked account is deleted', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->liability()->create();
    $debt = Debt::factory()->for($user)->create(['linked_account_id' => $account->id]);

    $account->delete();

    expect($debt->fresh()->linked_account_id)->toBeNull();
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=DebtModelTest`
Expected: FAIL — `linked_account_id` is not a known column / not fillable (SQL error or Eloquent mass-assignment exception), and `linkedAccount` is not a method on `Debt`.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->foreignId('linked_account_id')->nullable()->after('due_day')
                ->constrained('debt_focus_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_account_id');
        });
    }
};
```

- [ ] **Step 4: Add the column and relation to the model**

In `app/Models/DebtFocus/Debt.php`, add the `Account` import and a `BelongsTo` relation:

```php
use App\Models\DebtFocus\Account;
```

Update `$fillable`:

```php
protected $fillable = [
    'user_id', 'name', 'balance', 'monthly_rate', 'minimum_payment', 'debt_type', 'due_day', 'linked_account_id',
];
```

Add the relation method (after the existing `user()` method):

```php
/**
 * @return BelongsTo<Account, $this>
 */
public function linkedAccount(): BelongsTo
{
    return $this->belongsTo(Account::class, 'linked_account_id');
}
```

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=DebtModelTest`
Expected: PASS (all `DebtModelTest` tests, including the three new ones)

- [ ] **Step 6: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no remaining style violations (auto-fixes applied if any)

- [ ] **Step 7: Run the full DebtFocus test suite to check for regressions**

Run: `php artisan test --compact --filter=DebtFocus`
Expected: PASS — confirms `AccountModelTest`, `LedgerServiceTest`, `PayoffEngineTest`, `PayoffPlanControllerTest`, and `TransactionModelTest` are unaffected.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_08_28_150000_add_linked_account_id_to_debt_focus_debts_table.php app/Models/DebtFocus/Debt.php tests/Feature/DebtFocus/DebtModelTest.php
git commit -m "feat: link DebtFocus debts to their ledger account"
```

---

## Self-Review Notes

- **Spec coverage:** implements the `linked_account_id` field from the `Debt` entity block of the design spec exactly as specified (nullable FK to `Account`). The spend/paid derived-query behavior described in the same spec section is explicitly out of scope here — it has no consumer yet (no budgeting period concept exists), and the handoff doc scopes this step as just the FK wiring, with Bill/Envelope named as the next separate schema piece.
- **Out of scope, deliberately:** account-picker UI on `/payoff-plan` (no `Account`-creation UI exists at all yet, so there's nothing for a picker to offer), cross-user ownership validation on `linked_account_id` (no controller/route sets this field yet — add that validation alongside whichever controller change first exposes it, following the `abort_if($debt->user_id !== ...)` pattern already used in `PayoffPlanController`), and any constraint tying `linked_account_id` to `debt_type` (spec explicitly says these are independent).
- **Type consistency:** `linkedAccount()` follows the exact `BelongsTo<Account, $this>` pattern already used by `Transaction::account()`/`fromAccount()`/`toAccount()` in `app/Models/DebtFocus/Transaction.php`.
