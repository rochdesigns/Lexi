# Spend-vs-Payment Display for Revolving Debts Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** On `/payoff-plan`, show each linked revolving debt's month-to-date "paid $X, spent $Y → net $Z toward payoff" so the user can see whether active card use is undermining their payoff plan.

**Architecture:** Pure read path, no new schema. `PayoffPlanController::index` gains a per-debt computed prop (`spend_vs_payment`) built by summing existing `Transaction` rows for the debt's `linked_account_id` — `expense` transactions are "spent", `transfer` transactions crediting the account (`to_account_id`) are "paid". `PayoffPlan.vue` renders the figure under any revolving debt that has one.

**Tech Stack:** Laravel 13 (Eloquent query builder aggregates), Inertia v3 + Vue 3 (existing `PayoffPlan.vue` page), Pest 4.

**Spec:** `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` (decision #4 / "Revolving vs. installment debt", line 48: *"each period surfaces 'paid $X, spent $Y this period → net $Z toward payoff' ... 'spent' is the sum of `expense` transactions on the debt's `linked_account_id` this period, 'paid' is the sum of `transfer` transactions crediting it — no separate tracking field needed."*). Continuity doc: `docs/superpowers/plans/2026-08-27-money-section-debt-focus-handoff.md` ("Suggested next step", item 4).

## Global Constraints

- No new migrations, models, or tables — this reads the existing `debt_focus_transactions` table via the existing `Transaction` model and `debt_focus_debts.debt_type` / `linked_account_id` columns.
- Only debts where `debt_type === 'revolving'` AND `linked_account_id !== null` get a figure; everything else gets `spend_vs_payment: null` (mirrors how `payoff_month`/`total_interest` already null out for edge cases in this controller).
- "This period" = the current calendar month to date (1st of the current month through now). There is no `Bill`/`Envelope` period system yet (per the handoff doc, that's still undesigned), so this is the simplest period definition consistent with the spec's intent — do not build period infrastructure as part of this plan.
- Values are computed live on every page load (`sum()` queries), not cached or stored — consistent with the payoff engine's existing "always live, never a stale target" philosophy (spec section "Missed payments").
- Follow existing code style in `PayoffPlanController.php` (private helper methods returning nullable arrays, like `computeProgress`/`computePace`) and in `PayoffPlan.vue` (typed props, inline `style` with `var(--hab-*)` tokens, `formatMoney` helper).

---

## Task 1: `spend_vs_payment` computed prop on `PayoffPlanController`

**Files:**
- Modify: `app/Http/Controllers/PayoffPlanController.php`
- Test: `tests/Feature/DebtFocus/PayoffPlanControllerTest.php`

**Interfaces:**
- Consumes: `App\Models\DebtFocus\Transaction` (existing — `type` in `income|expense|transfer`, `account_id`, `from_account_id`, `to_account_id`, `amount`, `occurred_at` date column), `App\Models\DebtFocus\Debt` (`debt_type`, `linked_account_id`, `user_id` — all existing columns).
- Produces: each element of the `debts` Inertia prop gains `spend_vs_payment: {spent: float, paid: float, net: float, period_label: string} | null`. Task 2's frontend type must match this shape exactly.

- [ ] **Step 1: Write the failing tests**

Open `tests/Feature/DebtFocus/PayoffPlanControllerTest.php` and add these tests at the end of the file (the file currently ends after the `computePace`-related tests — append after the last `test(...)` block, keeping the existing `use` imports at the top: `Account`, `Debt`, `Settings`, `User`; add `LedgerService` to that import list if it isn't already imported there — check the top of the file first, since `LedgerControllerTest.php` imports it as `use App\Services\DebtFocus\LedgerService;`):

```php
test('a linked revolving debt shows spend vs payment for the current month', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create(['balance' => 500]);
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create([
        'debt_type' => 'revolving',
        'linked_account_id' => $card->id,
        'balance' => 500,
    ]);
    $ledger = new LedgerService;
    $ledger->recordExpense($card, 150, 'Groceries', now());
    $ledger->recordTransfer($checking, $card, 200, 'Card payment', now());

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $spendVsPayment = $response->viewData('page')['props']['debts'][0]['spend_vs_payment'];

    expect($spendVsPayment['spent'])->toBe(150.0);
    expect($spendVsPayment['paid'])->toBe(200.0);
    expect($spendVsPayment['net'])->toBe(50.0);
});

test('spend vs payment excludes transactions from a previous month', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    $debt = Debt::factory()->for($user)->create(['debt_type' => 'revolving', 'linked_account_id' => $card->id]);
    (new LedgerService)->recordExpense($card, 999, 'Old spend', now()->subMonthNoOverflow()->startOfMonth());

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $spendVsPayment = $response->viewData('page')['props']['debts'][0]['spend_vs_payment'];

    expect($spendVsPayment['spent'])->toBe(0.0);
    expect($spendVsPayment['paid'])->toBe(0.0);
});

test('an installment debt has no spend vs payment figure even if linked', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    Debt::factory()->for($user)->create(['debt_type' => 'installment', 'linked_account_id' => $card->id]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();

    expect($response->viewData('page')['props']['debts'][0]['spend_vs_payment'])->toBeNull();
});

test('a revolving debt with no linked account has no spend vs payment figure', function () {
    $user = User::factory()->create();
    Debt::factory()->for($user)->create(['debt_type' => 'revolving', 'linked_account_id' => null]);

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();

    expect($response->viewData('page')['props']['debts'][0]['spend_vs_payment'])->toBeNull();
});

test('spend vs payment only counts expenses and incoming transfers on the linked account, not payments made from it', function () {
    $user = User::factory()->create();
    $card = Account::factory()->for($user)->liability()->create();
    $checking = Account::factory()->for($user)->create(['balance' => 1000]);
    $debt = Debt::factory()->for($user)->create(['debt_type' => 'revolving', 'linked_account_id' => $card->id]);
    $ledger = new LedgerService;
    $ledger->recordIncome($checking, 50, 'Unrelated income', now());
    $ledger->recordTransfer($card, $checking, 20, 'Refund routed elsewhere', now());

    $response = $this->actingAs($user)->get('/payoff-plan')->assertOk();
    $spendVsPayment = $response->viewData('page')['props']['debts'][0]['spend_vs_payment'];

    expect($spendVsPayment['spent'])->toBe(0.0);
    expect($spendVsPayment['paid'])->toBe(0.0);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=PayoffPlanControllerTest`
Expected: FAIL — the new tests error on `$response->viewData('page')['props']['debts'][0]['spend_vs_payment']` being an undefined array key (the prop doesn't exist yet).

- [ ] **Step 3: Import `Transaction` in the controller**

In `app/Http/Controllers/PayoffPlanController.php`, add to the `use` block at the top (alphabetically among the existing `App\Models\DebtFocus\*` imports, after `App\Models\DebtFocus\Settings`):

```php
use App\Models\DebtFocus\Transaction;
```

- [ ] **Step 4: Add the `spendVsPayment` helper**

In `app/Http/Controllers/PayoffPlanController.php`, add this private method after `percentPaid()` (which currently ends the block right before `computeProgress()`):

```php
    /**
     * @return array{spent: float, paid: float, net: float, period_label: string}|null
     */
    private function spendVsPayment(Debt $debt): ?array
    {
        if ($debt->debt_type !== 'revolving' || $debt->linked_account_id === null) {
            return null;
        }

        $periodStart = Carbon::now()->startOfMonth()->toDateString();

        $spent = (float) Transaction::query()
            ->where('user_id', $debt->user_id)
            ->where('type', 'expense')
            ->where('account_id', $debt->linked_account_id)
            ->where('occurred_at', '>=', $periodStart)
            ->sum('amount');

        $paid = (float) Transaction::query()
            ->where('user_id', $debt->user_id)
            ->where('type', 'transfer')
            ->where('to_account_id', $debt->linked_account_id)
            ->where('occurred_at', '>=', $periodStart)
            ->sum('amount');

        return [
            'spent' => round($spent, 2),
            'paid' => round($paid, 2),
            'net' => round($paid - $spent, 2),
            'period_label' => Carbon::now()->format('F Y'),
        ];
    }
```

- [ ] **Step 5: Wire it into the `debts` prop**

In `app/Http/Controllers/PayoffPlanController.php`, in `index()`, find the `$debtProps` map (it currently ends with `'total_interest' => $perDebt->get($debt->id)['totalInterest'] ?? null,`) and add a new line right after it:

```php
            'total_interest' => $perDebt->get($debt->id)['totalInterest'] ?? null,
            'spend_vs_payment' => $this->spendVsPayment($debt),
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=PayoffPlanControllerTest`
Expected: PASS, all tests including the 5 new ones.

- [ ] **Step 7: Run the full `DebtFocus` suite to check for regressions**

Run: `php artisan test --compact --filter=DebtFocus`
Expected: PASS, all tests (91 previously + 5 new = 96).

- [ ] **Step 8: Format and commit**

Run: `vendor/bin/pint --dirty --format agent`
Expected: `{"tool":"pint","result":"passed"}` (or auto-fixes applied — re-check with the same command if it reports fixes).

```bash
git add app/Http/Controllers/PayoffPlanController.php tests/Feature/DebtFocus/PayoffPlanControllerTest.php
git commit -m "feat: compute spend-vs-payment for linked revolving debts on /payoff-plan"
```

---

## Task 2: Render spend-vs-payment on `/payoff-plan`

**Files:**
- Modify: `resources/js/pages/PayoffPlan.vue`

**Interfaces:**
- Consumes: `spend_vs_payment: {spent: number, paid: number, net: number, period_label: string} | null` on each `PayoffDebt` (produced by Task 1). Existing `formatMoney(n: number): string` helper already defined in this file.
- Produces: no new exports — this is a leaf page component.

- [ ] **Step 1: Add the `SpendVsPayment` type**

In `resources/js/pages/PayoffPlan.vue`, add a new type after the existing `type DebtType = 'revolving' | 'installment';` line:

```ts
type SpendVsPayment = {
    spent: number;
    paid: number;
    net: number;
    period_label: string;
};
```

- [ ] **Step 2: Add the field to `PayoffDebt`**

In the same file, in the `PayoffDebt` type, add a new field right after `total_interest: number | null;`:

```ts
    total_interest: number | null;
    spend_vs_payment: SpendVsPayment | null;
```

- [ ] **Step 3: Render it in the debt row**

In the `<template>`, inside the debt list loop, find the progress-bar `<div>` (the one with `class="mt-1.5 h-1.5 max-w-[220px] ..."`, currently the last element inside the `<button type="button" class="min-w-0 flex-1 text-left" @click="openEditRow(debt)">` block before that button's closing `</button>` tag). Add a new block immediately after that progress-bar `<div>` and before the button's closing tag:

```html
                        <div
                            class="mt-1.5 h-1.5 max-w-[220px] overflow-hidden rounded-full"
                            style="background: var(--hab-chip-fill)"
                        >
                            <div
                                class="h-full rounded-full"
                                :style="{ background: 'var(--hab-accent)', width: `${Math.round(debt.percent_paid * 100)}%` }"
                            ></div>
                        </div>
                        <div
                            v-if="debt.debt_type === 'revolving' && debt.spend_vs_payment !== null"
                            class="mt-1.5 text-[11.5px]"
                            style="color: var(--hab-muted)"
                        >
                            {{ formatMoney(debt.spend_vs_payment.paid) }} paid · {{ formatMoney(debt.spend_vs_payment.spent) }} spent this month →
                            <span :style="{ color: debt.spend_vs_payment.net >= 0 ? 'var(--hab-accent)' : '#c0512c' }">
                                net {{ formatMoney(debt.spend_vs_payment.net) }}
                            </span>
                        </div>
```

(The first `<div>` above — the progress bar — already exists; it's shown here only so the new block's placement is unambiguous. Only add the second `<div v-if="debt.debt_type === 'revolving' ...">` block.)

- [ ] **Step 4: Verify in the browser**

Start the dev server if it isn't already running (`composer run dev`, or ask the user), then:

1. In Tinker or via the UI, create a revolving debt linked to a liability account with at least one `expense` transaction and one `transfer` payment into it this month (e.g. reuse the `/ledger` "Log a transaction" flow from an existing liability account, then link that account to a debt on `/payoff-plan`).
2. Open `/payoff-plan` and confirm the linked revolving debt's row shows a line like "₱200.00 paid · ₱150.00 spent this month → net ₱50.00" beneath its progress bar, with the net figure in the accent color (green-ish) when non-negative.
3. Confirm an installment debt, and any revolving debt with no linked account, show no such line.
4. Confirm no console errors via `read_console_messages` (or the Boost `browser-logs` tool).

If login/session issues block interactive browser verification in this environment (as encountered in the prior cleanup pass), fall back to confirming via `read_network_requests`/`get_page_text` against an authenticated session, or note the limitation explicitly rather than claiming visual verification that didn't happen.

- [ ] **Step 5: Run the full `DebtFocus` suite once more**

Run: `php artisan test --compact --filter=DebtFocus`
Expected: PASS (no backend files changed in this task, but confirms nothing was accidentally broken).

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/PayoffPlan.vue
git commit -m "feat: show paid-vs-spent for linked revolving debts on /payoff-plan"
```
