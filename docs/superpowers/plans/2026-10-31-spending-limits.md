# Spending Limits Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the user set an optional monthly spending limit per expense category and watch living expenses against it on the Plan, Overview, Transactions and the Money MCP.

**Architecture:** A `spending_limits` table stores one row per category per month a limit was set or changed (`starts_on` = first of that month); the limit in force for a month is the latest row on or before it. A new `App\Services\Money\SpendingLimits` service turns limits plus the month's counted, non-bill expenses into per-category rows; controllers and the MCP tool only present those rows. Safe to spend is untouched (ADR 0001).

**Tech Stack:** Laravel 13, Pest 4, Inertia v3 + Vue 3, Wayfinder, Tailwind v4, laravel/mcp.

**Spec:** `docs/superpowers/specs/2026-10-31-spending-limits-design.md` (also `GLOSSARY.md`, `docs/adr/0001-spending-limits-watched-not-reserved.md`)

## Global Constraints

- Amounts are stored as integer centavos; request amounts arrive in pesos and go through `Centavos::fromPesos()` (see `BillRequest::billAttributes()`). Rule: `['nullable', 'numeric', 'gt:0', 'max:1000000000']`.
- A custom-category limit is stored like a custom-category transaction: `category = 'other'` plus `custom_category_id`. Plain Other is `category = 'other'`, `custom_category_id = null`.
- "Spent" for a category = `transactions()->counted()`, `kind = expense`, `bill_id IS NULL`, date from the first of the month through today — the same filter as `Projection::spentThisMonth()`.
- Close threshold: spent ≥ 80% of limit (compare `spent * 100 >= limit * 80`, integers only).
- Pace: `intdiv(limit * dayOfMonth, daysInMonth)`.
- Category order everywhere: built-in in `TransactionCategory::expenseValues()` order, then custom categories by name.
- UI copy: section title "Spending limits"; over-safe-to-spend note "Your limits are ₱X more than safe to spend."; status words "₱X over", "ahead of pace", "₱X left".
- Every new foreign id is validated ownership-scoped: `Rule::exists('custom_categories', 'id')->where('user_id', $userId)`.
- After PHP changes: `vendor/bin/pint --dirty --format agent`. After Vue/TS changes: `npm run lint`, `npm run format` and `npm run types:check`.

## Review Focus

1. **Custom category deleted mid-month** — its limit rows go (FK cascade) and its spending lands in Other, counting against Other's limit. Test in Task 2.
2. **Statement row matched to a manual entry** — counted once, not twice. Test in Task 1.
3. **Bill paid by card vs. card purchase** — the purchase counts, the bill payment does not. Test in Task 1.
4. **Limit changed in October after being set in September, or removed** — September still reads the old value; a removal does not come back next month. Tests in Tasks 1 and 2.
5. **Expense dated later this month** — not counted until its date (matches "spent this month"). Test in Task 1.

---

### Task 1: Storage and the `SpendingLimits` service

**Files:**
- Create: migration `create_spending_limits_table` (via `php artisan make:migration --no-interaction`)
- Create: `app/Models/Money/SpendingLimit.php`, `database/factories/Money/SpendingLimitFactory.php`
- Create: `app/Services/Money/SpendingLimits.php`
- Modify: `app/Models/User.php` (add `spendingLimits(): HasMany` next to `customCategories()`)
- Test: `tests/Feature/Money/SpendingLimitsTest.php`

**Interfaces:**
- Produces: table `spending_limits(id, user_id FK cascade, category string, custom_category_id nullable FK cascadeOnDelete, amount unsignedBigInteger nullable, starts_on date, timestamps)`, unique `(user_id, category, custom_category_id, starts_on)` (NULL custom ids don't collide in MySQL/SQLite, so writes must go through `updateOrCreate`, never a bare insert). `amount = null` means "no limit from this month on".
- Produces: `SpendingLimit` model — fillable `user_id, category, custom_category_id, amount, starts_on`; casts `category => TransactionCategory`, `starts_on => 'date'`; `user()` and `customCategory()` BelongsTo. Factory default: `category = groceries`, `amount = 800_000`, `starts_on = now()->startOfMonth()`; state `custom(CustomCategory $category)`.
- Produces: `SpendingLimits::forMonth(User $user, CarbonInterface $today): list<LimitRow>` where `LimitRow = array{category: string, customCategoryId: int|null, categoryName: string|null, limit: int|null, spent: int, left: int|null, pace: int|null, status: 'none'|'under'|'close'|'over', aheadOfPace: bool}`. One row per expense category (built-in, then custom by name). `categoryName` is the custom name or null. `left = limit - spent` (negative when over). Limit in force = latest row with `starts_on <= first of $today's month` for that `(category, custom_category_id)`.
- Produces: `SpendingLimits::totalLimits(array $rows): int` — sum of non-null `limit`.

- [ ] **Step 1: Write the failing tests** (travel to `2026-10-15`, as `GoalsTest` does)

```php
test('a category with no limit still shows what was spent', function () {
    Transaction::factory()->for($this->user)->create(['category' => 'groceries', 'amount' => 120_000, 'date' => '2026-10-03']);
    $row = rowFor($this->user, 'groceries');
    expect($row)->toMatchArray(['limit' => null, 'spent' => 120_000, 'left' => null, 'pace' => null, 'status' => 'none', 'aheadOfPace' => false]);
});

test('status is under, close at 80%, and over past the limit', function (int $spent, string $status) {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'food', 'amount' => 1_000_000]);
    Transaction::factory()->for($this->user)->create(['category' => 'food', 'amount' => $spent, 'date' => '2026-10-02']);
    expect(rowFor($this->user, 'food')['status'])->toBe($status);
})->with([[799_999, 'under'], [800_000, 'close'], [1_000_000, 'close'], [1_000_001, 'over']]);

test('pace is the limit spread evenly to today', function () {
    SpendingLimit::factory()->for($this->user)->create(['category' => 'transport', 'amount' => 310_000]);
    Transaction::factory()->for($this->user)->create(['category' => 'transport', 'amount' => 160_000, 'date' => '2026-10-01']);
    expect(rowFor($this->user, 'transport'))->toMatchArray(['pace' => 150_000, 'aheadOfPace' => true, 'left' => 150_000]);
});

test('bill payments, transfers and matched statement rows are not living expenses');
// one bill payment (bill_id set, also via ->onCard()), one transfer, one manual entry + its matched statement row:
// groceries spent === the manual entry's amount only

test('a card purchase counts in the month it was made');      // ->onCard(), spent includes it
test('an expense dated later this month does not count yet'); // date 2026-10-20, spent === 0
test('custom categories are their own rows and other means plain other');
// expense in custom "Coffee" (category other + custom id) and one plain other:
// rows in order food…other then Coffee; Coffee spent and Other spent are separate

test('a limit set in september carries into october until changed');
// row starts_on 2026-09-01 amount 500_000 → October limit 500_000;
// add row starts_on 2026-10-01 amount 700_000 → October 700_000, forMonth(…, '2026-09-10') still 500_000;
// add row starts_on 2026-10-01 amount null instead → October limit null, status 'none'

test('the limits spending adds up to spent this month');
// several categories → sum of spent === (new Projection)->spentThisMonth($user, today)
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --compact tests/Feature/Money/SpendingLimitsTest.php` → FAIL (class `SpendingLimit` not found).

- [ ] **Step 3: Create migration, model, factory, `User::spendingLimits()`.** Migration docblock in the style of `create_custom_categories_table`.

- [ ] **Step 4: Implement `SpendingLimits::forMonth()` and `totalLimits()`.** Two queries: limits with `starts_on <= month start` ordered by `starts_on` (last one wins per key in PHP), and spent grouped by `category, custom_category_id`. Spent query mirrors `Projection::spentThisMonth()`; don't change that method.

- [ ] **Step 5: Run to verify pass** — same command → PASS.

- [ ] **Step 6: Pint and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Models/Money/SpendingLimit.php app/Models/User.php app/Services/Money/SpendingLimits.php database tests/Feature/Money/SpendingLimitsTest.php
git commit -m "feat: store monthly spending limits and work out each category against them"
```

### Task 2: Setting, changing and clearing a limit

**Files:**
- Create: `app/Http/Requests/Money/SpendingLimitRequest.php`, `app/Http/Controllers/Money/SpendingLimitController.php`
- Modify: `routes/web.php` (in the `money.` group, after the `categories.*` routes)
- Test: `tests/Feature/Money/SpendingLimitControllerTest.php`

**Interfaces:**
- Consumes: `SpendingLimit` model (Task 1).
- Produces: `PUT /money/spending-limits` named `money.spending-limits.update` → `SpendingLimitController::update(SpendingLimitRequest): RedirectResponse` (`back()`). Body: `category` (required, in `TransactionCategory::expenseValues()`), `custom_category_id` (nullable, ownership-scoped exists), `amount` (pesos or null). When `custom_category_id` is given, `category` is forced to `other` in `prepareForValidation()`.
- Produces: `SpendingLimitRequest::limitAttributes(): array{category: string, custom_category_id: int|null, amount: int|null}` (amount in centavos).
- Behaviour: `updateOrCreate` on `(user_id, category, custom_category_id, starts_on = first of current month)`.

- [ ] **Step 1: Write the failing tests**

```php
test('setting a limit stores it from this month in centavos');            // put amount 8000 → row amount 800_000, starts_on 2026-10-01
test('changing a limit mid-month leaves last month alone');               // sept row 500_000 exists; put 7000 → new oct row, sept row unchanged
test('changing it twice in a month keeps one row');                       // put twice → one oct row with latest amount
test('clearing a limit stores no limit from this month');                 // put amount null → oct row amount null; forMonth row status 'none'
test('a custom category limit is stored against other plus the category'); // put custom_category_id → category 'other'
test('another user\'s custom category is rejected');                       // assertSessionHasErrors('custom_category_id')
test('income, transfer and adjustment are not limitable', fn (string $category) => /* assertSessionHasErrors('category') */)
    ->with(['income', 'transfer', 'adjustment']);
test('a zero or negative limit is rejected');                             // amount 0 → error 'amount'
test('deleting a custom category removes its limit and its spending counts as other');
// limit on Coffee + limit on Other; expense in Coffee; delete Coffee via route → no Coffee limit rows; Other row spent includes it
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --compact tests/Feature/Money/SpendingLimitControllerTest.php` → FAIL (404 / route not defined).

- [ ] **Step 3: Implement request, controller, route.** Follow `CustomCategoryRequest` / `BillRequest` style; run `php artisan wayfinder:generate` if the Vite plugin isn't running.

- [ ] **Step 4: Run to verify pass** → PASS.

- [ ] **Step 5: Pint and commit** — `git commit -m "feat: set, change and clear a spending limit"`.

### Task 3: Spending limits on the Plan

**Files:**
- Modify: `app/Http/Controllers/Money/PlanController.php`
- Modify: `resources/js/pages/Money/Plan.vue` (new section after C "Yearly and quarterly bills")
- Create: `resources/js/components/SpendingLimitBar.vue` (shared by Plan and Overview)
- Test: `tests/Feature/Money/SpendingLimitPagesTest.php`

**Interfaces:**
- Consumes: `SpendingLimits::forMonth()`, `totalLimits()` (Task 1); `update` from `@/actions/App/Http/Controllers/Money/SpendingLimitController` (Task 2).
- Produces: Plan props `spendingLimits: LimitRow[]` and `limitsOverSafeToSpend: int` = `max(0, totalLimits - safeToSpend)`, where safe to spend comes from `Projection::overview()` called exactly as `OverviewController` calls it (income sources with receipts, goal contributions, recovery months).
- Produces: `SpendingLimitBar.vue` props `{ limit: number; spent: number; pace: number; status: 'under' | 'close' | 'over' }` — a bar filled to `min(spent/limit, 1)`, a thin tick at `pace/limit`, red when over, warning colour when close. Export a `LimitRow` TS type from it.

- [ ] **Step 1: Write the failing tests**

```php
test('the plan lists every expense category with its limit and spending');
// assertInertia: spendingLimits count === count(expenseValues()) + custom count; groceries row limit/spent/status as seeded

test('the plan notes when limits add up to more than safe to spend');
// seed income/bills so safeToSpend is known (reuse a seeder from OverviewTest if one fits); limits total = safe + 500_000
// → limitsOverSafeToSpend === 500_000; with limits below safe → 0
```

- [ ] **Step 2: Run to verify failure** — `php artisan test --compact tests/Feature/Money/SpendingLimitPagesTest.php` → FAIL (missing prop).

- [ ] **Step 3: Add the props to `PlanController`.**

- [ ] **Step 4: Run to verify pass** → PASS.

- [ ] **Step 5: Build section D in `Plan.vue`** using `BlueprintSectionHeading letter="D" title="Spending limits"`, matching the row style of section C. Each row: category label (`categoryName ?? categoryLabel(category)`), "₱spent of ₱limit", `SpendingLimitBar`, and status words: "₱X over" when over, "ahead of pace" when `aheadOfPace`, else "₱X left"; no-limit rows show "₱spent spent" and an "Add limit" action. Edit inline with `MoneyInput` and `useForm().put(update().url)`; an empty amount clears the limit. When `limitsOverSafeToSpend > 0`, show `InfoNote`: "Your limits are ₱X more than safe to spend."

- [ ] **Step 6: Lint, types, commit** — `git commit -m "feat: set and watch spending limits on the plan"`.

### Task 4: Close-or-over line on the Overview

**Files:**
- Modify: `app/Http/Controllers/Money/OverviewController.php`, `resources/js/pages/Money/Overview.vue` (beneath the "spent so far" line near line 514)
- Test: `tests/Feature/Money/SpendingLimitPagesTest.php`

**Interfaces:**
- Consumes: `SpendingLimits::forMonth()`; `LimitRow` type (Task 3).
- Produces: Overview prop `limitAlerts: LimitRow[]` — rows with status `close` or `over`, over first, then close, each group in category order.

- [ ] **Step 1: Failing test** — `test('the overview lists only limits that are close or over, over first')`: one under, one close, one over, one without limit → `limitAlerts` is `[over, close]` by category.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Add the prop.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Render** — hidden when empty; otherwise one compact line, e.g. "Groceries ₱1,200 over · Transport 86% used", linking to `/money/plan` via Wayfinder.
- [ ] **Step 6: Lint, types, commit** — `git commit -m "feat: flag spending limits that are close or over on the overview"`.

### Task 5: What's left in the entry card on Transactions

**Files:**
- Modify: `app/Http/Controllers/Money/TransactionsController.php` (`index`), `resources/js/pages/Money/Transactions.vue` (new-entry card, near the amount `MoneyInput` around line 733)
- Test: `tests/Feature/Money/SpendingLimitPagesTest.php`

**Interfaces:**
- Consumes: `SpendingLimits::forMonth($user, today)` regardless of the `month` query — limits are always this month.
- Produces: Transactions prop `spendingLimits: LimitRow[]` — rows with a non-null limit only.

- [ ] **Step 1: Failing test** — `test('transactions get this month\'s limits even when viewing another month')`: limit on groceries, `get('/money/transactions?month=2026-08')` → `spendingLimits` has one groceries row with October spent.
- [ ] **Step 2: Run** → FAIL.
- [ ] **Step 3: Add the prop.**
- [ ] **Step 4: Run** → PASS.
- [ ] **Step 5: Render** — when the entry is an expense, its date is in `currentMonth`, no bill is chosen, and the chosen category (`form.category` + `form.custom_category_id`) has a row: "₱left left in {category} this month"; once an amount is entered, "₱X left after this" or "₱X over after this". Use the evaluated amount `MoneyInput` already produces.
- [ ] **Step 6: Lint, types, commit** — `git commit -m "feat: show what's left of a category's limit when logging spending"`.

### Task 6: Limits in the MCP overview

**Files:**
- Modify: `app/Mcp/Tools/Money/GetMoneyOverview.php`
- Test: `tests/Feature/Mcp/GetMoneyOverviewTest.php`

**Interfaces:**
- Consumes: `SpendingLimits::forMonth()`.
- Produces: response key `spendingLimits: list<array{category: string, limit: int, spent: int, left: int, pace: int, status: string}>` — rows with a limit only; `category` is the custom name or the built-in value. Append to the tool description: "…and this month's spending limits per category with what has been spent against each."

- [ ] **Step 1: Failing tests** — `test('the overview includes spending limits with spending against them')` and `test('spending limits are only the user\'s own')` (a second user's limit with a distinctive amount like `98_765_432` is not in the response — same idea as `MoneyIsolationTest`).
- [ ] **Step 2: Run** `php artisan test --compact tests/Feature/Mcp/GetMoneyOverviewTest.php` → FAIL.
- [ ] **Step 3: Implement.**
- [ ] **Step 4: Run** the file plus `tests/Feature/Mcp/ReadOnlyGuaranteeTest.php` → PASS.
- [ ] **Step 5: Pint and commit** — `git commit -m "feat: include spending limits in the money MCP overview"`.

### Task 7: Check it end to end

- [ ] **Step 1:** `php artisan test --compact tests/Feature/Money tests/Feature/Mcp` → all pass.
- [ ] **Step 2:** In the browser at the Herd URL (`get-absolute-url`): set a groceries limit on the Plan, log a groceries expense on Transactions and watch the "left" line change, push it over and confirm the Overview line and red bar. Check at phone width and in dark mode. Fix anything found in the owning task's files and commit.
