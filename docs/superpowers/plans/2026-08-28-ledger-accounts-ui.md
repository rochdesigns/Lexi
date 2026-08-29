# Ledger Accounts UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the `DebtFocus` ledger (`Account`, `Transaction`, `LedgerService`) its first UI — a new `/ledger` page where a user can create, edit, and delete `Account` records, and see each account's balance and recent transaction history.

**Architecture:** One new controller (`App\Http\Controllers\LedgerController`) and one new Inertia page (`resources/js/pages/Ledger.vue`), following the exact inline-add/edit-row pattern already used by `PayoffPlanController`/`PayoffPlan.vue`. A new `Transaction::scopeInvolvingAccount()` local scope answers "which transactions touch this account" across all three of `Transaction`'s account FKs (`account_id`, `from_account_id`, `to_account_id`), since `Account` has no reverse relation for any of them today. This is deliberately its own standalone page and sidebar entry (not nested under `/payoff-plan`) at the user's direction — it is the start of the section that will eventually replace `/accounts`+`/money`+`/debt`, kept fully separate from that old system until it's ready for a single cutover. No transaction *entry* here — that's the next planned step (manual transaction entry UI) and stays out of scope.

**Tech Stack:** Laravel 13 / PHP 8.4, Eloquent, Inertia v3 + Vue 3, Tailwind v4, Pest 4, Laravel Wayfinder.

**Spec:** `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` (`Account` and `Transaction` entity sections) and `docs/superpowers/plans/2026-08-27-money-section-debt-focus-handoff.md` ("Suggested next step", item 1).

## Global Constraints

- `DebtFocus` stays a namespace completely separate from the app's existing `/money`+`/debt`+`/accounts` implementation (`App\Http\Controllers\AccountController`, `MoneyController`, `DebtController`) — do not touch those files, and do not reuse their route path (`/accounts`) or route names (`accounts.*`). The old `AccountController` already owns that path and controller class name.
- New routes live under `/ledger` (confirmed with the user): `App\Http\Controllers\LedgerController`, route names `ledger.index` / `ledger.accounts.store` / `ledger.accounts.update` / `ledger.accounts.destroy`.
- Ownership scoping on every mutating route: `abort_if($account->user_id !== $request->user()->id, 403)`, matching the exact pattern already used in `PayoffPlanController::updateDebt`/`destroyDebt`.
- UI must reuse the existing CSS custom-property design tokens already used throughout `PayoffPlan.vue` (`--hab-ink`, `--hab-muted`, `--hab-card-bg`, `--hab-card-border`, `--hab-chip-fill`, `--hab-accent`, `--hab-hairline`, `--hab-faint`) and the same inline add/edit-row interaction pattern (`editingId` ref of `number | 'new' | null`, a `useForm` object, `openAddRow`/`openEditRow`/`cancelRow`/`submitRow`). Do not introduce a modal, a separate route per form, or a new design language for this page.
- `formatMoney` is duplicated locally per page in this codebase (confirmed in `PayoffPlan.vue`, `Money.vue`, `Forecast.vue`, `Debt.vue`, `Accounts.vue`, `SetAsides.vue`, `settings/Bills.vue` — no shared utility exists). Follow that convention; do not extract a shared helper as part of this plan.
- Run `vendor/bin/pint --dirty --format agent` after any PHP change. Run `php artisan wayfinder:generate` after adding the controller/routes so the frontend can import `@/actions/App/Http/Controllers/LedgerController`.
- This plan is backend + frontend, but still **no transaction entry**: `recent_transactions` in Task 1's props are read-only history. Manual transaction entry (calling `LedgerService::recordIncome`/`recordExpense`/`recordTransfer` from a form) is the next handoff-doc step, out of scope here.

---

### Task 1: `LedgerController`, the account-involvement scope, and route wiring

**Files:**
- Create: `app/Http/Controllers/LedgerController.php`
- Modify: `app/Models/DebtFocus/Transaction.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/DebtFocus/LedgerControllerTest.php` (new)
- Test: `tests/Feature/DebtFocus/TransactionModelTest.php` (add one test)

**Interfaces:**
- Consumes: `App\Models\DebtFocus\Account` (`app/Models/DebtFocus/Account.php`, unchanged), `App\Models\DebtFocus\Transaction` (`app/Models/DebtFocus/Transaction.php`), `User::debtFocusAccounts(): HasMany<Account>` (already exists on `app/Models/User.php`).
- Produces: `Transaction::scopeInvolvingAccount(Builder $query, int $accountId): Builder` — used by `LedgerController` and by Task 2's frontend indirectly via the `recent_transactions` prop shape below. `GET /ledger` Inertia props:
  ```
  accounts: Array<{
      id: number, name: string, institution: string | null,
      type: 'checking' | 'savings' | 'credit' | 'cash' | 'investment',
      is_liability: boolean, balance: number,
      recent_transactions: Array<{
          id: number, type: 'income' | 'expense' | 'transfer',
          amount: number, description: string | null, occurred_at: string,
          direction: 'in' | 'out', counterpart: string | null,
      }>,
  }>
  ```
  This exact shape is what Task 2's `Ledger.vue` types against.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/DebtFocus/LedgerControllerTest.php`:

```php
<?php

use App\Models\DebtFocus\Account;
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

test('renders an account with its balance and recent transactions', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create(['name' => 'Main Checking', 'balance' => 100]);
    (new LedgerService)->recordIncome($account, 50, 'Paycheck');
    (new LedgerService)->recordExpense($account, 20, 'Groceries');

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $props = $response->viewData('page')['props']['accounts'];

    expect($props)->toHaveCount(1);
    expect($props[0]['name'])->toBe('Main Checking');
    expect((float) $props[0]['balance'])->toBe(130.0);
    expect($props[0]['recent_transactions'])->toHaveCount(2);
});

test('a transfer shows the counterpart account and correct direction on each side', function () {
    $user = User::factory()->create();
    $checking = Account::factory()->for($user)->create(['name' => 'Checking']);
    $savings = Account::factory()->for($user)->create(['name' => 'Savings']);
    (new LedgerService)->recordTransfer($checking, $savings, 100, 'Move to savings');

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $accounts = collect($response->viewData('page')['props']['accounts'])->keyBy('name');

    $checkingTx = $accounts['Checking']['recent_transactions'][0];
    expect($checkingTx['direction'])->toBe('out');
    expect($checkingTx['counterpart'])->toBe('Savings');

    $savingsTx = $accounts['Savings']['recent_transactions'][0];
    expect($savingsTx['direction'])->toBe('in');
    expect($savingsTx['counterpart'])->toBe('Checking');
});

test('recent transactions are limited to 10 and ordered most recent first', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $service = new LedgerService;

    foreach (range(1, 12) as $i) {
        $service->recordIncome($account, 1, "Deposit {$i}", now()->subDays(12 - $i));
    }

    $response = $this->actingAs($user)->get('/ledger')->assertOk();
    $transactions = $response->viewData('page')['props']['accounts'][0]['recent_transactions'];

    expect($transactions)->toHaveCount(10);
    expect($transactions[0]['description'])->toBe('Deposit 12');
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
```

Append to `tests/Feature/DebtFocus/TransactionModelTest.php` (add the `Illuminate\Database\Eloquent\Builder`-free scope test using the existing `Account`/`Transaction`/`User` imports already at the top of that file):

```php
test('involvingAccount scope matches a transaction on any of its three account columns', function () {
    $user = User::factory()->create();
    $account = Account::factory()->for($user)->create();
    $other = Account::factory()->for($user)->create();

    $asPrimary = Transaction::factory()->for($user)->create(['type' => 'expense', 'account_id' => $account->id]);
    $asSource = Transaction::factory()->for($user)->create(['type' => 'transfer', 'account_id' => null, 'from_account_id' => $account->id, 'to_account_id' => $other->id]);
    $asDestination = Transaction::factory()->for($user)->create(['type' => 'transfer', 'account_id' => null, 'from_account_id' => $other->id, 'to_account_id' => $account->id]);
    $unrelated = Transaction::factory()->for($user)->create(['type' => 'expense', 'account_id' => $other->id]);

    $ids = Transaction::query()->involvingAccount($account->id)->pluck('id');

    expect($ids)->toContain($asPrimary->id, $asSource->id, $asDestination->id);
    expect($ids)->not->toContain($unrelated->id);
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=LedgerControllerTest`
Expected: FAIL — route `/ledger` doesn't exist (404).

Run: `php artisan test --compact --filter=TransactionModelTest`
Expected: FAIL — `involvingAccount` is not a known query scope on `Transaction`.

- [ ] **Step 3: Add the `involvingAccount` scope to `Transaction`**

In `app/Models/DebtFocus/Transaction.php`, add the import:

```php
use Illuminate\Database\Eloquent\Builder;
```

Add this method after the existing `toAccount()` relation:

```php
/**
 * @param  Builder<Transaction>  $query
 * @return Builder<Transaction>
 */
public function scopeInvolvingAccount(Builder $query, int $accountId): Builder
{
    return $query->where(function (Builder $q) use ($accountId) {
        $q->where('account_id', $accountId)
            ->orWhere('from_account_id', $accountId)
            ->orWhere('to_account_id', $accountId);
    });
}
```

- [ ] **Step 4: Run the Transaction scope test to verify it passes**

Run: `php artisan test --compact --filter=TransactionModelTest`
Expected: PASS (all `TransactionModelTest` tests, including the new one)

- [ ] **Step 5: Create `LedgerController`**

Create `app/Http/Controllers/LedgerController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\DebtFocus\Account;
use App\Models\DebtFocus\Transaction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LedgerController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()->debtFocusAccounts()->orderBy('name')->get();

        $accountProps = $accounts->map(fn (Account $account) => [
            'id' => $account->id,
            'name' => $account->name,
            'institution' => $account->institution,
            'type' => $account->type,
            'is_liability' => $account->is_liability,
            'balance' => (float) $account->balance,
            'recent_transactions' => $this->recentTransactions($account),
        ])->values();

        return Inertia::render('Ledger', [
            'accounts' => $accountProps,
        ]);
    }

    public function storeAccount(Request $request): RedirectResponse
    {
        $validated = $request->validate($this->accountRules());

        $request->user()->debtFocusAccounts()->create($validated);

        return back();
    }

    public function updateAccount(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $account->update($request->validate($this->accountRules()));

        return back();
    }

    public function destroyAccount(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $account->delete();

        return back();
    }

    /**
     * @return array<int, array{id: int, type: string, amount: float, description: string|null, occurred_at: string, direction: string, counterpart: string|null}>
     */
    private function recentTransactions(Account $account): array
    {
        $transactions = Transaction::query()
            ->involvingAccount($account->id)
            ->with(['fromAccount:id,name', 'toAccount:id,name'])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        return $transactions->map(function (Transaction $transaction) use ($account) {
            $direction = match (true) {
                $transaction->type === 'income' => 'in',
                $transaction->type === 'expense' => 'out',
                $transaction->from_account_id === $account->id => 'out',
                default => 'in',
            };

            $counterpart = match (true) {
                $transaction->type !== 'transfer' => null,
                $transaction->from_account_id === $account->id => $transaction->toAccount?->name,
                default => $transaction->fromAccount?->name,
            };

            return [
                'id' => $transaction->id,
                'type' => $transaction->type,
                'amount' => (float) $transaction->amount,
                'description' => $transaction->description,
                'occurred_at' => $transaction->occurred_at->format('M j, Y'),
                'direction' => $direction,
                'counterpart' => $counterpart,
            ];
        })->all();
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function accountRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:100'],
            'institution' => ['nullable', 'string', 'max:100'],
            'type' => ['required', 'string', 'in:checking,savings,credit,cash,investment'],
            'is_liability' => ['required', 'boolean'],
            'balance' => ['required', 'numeric', 'min:0'],
        ];
    }
}
```

- [ ] **Step 6: Add the routes**

In `routes/web.php`, add the import alongside the other controller imports (alphabetical, after `use App\Http\Controllers\EventController;`... place it correctly alphabetically among the existing `use` statements, i.e. after `ForecastController` and before `MoneyController`):

```php
use App\Http\Controllers\LedgerController;
```

Add these routes inside the existing `Route::middleware(['auth', 'verified'])->group(...)` block, near the `/payoff-plan` routes:

```php
Route::get('/ledger', [LedgerController::class, 'index'])->name('ledger.index');
Route::post('/ledger/accounts', [LedgerController::class, 'storeAccount'])->name('ledger.accounts.store');
Route::patch('/ledger/accounts/{account}', [LedgerController::class, 'updateAccount'])->name('ledger.accounts.update');
Route::delete('/ledger/accounts/{account}', [LedgerController::class, 'destroyAccount'])->name('ledger.accounts.destroy');
```

- [ ] **Step 7: Run the full new test file to verify it passes**

Run: `php artisan test --compact --filter=LedgerControllerTest`
Expected: PASS (all 11 tests)

- [ ] **Step 8: Run Pint**

Run: `vendor/bin/pint --dirty --format agent`
Expected: no remaining style violations

- [ ] **Step 9: Run the full DebtFocus suite to check for regressions**

Run: `php artisan test --compact --filter=DebtFocus`
Expected: PASS on every test this branch can affect. (A fresh worktree with no `npm run build` will still fail the pre-existing `PayoffPlanControllerTest` cases with `ViteManifestNotFoundException` — that is the same known, already-documented environment gap from the prior `linked_account_id` plan, not something this task introduces. Confirm no *other* new failures.)

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/LedgerController.php app/Models/DebtFocus/Transaction.php routes/web.php tests/Feature/DebtFocus/LedgerControllerTest.php tests/Feature/DebtFocus/TransactionModelTest.php
git commit -m "feat: add LedgerController for DebtFocus account management"
```

---

### Task 2: `Ledger.vue` page and sidebar entry

**Files:**
- Create: `resources/js/pages/Ledger.vue`
- Modify: `resources/js/components/AppSidebar.vue`

**Interfaces:**
- Consumes: the `accounts` prop shape produced by Task 1 (see Task 1's Interfaces block above), and the wayfinder-generated `storeAccount`, `updateAccount`, `destroyAccount` functions from `@/actions/App/Http/Controllers/LedgerController` (generated by `php artisan wayfinder:generate`, run in Step 1 below — mirrors how `PayoffPlan.vue` imports `storeDebt`/`updateDebt`/`destroyDebt` from `@/actions/App/Http/Controllers/PayoffPlanController`).
- Produces: nothing consumed by a later task — this is the last task in this plan.

- [ ] **Step 1: Generate the Wayfinder actions for `LedgerController`**

Run: `php artisan wayfinder:generate`
Expected: creates/updates `resources/js/actions/App/Http/Controllers/LedgerController.ts` (or equivalent per-method files) exporting `index`, `storeAccount`, `updateAccount`, `destroyAccount`.

- [ ] **Step 2: Create `resources/js/pages/Ledger.vue`**

```vue
<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import { destroyAccount, storeAccount, updateAccount } from '@/actions/App/Http/Controllers/LedgerController';

type AccountType = 'checking' | 'savings' | 'credit' | 'cash' | 'investment';

type LedgerTransaction = {
    id: number;
    type: 'income' | 'expense' | 'transfer';
    amount: number;
    description: string | null;
    occurred_at: string;
    direction: 'in' | 'out';
    counterpart: string | null;
};

type LedgerAccount = {
    id: number;
    name: string;
    institution: string | null;
    type: AccountType;
    is_liability: boolean;
    balance: number;
    recent_transactions: LedgerTransaction[];
};

const props = defineProps<{ accounts: LedgerAccount[] }>();

function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

    return n < 0 ? `-${formatted}` : formatted;
}

function typeLabel(type: AccountType): string {
    return type.charAt(0).toUpperCase() + type.slice(1);
}

function transactionLabel(tx: LedgerTransaction): string {
    if (tx.type === 'transfer' && tx.counterpart) {
        return tx.direction === 'out' ? `Transfer to ${tx.counterpart}` : `Transfer from ${tx.counterpart}`;
    }

    return tx.type === 'income' ? 'Income' : tx.type === 'expense' ? 'Expense' : 'Transfer';
}

// ── Expand / collapse transaction history ───────────────────────────────

const expandedId = ref<number | null>(null);

function toggleExpand(accountId: number): void {
    expandedId.value = expandedId.value === accountId ? null : accountId;
}

// ── Add / edit account (inline) ─────────────────────────────────────────

const editingId = ref<number | 'new' | null>(null);
const accountForm = useForm({
    name: '',
    institution: null as string | null,
    type: 'checking' as AccountType,
    is_liability: false,
    balance: 0,
});

function openAddRow(): void {
    editingId.value = 'new';
    expandedId.value = null;
    accountForm.clearErrors();
    accountForm.name = '';
    accountForm.institution = null;
    accountForm.type = 'checking';
    accountForm.is_liability = false;
    accountForm.balance = 0;
}

function openEditRow(account: LedgerAccount): void {
    editingId.value = account.id;
    expandedId.value = null;
    accountForm.clearErrors();
    accountForm.name = account.name;
    accountForm.institution = account.institution;
    accountForm.type = account.type;
    accountForm.is_liability = account.is_liability;
    accountForm.balance = account.balance;
}

function cancelRow(): void {
    editingId.value = null;
    accountForm.clearErrors();
}

function onTypeChange(): void {
    accountForm.is_liability = accountForm.type === 'credit';
}

function submitRow(): void {
    if (editingId.value === 'new') {
        accountForm.post(storeAccount().url, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
    } else if (editingId.value !== null) {
        accountForm.patch(updateAccount(editingId.value).url, { preserveScroll: true, onSuccess: () => (editingId.value = null) });
    }
}

function removeAccount(account: LedgerAccount): void {
    if (!window.confirm(`Delete ${account.name} and all its transaction history?`)) {
        return;
    }

    router.delete(destroyAccount(account.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Ledger" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pb-[46px] pt-6">

        <!-- Header -->
        <div class="mb-5">
            <h1 class="font-['Bricolage_Grotesque'] text-[28px] font-bold" style="color: var(--hab-ink); letter-spacing: -.02em">
                Ledger
            </h1>
            <p class="mt-1 text-[14px]" style="color: var(--hab-muted)">Your accounts and recent activity</p>
        </div>

        <!-- Account list -->
        <div
            class="rounded-[22px] p-6"
            style="background: var(--hab-card-bg); border: 1px solid var(--hab-card-border); box-shadow: 0 8px 24px -20px var(--hab-card-shadow)"
        >
            <h2 class="mb-4 font-['Bricolage_Grotesque'] text-[18px] font-bold" style="color: var(--hab-ink)">Accounts</h2>

            <p v-if="props.accounts.length === 0 && editingId !== 'new'" class="text-[14px]" style="color: var(--hab-muted)">
                Add an account to start tracking your balances.
            </p>

            <div v-for="(account, i) in props.accounts" :key="account.id">
                <div v-if="i > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>

                <div v-if="editingId !== account.id" class="flex items-center gap-3 py-1">
                    <button type="button" class="min-w-0 flex-1 text-left" @click="toggleExpand(account.id)">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-[14px] font-medium" style="color: var(--hab-ink)">{{ account.name }}</span>
                            <span
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
                                style="background: var(--hab-chip-fill); color: var(--hab-muted)"
                            >
                                {{ typeLabel(account.type) }}
                            </span>
                            <span
                                v-if="account.is_liability"
                                class="shrink-0 rounded-full px-2 py-0.5 text-[10.5px] font-semibold"
                                style="background: var(--hab-chip-fill); color: #c0512c"
                            >
                                Liability
                            </span>
                        </div>
                        <div class="text-[12px]" style="color: var(--hab-muted)">
                            <span v-if="account.institution">{{ account.institution }} · </span>
                            {{ formatMoney(account.balance) }} {{ account.is_liability ? 'owed' : 'balance' }}
                        </div>
                    </button>
                    <div class="shrink-0 flex items-center gap-3">
                        <button type="button" class="material-symbols-rounded" style="font-size: 18px; color: var(--hab-faint)" @click="openEditRow(account)">
                            edit
                        </button>
                        <button type="button" class="material-symbols-rounded" style="font-size: 18px; color: var(--hab-faint)" @click="removeAccount(account)">
                            delete
                        </button>
                    </div>
                </div>

                <!-- Expanded transaction history -->
                <div v-if="editingId !== account.id && expandedId === account.id" class="mt-2 rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <p v-if="account.recent_transactions.length === 0" class="text-[12px]" style="color: var(--hab-muted)">
                        No transactions yet.
                    </p>
                    <div v-for="tx in account.recent_transactions" :key="tx.id" class="flex items-center justify-between py-1 text-[12.5px]">
                        <span style="color: var(--hab-ink)">
                            {{ tx.occurred_at }} · {{ tx.description ?? transactionLabel(tx) }}
                        </span>
                        <span :style="{ color: tx.direction === 'in' ? 'var(--hab-accent)' : 'var(--hab-ink)' }">
                            {{ tx.direction === 'in' ? '+' : '−' }}{{ formatMoney(tx.amount) }}
                        </span>
                    </div>
                </div>

                <!-- Inline edit form -->
                <div v-else-if="editingId === account.id" class="rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                    <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Name</label>
                            <input v-model="accountForm.name" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Institution (optional)</label>
                            <input v-model="accountForm.institution" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Type</label>
                            <select v-model="accountForm.type" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" @change="onTypeChange">
                                <option value="checking">Checking</option>
                                <option value="savings">Savings</option>
                                <option value="credit">Credit</option>
                                <option value="cash">Cash</option>
                                <option value="investment">Investment</option>
                            </select>
                        </div>
                        <div>
                            <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Starting balance</label>
                            <input v-model.number="accountForm.balance" type="number" min="0" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                        </div>
                        <div class="flex items-end gap-2 pb-1.5">
                            <input id="is_liability" v-model="accountForm.is_liability" type="checkbox" class="h-4 w-4" />
                            <label for="is_liability" class="text-[11.5px]" style="color: var(--hab-muted)">This is a liability (I owe this balance)</label>
                        </div>
                    </div>
                    <div class="mt-2 flex items-center gap-2">
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50" style="background: var(--hab-accent)" :disabled="accountForm.processing" @click="submitRow">
                            Confirm
                        </button>
                        <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)" :disabled="accountForm.processing" @click="cancelRow">
                            Cancel
                        </button>
                    </div>
                    <div v-if="accountForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.name }}</div>
                    <div v-if="accountForm.errors.institution" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.institution }}</div>
                    <div v-if="accountForm.errors.type" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.type }}</div>
                    <div v-if="accountForm.errors.balance" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.balance }}</div>
                </div>
            </div>

            <div v-if="props.accounts.length > 0" class="my-3 h-px" style="background: var(--hab-hairline)"></div>

            <!-- Add row -->
            <button v-if="editingId !== 'new'" type="button" class="flex w-full items-center gap-2 py-2 text-[13.5px] font-medium" style="color: var(--hab-accent)" @click="openAddRow">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span>
                Add an account
            </button>
            <div v-else class="rounded-[12px] p-3" style="background: var(--hab-chip-fill)">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Name</label>
                        <input v-model="accountForm.name" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Institution (optional)</label>
                        <input v-model="accountForm.institution" type="text" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Type</label>
                        <select v-model="accountForm.type" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" @change="onTypeChange">
                            <option value="checking">Checking</option>
                            <option value="savings">Savings</option>
                            <option value="credit">Credit</option>
                            <option value="cash">Cash</option>
                            <option value="investment">Investment</option>
                        </select>
                    </div>
                    <div>
                        <label class="mb-1 block text-[11px] font-medium" style="color: var(--hab-muted)">Starting balance</label>
                        <input v-model.number="accountForm.balance" type="number" min="0" step="0.01" class="w-full rounded-[8px] border px-2 py-1.5 text-[12.5px]" style="border-color: var(--hab-card-border); background: var(--hab-card-bg); color: var(--hab-ink)" />
                    </div>
                    <div class="flex items-end gap-2 pb-1.5">
                        <input id="is_liability_new" v-model="accountForm.is_liability" type="checkbox" class="h-4 w-4" />
                        <label for="is_liability_new" class="text-[11.5px]" style="color: var(--hab-muted)">This is a liability (I owe this balance)</label>
                    </div>
                </div>
                <div class="mt-2 flex items-center gap-2">
                    <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-semibold text-white disabled:opacity-50" style="background: var(--hab-accent)" :disabled="accountForm.processing" @click="submitRow">
                        Confirm
                    </button>
                    <button type="button" class="rounded-full px-3 py-1.5 text-[12px] font-medium" style="color: var(--hab-muted)" :disabled="accountForm.processing" @click="cancelRow">
                        Cancel
                    </button>
                </div>
                <div v-if="accountForm.errors.name" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.name }}</div>
                <div v-if="accountForm.errors.institution" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.institution }}</div>
                <div v-if="accountForm.errors.type" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.type }}</div>
                <div v-if="accountForm.errors.balance" class="mt-2 text-[12px]" style="color: #c0512c">{{ accountForm.errors.balance }}</div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 3: Add the sidebar nav entry**

In `resources/js/components/AppSidebar.vue`, in the `'Money'` section's `items` array, add a new entry right after the `'Payoff Plan'` entry:

```ts
{ title: 'Ledger', href: '/ledger', icon: 'receipt_long' },
```

- [ ] **Step 4: Run the Task 1 backend tests once more to confirm nothing broke**

Run: `php artisan test --compact --filter=LedgerControllerTest`
Expected: PASS (all 11 tests) — this task doesn't change backend behavior, but confirms the Wayfinder generation step didn't touch anything under `app/`.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Ledger.vue resources/js/components/AppSidebar.vue resources/js/actions/App/Http/Controllers/LedgerController.ts
git commit -m "feat: add the Ledger page for DebtFocus account management"
```

**Note for whoever merges this branch:** this worktree has no built frontend assets (`npm run build` was never run in it — see the prior `linked_account_id` plan's ledger for why), so `/ledger` cannot be visually verified from inside the worktree via Herd (which serves the main checkout, not this worktree). Do a manual browser check of `/ledger` against `main` after merging — add an account, edit it, expand its transaction history (create one via Tinker per the "how to test" pattern already used for `linked_account_id`), delete it — before considering this plan fully verified.

---

## Self-Review Notes

- **Spec coverage:** implements `Account` CRUD per the spec's `Account` entity fields (`name, institution, type, is_liability, balance`), and read-only transaction history per the `Transaction` entity's three-FK model — no schema changes needed, both entities already exist. Transaction *entry* (the spec's manual-entry UX open question) is explicitly out of scope, matching the handoff doc's ordering (this is step 1 of the UI track, entry is step 2).
- **Out of scope, deliberately:** transaction entry/editing, the `/payoff-plan` account-picker (step 3 of the UI track — needs this page's accounts to exist first), statement-balance handling, net worth/reporting, and any reconciliation with the old `/money`+`/debt`+`/accounts` system (explicitly deferred per the user's "stays completely separate" direction).
- **Naming/placement decisions locked in with the user before writing this plan:** standalone `/ledger` page with its own sidebar entry (not nested under `/payoff-plan`), and accounts + read-only transaction history in one page (not accounts-only, not a separate transactions page).
- **Type consistency:** the `accounts` prop shape defined in Task 1's Interfaces block is reproduced exactly in Task 2's `LedgerAccount`/`LedgerTransaction` TypeScript types — field names and nullability match on both sides of the Inertia boundary.
