# Ledger Manual Transaction Entry Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a user log income, expense, and transfer transactions from `/ledger`, calling the existing `LedgerService`, so account balances stop being edited by hand and start having a real transaction trail.

**Architecture:** One new controller action (`LedgerController::storeTransaction`) validates input (including a `Rule::exists(...)->where('user_id', ...)` ownership check on every account id, matching `PayoffPlanController::linkDebt`'s existing pattern) and dispatches to `LedgerService::recordIncome`/`recordExpense`/`recordTransfer` — no new service logic, the service already does the right thing. A new inline form on `Ledger.vue`, following the same `useForm` + inline-row pattern already used for account add/edit, posts to it. Before entry ships, a real data-loss bug in the transfer-leg foreign keys gets fixed first (Task 1), since transaction entry is what makes it start mattering.

**Tech Stack:** Laravel 13 (Pest 4 feature tests, SQLite with foreign keys enabled), Inertia v3 + Vue 3, Laravel Wayfinder for typed route/action imports.

**Spec:** `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` (Transaction model, §"Transaction"); continuity notes in `docs/superpowers/plans/2026-08-27-money-section-debt-focus-handoff.md` ("Suggested next step" → step 2).

## Global Constraints

- `Account.is_liability` is the single sign-convention flag — never branch on `type` for balance math. (Already enforced inside `LedgerService::applyEffect`; this plan doesn't touch that method.)
- All balance mutation goes through `LedgerService` — no controller ever calls `$account->increment('balance', ...)` directly.
- A transfer is one `Transaction` row with `from_account_id`/`to_account_id` — never two independent rows.
- `occurred_at` is a plain date (`Y-m-d`), not a datetime.
- Every account id accepted from the client must be validated as owned by the authenticated user before any `LedgerService` call touches it (IDOR prevention) — use `Rule::exists('debt_focus_accounts', 'id')->where('user_id', $userId)`, the pattern already established in `PayoffPlanController::linkDebt`.

---

### Task 1: Stop transfer deletion from destroying the surviving account's history

**Problem:** `debt_focus_transactions.from_account_id` and `to_account_id` are both `cascadeOnDelete()`. Deleting one side of a transfer deletes the *whole transaction row* — silently erasing the surviving account's transfer history too. This is flagged as a known follow-up in the ledger-accounts-ui handoff and is currently unreachable (nothing creates transactions outside Tinker/tests), but Task 3 of this plan makes it reachable from the UI, so it must be fixed first.

**Files:**
- Create: `database/migrations/2026_08_28_200000_null_debt_focus_transaction_transfer_legs_on_delete.php`
- Modify: `app/Http/Controllers/LedgerController.php:79-91` (the `recentTransactions` counterpart logic)
- Test: `tests/Feature/DebtFocus/LedgerControllerTest.php`

**Interfaces:**
- Produces: no change to any public method signature — `LedgerController::recentTransactions` still returns the same array shape, just with `counterpart` now possibly `'(deleted account)'` instead of `null` for a transfer whose other leg was deleted.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/DebtFocus/LedgerControllerTest.php`:

```php
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --compact --filter="deleting one side of a transfer"`
Expected: FAIL — today the whole transaction row cascade-deletes with `checking`, so `Savings`'s `recent_transactions` is empty and indexing `[0]` fails.

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration null_debt_focus_transaction_transfer_legs_on_delete --no-interaction
```

Replace its contents with:

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
            $table->dropForeign(['from_account_id']);
            $table->dropForeign(['to_account_id']);
        });

        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->foreign('from_account_id')->references('id')->on('debt_focus_accounts')->nullOnDelete();
            $table->foreign('to_account_id')->references('id')->on('debt_focus_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->dropForeign(['from_account_id']);
            $table->dropForeign(['to_account_id']);
        });

        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->foreign('from_account_id')->references('id')->on('debt_focus_accounts')->cascadeOnDelete();
            $table->foreign('to_account_id')->references('id')->on('debt_focus_accounts')->cascadeOnDelete();
        });
    }
};
```

Run: `php artisan migrate --no-interaction`
Expected: migration runs cleanly against the local SQLite database.

- [ ] **Step 4: Update the counterpart fallback in `LedgerController`**

In `app/Http/Controllers/LedgerController.php`, change the `$counterpart` computation inside `recentTransactions`:

```php
$counterpart = match (true) {
    $transaction->type !== 'transfer' => null,
    $transaction->from_account_id === $account->id => $transaction->toAccount?->name ?? '(deleted account)',
    default => $transaction->fromAccount?->name ?? '(deleted account)',
};
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --compact --filter="deleting one side of a transfer"`
Expected: PASS

- [ ] **Step 6: Run the full DebtFocus suite and Pint**

Run: `php artisan test --compact --filter=DebtFocus`
Expected: all pass (existing "deletes an owned account and cascades its transactions" test is unaffected — it only exercises `account_id`, which stays `cascadeOnDelete`).

Run: `vendor/bin/pint --dirty --format agent`
Expected: passed (fix any reported issues before continuing)

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_08_28_200000_null_debt_focus_transaction_transfer_legs_on_delete.php app/Http/Controllers/LedgerController.php tests/Feature/DebtFocus/LedgerControllerTest.php
git commit -m "fix: preserve surviving account's transfer history when the other leg is deleted"
```

---

### Task 2: `storeTransaction` endpoint

**Files:**
- Modify: `app/Http/Controllers/LedgerController.php` (add `storeTransaction` method + a private `accountOwnershipRule` helper)
- Modify: `routes/web.php:81` (add the route directly after the existing `ledger.accounts.destroy` line)
- Test: `tests/Feature/DebtFocus/LedgerControllerTest.php`

**Interfaces:**
- Consumes: `App\Services\DebtFocus\LedgerService::recordIncome(Account $account, float $amount, ?string $description, ?CarbonInterface $occurredAt): Transaction`, `recordExpense(...)` (same shape), `recordTransfer(Account $from, Account $to, float $amount, ?string $description, ?CarbonInterface $occurredAt): Transaction` — all already implemented in `app/Services/DebtFocus/LedgerService.php`, unchanged by this task.
- Produces: `POST /ledger/transactions` (route name `ledger.transactions.store`) — redirects back on success (`302`), or back with session validation errors on failure. No new Inertia props; the existing `index()` response (accounts + `recent_transactions`) already reflects new transactions on the next page load.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/DebtFocus/LedgerControllerTest.php`:

```php
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
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact --filter=LedgerControllerTest`
Expected: FAIL — `POST /ledger/transactions` doesn't exist yet (404s), so every new test's `assertRedirect()`/`assertSessionHasErrors()` fails.

- [ ] **Step 3: Add the route**

In `routes/web.php`, directly after line 81 (`Route::delete('/ledger/accounts/{account}', ...)`):

```php
    Route::post('/ledger/transactions', [LedgerController::class, 'storeTransaction'])->name('ledger.transactions.store');
```

- [ ] **Step 4: Implement `storeTransaction`**

In `app/Http/Controllers/LedgerController.php`, add the imports:

```php
use App\Services\DebtFocus\LedgerService;
use Carbon\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;
```

Add the method (after `destroyAccount`, before `recentTransactions`):

```php
public function storeTransaction(Request $request, LedgerService $ledger): RedirectResponse
{
    $ownedAccount = $this->accountOwnershipRule($request->user()->id);

    $validated = $request->validate([
        'type' => ['required', 'string', 'in:income,expense,transfer'],
        'account_id' => ['required_unless:type,transfer', 'nullable', 'integer', $ownedAccount],
        'from_account_id' => ['required_if:type,transfer', 'nullable', 'integer', 'different:to_account_id', $ownedAccount],
        'to_account_id' => ['required_if:type,transfer', 'nullable', 'integer', $ownedAccount],
        'amount' => ['required', 'numeric', 'min:0.01'],
        'description' => ['nullable', 'string', 'max:255'],
        'occurred_at' => ['required', 'date'],
    ]);

    $amount = (float) $validated['amount'];
    $description = $validated['description'] ?? null;
    $occurredAt = Carbon::parse($validated['occurred_at']);

    match ($validated['type']) {
        'income' => $ledger->recordIncome(Account::findOrFail($validated['account_id']), $amount, $description, $occurredAt),
        'expense' => $ledger->recordExpense(Account::findOrFail($validated['account_id']), $amount, $description, $occurredAt),
        'transfer' => $ledger->recordTransfer(Account::findOrFail($validated['from_account_id']), Account::findOrFail($validated['to_account_id']), $amount, $description, $occurredAt),
    };

    return back();
}

private function accountOwnershipRule(int $userId): Exists
{
    return Rule::exists('debt_focus_accounts', 'id')->where('user_id', $userId);
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact --filter=LedgerControllerTest`
Expected: PASS (all tests in the file, including the pre-existing ones)

- [ ] **Step 6: Run the full DebtFocus suite and Pint**

Run: `php artisan test --compact --filter=DebtFocus`
Expected: all pass

Run: `vendor/bin/pint --dirty --format agent`
Expected: passed

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/LedgerController.php routes/web.php tests/Feature/DebtFocus/LedgerControllerTest.php
git commit -m "feat: add POST /ledger/transactions to log income, expense, and transfer entries"
```

---

### Task 3: Quick transaction-entry form on `/ledger`

**Files:**
- Modify: `resources/js/pages/Ledger.vue`

**Interfaces:**
- Consumes: `storeTransaction` from `@/actions/App/Http/Controllers/LedgerController` (Wayfinder-generated once Task 2's route exists — regenerate with `php artisan wayfinder:generate --no-interaction` before this task's frontend work). `props.accounts: LedgerAccount[]` (already defined in `Ledger.vue`) supplies the account-picker options — no new backend props needed.
- Produces: no new exports — this is a leaf page component.

- [ ] **Step 1: Regenerate Wayfinder actions**

Run: `php artisan wayfinder:generate --no-interaction`
Expected: `resources/js/actions/App/Http/Controllers/LedgerController.ts` now exports a `storeTransaction` function alongside the existing `storeAccount`/`updateAccount`/`destroyAccount`.

- [ ] **Step 2: Add the entry form state and types**

In `resources/js/pages/Ledger.vue`, add to the `<script setup>` block (after the existing `import` line):

```ts
import { destroyAccount, storeAccount, storeTransaction, updateAccount } from '@/actions/App/Http/Controllers/LedgerController';
```

Add a new type and reactive state (after the `openAddRow`/`openEditRow`/`cancelRow`/`onTypeChange`/`submitRow` block, before `removeAccount`):

```ts
// ── Log a transaction ───────────────────────────────────────────────────

type TransactionType = 'income' | 'expense' | 'transfer';

const transactionFormOpen = ref(false);
const transactionForm = useForm({
    type: 'expense' as TransactionType,
    account_id: null as number | null,
    from_account_id: null as number | null,
    to_account_id: null as number | null,
    amount: 0,
    description: null as string | null,
    occurred_at: new Date().toISOString().slice(0, 10),
});

function openTransactionForm(): void {
    transactionFormOpen.value = true;
    expandedId.value = null;
    transactionForm.clearErrors();
    transactionForm.type = 'expense';
    transactionForm.account_id = props.accounts[0]?.id ?? null;
    transactionForm.from_account_id = props.accounts[0]?.id ?? null;
    transactionForm.to_account_id = props.accounts[1]?.id ?? null;
    transactionForm.amount = 0;
    transactionForm.description = null;
    transactionForm.occurred_at = new Date().toISOString().slice(0, 10);
}

function cancelTransactionForm(): void {
    transactionFormOpen.value = false;
    transactionForm.clearErrors();
}

function submitTransactionForm(): void {
    transactionForm.post(storeTransaction().url, {
        preserveScroll: true,
        onSuccess: () => (transactionFormOpen.value = false),
    });
}
```

- [ ] **Step 3: Add the form's markup**

In the `<template>`, add a new card between the header (`</div>` closing `.mb-5`) and the existing "Account list" card:

```html
<!-- Log a transaction -->
<div
    class="mb-5 rounded-[22px] p-6"
    style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
>
    <div class="flex items-center justify-between">
        <h2 class="font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Log a transaction</h2>
        <button
            v-if="!transactionFormOpen"
            type="button"
            class="flex items-center gap-1 text-[13.5px] font-medium"
            style="color: var(--hab-accent)"
            :disabled="props.accounts.length === 0"
            @click="openTransactionForm"
        >
            <span class="material-symbols-rounded" style="font-size: 18px">add</span>
            New entry
        </button>
    </div>

    <p v-if="props.accounts.length === 0" class="mt-2 text-[14px]" style="color: var(--hab-muted)">
        Add an account first to start logging transactions.
    </p>

    <div v-if="transactionFormOpen" class="mt-4 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
        <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
            <div>
                <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Type</label>
                <select v-model="transactionForm.type" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                    <option value="income">Income</option>
                    <option value="expense">Expense</option>
                    <option value="transfer">Transfer</option>
                </select>
            </div>

            <div v-if="transactionForm.type !== 'transfer'">
                <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Account</label>
                <select v-model.number="transactionForm.account_id" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                    <option v-for="account in props.accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                </select>
            </div>

            <template v-else>
                <div>
                    <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">From</label>
                    <select v-model.number="transactionForm.from_account_id" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                        <option v-for="account in props.accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                </div>
                <div>
                    <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">To</label>
                    <select v-model.number="transactionForm.to_account_id" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)">
                        <option v-for="account in props.accounts" :key="account.id" :value="account.id">{{ account.name }}</option>
                    </select>
                </div>
            </template>

            <div>
                <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Amount</label>
                <input v-model.number="transactionForm.amount" type="number" min="0.01" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Date</label>
                <input v-model="transactionForm.occurred_at" type="date" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
            </div>
            <div>
                <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Description (optional)</label>
                <input v-model="transactionForm.description" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
            </div>
        </div>
        <div class="mt-2 flex items-center gap-2">
            <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50" style="background: var(--hab-accent)" :disabled="transactionForm.processing" @click="submitTransactionForm">
                Confirm
            </button>
            <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)" :disabled="transactionForm.processing" @click="cancelTransactionForm">
                Cancel
            </button>
        </div>
        <div v-if="transactionForm.errors.type" class="mt-2 text-[12px]" style="color: #c0512c">{{ transactionForm.errors.type }}</div>
        <div v-if="transactionForm.errors.account_id" class="mt-2 text-[12px]" style="color: #c0512c">{{ transactionForm.errors.account_id }}</div>
        <div v-if="transactionForm.errors.from_account_id" class="mt-2 text-[12px]" style="color: #c0512c">{{ transactionForm.errors.from_account_id }}</div>
        <div v-if="transactionForm.errors.to_account_id" class="mt-2 text-[12px]" style="color: #c0512c">{{ transactionForm.errors.to_account_id }}</div>
        <div v-if="transactionForm.errors.amount" class="mt-2 text-[12px]" style="color: #c0512c">{{ transactionForm.errors.amount }}</div>
    </div>
</div>
```

- [ ] **Step 4: Verify in the browser**

Start the dev server (`composer run dev` or ask the user to run it if not already running), open `/ledger`, and:
1. Confirm "Log a transaction" renders with a disabled/hidden entry button when there are zero accounts, and an enabled "New entry" button once at least one account exists.
2. Click "New entry", select "Income" against an account, enter an amount and date, submit — confirm the account's balance and transaction history (expand the account row) update.
3. Switch type to "Transfer", pick two different accounts, submit — confirm both accounts' balances move correctly (source down, destination up, respecting `is_liability`).
4. Trigger a validation error (e.g. amount `0`) — confirm the inline error message renders and the form does not silently succeed.

Use `read_console_messages` and `read_network_requests` (or the Boost `browser-logs` tool) to confirm no JS errors and that the `POST /ledger/transactions` request returns a redirect, not a validation failure, for the success cases.

- [ ] **Step 5: Run the full DebtFocus suite once more**

Run: `php artisan test --compact --filter=DebtFocus`
Expected: all pass (this task made no backend changes, but confirms nothing regressed)

- [ ] **Step 6: Commit**

```bash
git add resources/js/pages/Ledger.vue resources/js/actions/App/Http/Controllers/LedgerController.ts
git commit -m "feat: add quick transaction-entry form to the Ledger page"
```

---

## Not in scope for this plan

- Resolving the `LedgerController` "Starting balance" label / no-adjustment-transaction-on-edit gap noted in the handoff doc — that's about editing an account's balance directly, a separate concern from logging transactions against it.
- Spend-vs-payment display for revolving debts (handoff doc step 4) — depends on this plan shipping first, so transaction history actually exists to query.
- Any budgeting (`Bill`/`Envelope`) work — still a separate, undesigned schema piece per the spec's "Open questions."
