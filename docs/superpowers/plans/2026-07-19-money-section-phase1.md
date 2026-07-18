# Money Section Phase 1 — Accounts + Cycle Scaffolding Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the Accounts page (full CRUD) and the budget cycle setting scaffolding that phase 2 (Budgets) depends on.

**Architecture:** Three tasks in order — (1) cycle migrations + Setting model helper + tests, (2) Account CRUD backend + tests, (3) Accounts Vue page. Tasks 1 and 2 are backend-only with full test coverage before any frontend work. Each task ends with a passing test run and a commit.

**Tech Stack:** PHP 8.4, Laravel 13, Inertia v3, Vue 3 (`<script setup lang="ts">`), Tailwind v4, shadcn-vue (Dialog already installed), Material Symbols Rounded icons, Pest v4, Wayfinder v0

## Global Constraints

- All monetary values: Philippine peso sign ₱ — `'₱' + Math.abs(n).toLocaleString('en-US')` client-side
- `balance` is a signed decimal: positive = asset, negative = debt owed. Net worth = `SUM(balance)` — no special-case logic needed.
- Hero gradient: `radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%)`
- Accent CSS vars: `var(--hab-accent)`, `var(--hab-accent-deep)` — already defined per theme in `resources/css/app.css`
- Material Symbols: outlined → `<span class="material-symbols-rounded">icon_name</span>`, filled → `<span class="msf">icon_name</span>`
- Every PATCH/DELETE route must return `back()` — Inertia does not accept plain JSON
- Ownership check pattern: `abort_if($model->user_id !== $request->user()->id, 403)`
- After modifying any PHP file: `vendor/bin/pint --dirty --format agent`
- Run tests: `php artisan test --compact --filter=ClassName`
- No AppLayout import needed in page components — it is the default layout in `resources/js/app.ts`

---

## File Map

| Action | File |
|---|---|
| Create | `database/migrations/*_add_budget_cycle_to_settings_table.php` |
| Create | `database/migrations/*_create_budget_cycles_table.php` |
| Modify | `app/Models/Setting.php` |
| Modify | `app/Actions/Fortify/CreateNewUser.php` |
| Create | `database/factories/SettingFactory.php` |
| Create | `tests/Feature/BudgetCycleTest.php` |
| Create | `database/factories/AccountFactory.php` |
| Create | `app/Http/Controllers/AccountController.php` |
| Modify | `routes/web.php` |
| Create | `tests/Feature/AccountTest.php` |
| Create | `resources/js/pages/Accounts.vue` |

---

## Task 1: Budget cycle scaffolding

**Files:**
- Create: `database/migrations/*_add_budget_cycle_to_settings_table.php`
- Create: `database/migrations/*_create_budget_cycles_table.php`
- Modify: `app/Models/Setting.php`
- Modify: `app/Actions/Fortify/CreateNewUser.php`
- Create: `database/factories/SettingFactory.php`
- Create: `tests/Feature/BudgetCycleTest.php`

**Interfaces:**
- Produces: `$setting->cycleForDate(\Carbon\Carbon $date): array{start: \Carbon\Carbon, end: \Carbon\Carbon, index: int}`
- Produces: `Setting->budget_cycle_days: int` (default 30)
- Produces: `Setting->budget_cycle_anchor: \Carbon\Carbon|null`
- Produces: `budget_cycles` table (empty in phase 1, fully used in phase 2)

- [ ] **Step 1: Write the failing tests**

Run: `php artisan make:test --pest BudgetCycleTest`

Fill `tests/Feature/BudgetCycleTest.php` with:

```php
<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\Setting;
use App\Models\User;
use Carbon\Carbon;

test('new user registration creates setting with budget_cycle_days = 30', function () {
    (new CreateNewUser)->create([
        'name' => 'Cycle Test',
        'email' => 'cycle@test.com',
        'password' => 'password',
        'password_confirmation' => 'password',
    ]);

    $user = User::where('email', 'cycle@test.com')->first();

    expect($user->setting)->not->toBeNull();
    expect($user->setting->budget_cycle_days)->toBe(30);
    expect($user->setting->budget_cycle_anchor)->not->toBeNull();
});

test('cycleForDate returns correct cycle for a date in the first cycle', function () {
    $setting = new Setting([
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => Carbon::parse('2026-07-01'),
    ]);

    $result = $setting->cycleForDate(Carbon::parse('2026-07-15'));

    expect($result['start']->toDateString())->toBe('2026-07-01');
    expect($result['end']->toDateString())->toBe('2026-07-30');
    expect($result['index'])->toBe(0);
});

test('cycleForDate returns correct cycle for a date in a later cycle', function () {
    $setting = new Setting([
        'budget_cycle_days' => 30,
        'budget_cycle_anchor' => Carbon::parse('2026-07-01'),
    ]);

    $result = $setting->cycleForDate(Carbon::parse('2026-08-05'));

    expect($result['start']->toDateString())->toBe('2026-07-31');
    expect($result['end']->toDateString())->toBe('2026-08-29');
    expect($result['index'])->toBe(1);
});

test('cycleForDate works for non-30-day intervals', function () {
    $setting = new Setting([
        'budget_cycle_days' => 14,
        'budget_cycle_anchor' => Carbon::parse('2026-07-01'),
    ]);

    $result = $setting->cycleForDate(Carbon::parse('2026-07-20'));

    // Jul 20 is 19 days after Jul 1; index = floor(19/14) = 1
    // start = Jul 1 + 14 = Jul 15; end = Jul 15 + 13 = Jul 28
    expect($result['start']->toDateString())->toBe('2026-07-15');
    expect($result['end']->toDateString())->toBe('2026-07-28');
    expect($result['index'])->toBe(1);
});

test('cycle end date is start + cycle_days - 1', function () {
    $setting = new Setting([
        'budget_cycle_days' => 7,
        'budget_cycle_anchor' => Carbon::parse('2026-07-01'),
    ]);

    $result = $setting->cycleForDate(Carbon::parse('2026-07-03'));

    expect($result['end']->diffInDays($result['start']))->toBe(6);
});
```

- [ ] **Step 2: Run tests — expect them to fail**

```bash
php artisan test --compact --filter=BudgetCycleTest
```

Expected: all 5 fail (Setting has no `cycleForDate`, no `budget_cycle_days` cast).

- [ ] **Step 3: Create the migrations**

```bash
php artisan make:migration add_budget_cycle_to_settings_table --table=settings
php artisan make:migration create_budget_cycles_table
```

Fill the `add_budget_cycle_to_settings_table` migration:

```php
public function up(): void
{
    Schema::table('settings', function (Blueprint $table) {
        $table->integer('budget_cycle_days')->default(30)->after('theme');
        $table->date('budget_cycle_anchor')->nullable()->after('budget_cycle_days');
    });
}

public function down(): void
{
    Schema::table('settings', function (Blueprint $table) {
        $table->dropColumn(['budget_cycle_days', 'budget_cycle_anchor']);
    });
}
```

Fill the `create_budget_cycles_table` migration:

```php
public function up(): void
{
    Schema::create('budget_cycles', function (Blueprint $table) {
        $table->id();
        $table->foreignId('user_id')->constrained()->cascadeOnDelete();
        $table->date('start_date');
        $table->date('end_date');
        $table->decimal('budget', 10, 2)->default(0);
        $table->timestamps();

        $table->unique(['user_id', 'start_date']);
    });
}

public function down(): void
{
    Schema::dropIfExists('budget_cycles');
}
```

Run: `php artisan migrate`

- [ ] **Step 4: Update `app/Models/Setting.php`**

Replace the entire file content with:

```php
<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Setting extends Model
{
    protected $fillable = [
        'user_id',
        'theme',
        'budget_cycle_days',
        'budget_cycle_anchor',
    ];

    protected $casts = [
        'budget_cycle_days' => 'integer',
        'budget_cycle_anchor' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cycleForDate(Carbon $date): array
    {
        $anchor = ($this->budget_cycle_anchor ?? Carbon::parse($this->created_at))->copy()->startOfDay();
        $target = $date->copy()->startOfDay();
        $days = $this->budget_cycle_days;

        $diffDays = (int) round(($target->timestamp - $anchor->timestamp) / 86400);
        $index = (int) floor($diffDays / $days);

        $start = $anchor->copy()->addDays($index * $days);
        $end = $start->copy()->addDays($days - 1);

        return ['start' => $start, 'end' => $end, 'index' => $index];
    }
}
```

- [ ] **Step 5: Update `app/Actions/Fortify/CreateNewUser.php`** to create a Setting on registration

Add `use App\Models\Setting;` to imports, then add the `Setting::create` call after the user is created:

```php
<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Concerns\ProfileValidationRules;
use App\Models\Setting;
use App\Models\TaskSection;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\CreatesNewUsers;

class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules, ProfileValidationRules;

    public function create(array $input): User
    {
        Validator::make($input, [
            ...$this->profileRules(),
            'password' => $this->passwordRules(),
        ])->validate();

        $user = User::create([
            'name' => $input['name'],
            'email' => $input['email'],
            'password' => $input['password'],
        ]);

        Setting::create([
            'user_id' => $user->id,
            'theme' => 'ocean',
            'budget_cycle_days' => 30,
            'budget_cycle_anchor' => now()->toDateString(),
        ]);

        foreach ([['label' => 'Today', 'position' => 1], ['label' => 'This Week', 'position' => 2], ['label' => 'Someday', 'position' => 3]] as $section) {
            TaskSection::create(['user_id' => $user->id, ...$section]);
        }

        return $user;
    }
}
```

- [ ] **Step 6: Create `database/factories/SettingFactory.php`**

```bash
php artisan make:factory SettingFactory --model=Setting
```

Fill the factory:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class SettingFactory extends Factory
{
    public function definition(): array
    {
        return [
            'theme' => 'ocean',
            'budget_cycle_days' => 30,
            'budget_cycle_anchor' => now()->toDateString(),
        ];
    }
}
```

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Run the tests — expect them to pass**

```bash
php artisan test --compact --filter=BudgetCycleTest
```

Expected: 5 passed.

- [ ] **Step 9: Verify existing tests still pass**

```bash
php artisan test --compact --filter=TaskSection
```

Expected: all pass (CreateNewUser change does not break section creation).

- [ ] **Step 10: Commit**

```bash
git add database/migrations app/Models/Setting.php app/Actions/Fortify/CreateNewUser.php database/factories/SettingFactory.php tests/Feature/BudgetCycleTest.php
git commit -m "$(cat <<'EOF'
feat: add budget cycle scaffolding (settings columns + budget_cycles table)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 2: Account CRUD backend

**Files:**
- Create: `database/factories/AccountFactory.php`
- Create: `app/Http/Controllers/AccountController.php`
- Modify: `routes/web.php`
- Create: `tests/Feature/AccountTest.php`

**Interfaces:**
- Consumes: `User->accounts()` hasMany (already exists in User model)
- Consumes: `Setting->cycleForDate()` from Task 1
- Produces: `GET /accounts` → Inertia page with `{ netWorth: number, groups: AccountGroup[], cycleLabel: string }`
- Produces: `POST /accounts` → creates account, redirects to `accounts.index`
- Produces: `PATCH /accounts/{account}` → updates account, returns `back()`
- Produces: `DELETE /accounts/{account}` → deletes account, returns `back()`

- [ ] **Step 1: Create the AccountFactory**

```bash
php artisan make:factory AccountFactory --model=Account
```

Fill `database/factories/AccountFactory.php`:

```php
<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AccountFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement(['Checking', 'Savings', 'Credit Card']) . ' Account',
            'institution' => fake()->company(),
            'last4' => fake()->numerify('####'),
            'balance' => fake()->randomFloat(2, 100, 20000),
            'type' => fake()->randomElement(['cash', 'credit', 'investment']),
        ];
    }
}
```

- [ ] **Step 2: Write the failing tests**

```bash
php artisan make:test --pest AccountTest
```

Fill `tests/Feature/AccountTest.php`:

```php
<?php

use App\Models\Account;
use App\Models\Setting;
use App\Models\User;

test('guest is redirected from accounts index', function () {
    $this->get(route('accounts.index'))->assertRedirect(route('login'));
});

test('accounts index returns grouped accounts and net worth', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'cash', 'balance' => 2000]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => -500]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'investment', 'balance' => 5000]);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page
            ->component('Accounts')
            ->where('netWorth', 6500.0)
            ->has('groups', 3)
        );
});

test('net worth is correct sum including negative credit balances', function () {
    $user = User::factory()->create();
    Setting::factory()->create(['user_id' => $user->id]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'cash', 'balance' => 10000]);
    Account::factory()->create(['user_id' => $user->id, 'type' => 'credit', 'balance' => -3000]);

    $this->actingAs($user)
        ->get(route('accounts.index'))
        ->assertInertia(fn ($page) => $page->where('netWorth', 7000.0));
});

test('store creates account for authenticated user', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), [
            'name' => 'BDO Checking',
            'institution' => 'BDO',
            'last4' => '1234',
            'balance' => 15000,
            'type' => 'cash',
        ])
        ->assertRedirect(route('accounts.index'));

    expect(Account::where('user_id', $user->id)->where('name', 'BDO Checking')->exists())->toBeTrue();
});

test('store validates required fields', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), [])
        ->assertSessionHasErrors(['name', 'balance', 'type']);
});

test('store rejects invalid account type', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('accounts.store'), ['name' => 'Test', 'balance' => 100, 'type' => 'crypto'])
        ->assertSessionHasErrors(['type']);
});

test('update modifies account fields', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id, 'balance' => 1000]);

    $this->actingAs($user)
        ->patch(route('accounts.update', $account), [
            'name' => $account->name,
            'institution' => $account->institution,
            'last4' => $account->last4,
            'balance' => 2500,
            'type' => $account->type,
        ])
        ->assertRedirect();

    expect((float) $account->fresh()->balance)->toEqual(2500.0);
});

test('update returns 403 for another users account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->patch(route('accounts.update', $account), [
            'name' => 'Hacked',
            'balance' => 0,
            'type' => 'cash',
        ])
        ->assertForbidden();
});

test('destroy deletes account', function () {
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    $this->actingAs($user)
        ->delete(route('accounts.destroy', $account))
        ->assertRedirect();

    expect(Account::find($account->id))->toBeNull();
});

test('destroy returns 403 for another users account', function () {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $other->id]);

    $this->actingAs($user)
        ->delete(route('accounts.destroy', $account))
        ->assertForbidden();
});
```

- [ ] **Step 3: Run tests — expect them to fail**

```bash
php artisan test --compact --filter=AccountTest
```

Expected: fails on "route [accounts.index] not defined".

- [ ] **Step 4: Create the AccountController**

```bash
php artisan make:controller AccountController
```

Fill `app/Http/Controllers/AccountController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Account;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    public function index(Request $request): Response
    {
        $accounts = $request->user()
            ->accounts()
            ->orderBy('name')
            ->get();

        $netWorth = (float) $accounts->sum('balance');

        $typeOrder = ['cash' => 0, 'credit' => 1, 'investment' => 2];

        $groups = $accounts
            ->groupBy('type')
            ->map(fn ($items, $type) => [
                'type' => $type,
                'label' => match ($type) {
                    'cash' => 'Cash',
                    'credit' => 'Credit',
                    'investment' => 'Investments',
                    default => ucfirst($type),
                },
                'subtotal' => (float) $items->sum('balance'),
                'accounts' => $items->values(),
            ])
            ->sortBy(fn ($g) => $typeOrder[$g['type']] ?? 99)
            ->values();

        $setting = $request->user()->setting;
        $cycleLabel = now()->format('F Y');
        if ($setting?->budget_cycle_anchor) {
            $cycle = $setting->cycleForDate(now());
            $cycleLabel = $cycle['start']->format('M j').' – '.$cycle['end']->format('M j');
        }

        return Inertia::render('Accounts', [
            'netWorth' => $netWorth,
            'groups' => $groups,
            'cycleLabel' => $cycleLabel,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'institution' => ['nullable', 'string', 'max:100'],
            'last4' => ['nullable', 'string', 'max:4', 'regex:/^\d{0,4}$/'],
            'balance' => ['required', 'numeric'],
            'type' => ['required', 'in:cash,credit,investment'],
        ]);

        $request->user()->accounts()->create($validated);

        return redirect()->route('accounts.index');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'institution' => ['nullable', 'string', 'max:100'],
            'last4' => ['nullable', 'string', 'max:4', 'regex:/^\d{0,4}$/'],
            'balance' => ['required', 'numeric'],
            'type' => ['required', 'in:cash,credit,investment'],
        ]);

        $account->update($validated);

        return back();
    }

    public function destroy(Request $request, Account $account): RedirectResponse
    {
        abort_if($account->user_id !== $request->user()->id, 403);

        $account->delete();

        return back();
    }
}
```

- [ ] **Step 5: Add routes to `routes/web.php`**

Add these four lines inside the authenticated middleware group (after the existing task routes):

```php
Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
Route::patch('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update');
Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy');
```

Add the import at the top of the file:

```php
use App\Http\Controllers\AccountController;
```

- [ ] **Step 6: Generate Wayfinder TypeScript route helpers**

```bash
php artisan wayfinder:generate
```

Verify that `resources/js/actions/AccountController.ts` was created. It will contain `index()`, `store()`, `update({ account })`, and `destroy({ account })` functions.

- [ ] **Step 7: Add Account model `HasFactory` trait**

Open `app/Models/Account.php`. Add `use Illuminate\Database\Eloquent\Factories\HasFactory;` to the imports and `use HasFactory;` in the class body:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Account extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'name', 'institution', 'last4', 'balance', 'type'];

    protected $casts = ['balance' => 'decimal:2'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
```

- [ ] **Step 8: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Run the tests — expect them to pass**

```bash
php artisan test --compact --filter=AccountTest
```

Expected: 9 passed.

- [ ] **Step 10: Run the full test suite to check for regressions**

```bash
php artisan test --compact
```

Expected: all pass.

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/AccountController.php app/Models/Account.php database/factories/AccountFactory.php routes/web.php tests/Feature/AccountTest.php resources/js/actions/
git commit -m "$(cat <<'EOF'
feat: add Account CRUD backend (index, store, update, destroy)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```

---

## Task 3: Accounts Vue page

**Files:**
- Create: `resources/js/pages/Accounts.vue`

**Interfaces:**
- Consumes props: `netWorth: number`, `groups: AccountGroup[]`, `cycleLabel: string` (from AccountController@index)
- Consumes Wayfinder: `store()`, `update({ account })`, `destroy({ account })` from `@/actions/AccountController`

- [ ] **Step 1: Create `resources/js/pages/Accounts.vue`**

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { ref, reactive, computed } from 'vue';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { store, update, destroy } from '@/actions/AccountController';

type AccountType = 'cash' | 'credit' | 'investment';

type Account = {
    id: number;
    name: string;
    institution: string | null;
    last4: string | null;
    balance: string;
    type: AccountType;
};

type AccountGroup = {
    type: AccountType;
    label: string;
    subtotal: number;
    accounts: Account[];
};

const props = defineProps<{
    netWorth: number;
    groups: AccountGroup[];
    cycleLabel: string;
}>();

const typeIcons: Record<AccountType, string> = {
    cash: 'savings',
    credit: 'credit_card',
    investment: 'candlestick_chart',
};

const typeColors: Record<AccountType, { color: string; bg: string }> = {
    cash: { color: '#2f7d5b', bg: '#dff3e6' },
    credit: { color: '#c0512c', bg: '#fbe2d6' },
    investment: { color: '#3f7fb0', bg: '#dcebf5' },
};

const typeOptions: { value: AccountType; label: string }[] = [
    { value: 'cash', label: 'Cash' },
    { value: 'credit', label: 'Credit' },
    { value: 'investment', label: 'Investment' },
];

// Modal state
const modalOpen = ref(false);
const editingAccount = ref<Account | null>(null);
const form = reactive({ name: '', institution: '', last4: '', balance: '', type: 'cash' as AccountType });

// Two-step delete
const deleteConfirmId = ref<number | null>(null);
let deleteTimer: ReturnType<typeof setTimeout> | null = null;

const netWorthFormatted = computed(() => formatMoney(props.netWorth));

function formatMoney(amount: string | number): string {
    const n = typeof amount === 'string' ? parseFloat(amount) : amount;
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 0, maximumFractionDigits: 0 });
    return n < 0 ? '-' + formatted : formatted;
}

function openAdd() {
    editingAccount.value = null;
    Object.assign(form, { name: '', institution: '', last4: '', balance: '', type: 'cash' });
    modalOpen.value = true;
}

function openEdit(account: Account) {
    editingAccount.value = account;
    Object.assign(form, {
        name: account.name,
        institution: account.institution ?? '',
        last4: account.last4 ?? '',
        balance: account.balance,
        type: account.type,
    });
    modalOpen.value = true;
}

function closeModal() {
    modalOpen.value = false;
}

function submit() {
    const data = {
        name: form.name,
        institution: form.institution || null,
        last4: form.last4 || null,
        balance: parseFloat(form.balance),
        type: form.type,
    };
    if (editingAccount.value) {
        router.patch(update({ account: editingAccount.value.id }), data, {
            onSuccess: closeModal,
            preserveScroll: true,
        });
    } else {
        router.post(store(), data, {
            onSuccess: closeModal,
            preserveScroll: true,
        });
    }
}

function startDelete(id: number) {
    if (deleteTimer) clearTimeout(deleteTimer);
    deleteConfirmId.value = id;
    deleteTimer = setTimeout(() => { deleteConfirmId.value = null; }, 3000);
}

function confirmDelete(id: number) {
    if (deleteTimer) clearTimeout(deleteTimer);
    deleteConfirmId.value = null;
    router.delete(destroy({ account: id }), { preserveScroll: true });
}

function cancelDelete() {
    if (deleteTimer) clearTimeout(deleteTimer);
    deleteConfirmId.value = null;
}
</script>

<template>
    <Head title="Accounts" />

    <div style="padding: 24px 30px 46px; display: flex; flex-direction: column; gap: 20px; max-width: 1180px; width: 100%">

        <!-- Page header -->
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Accounts</h1>
                <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Everything you own and owe, in one place</p>
            </div>
            <button @click="openAdd" style="margin-left: auto; display: flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 42px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> Add account
            </button>
        </div>

        <!-- Net worth hero + cash flow row -->
        <div style="display: grid; grid-template-columns: 1fr 340px; gap: 20px; align-items: start">

            <!-- Net worth hero -->
            <div style="background: radial-gradient(120% 140% at 100% 0%, var(--hab-grad-a) 0%, var(--hab-grad-b) 55%, var(--hab-grad-c) 100%); border-radius: 24px; padding: 28px 30px; color: #fff; position: relative; overflow: hidden; box-shadow: 0 24px 40px -26px rgba(20,20,20,.7)">
                <div style="position: absolute; right: -40px; top: -40px; width: 220px; height: 220px; border-radius: 50%; background: rgba(255,255,255,.06)"></div>
                <div style="position: relative; display: flex; justify-content: space-between; align-items: flex-start; gap: 20px; flex-wrap: wrap">
                    <div>
                        <div style="font-size: 14px; font-weight: 500; color: rgba(255,255,255,.72)">Total net worth</div>
                        <div style="font-family: 'Bricolage Grotesque'; font-weight: 800; font-size: 56px; line-height: .9; letter-spacing: -.03em; margin-top: 6px">
                            {{ netWorthFormatted }}
                        </div>
                        <div style="margin-top: 14px; display: inline-flex; align-items: center; gap: 7px; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.18); padding: 6px 12px; border-radius: 20px; font-size: 13px">
                            <span class="msf" style="font-size: 16px; color: #fff">trending_flat</span>
                            <span>₱0 · 0% this month</span>
                        </div>
                    </div>
                    <!-- Flat sparkline (phase 1 — no transaction history) -->
                    <div style="display: flex; align-items: flex-end; gap: 6px; height: 74px; padding-top: 6px">
                        <div v-for="h in [30, 35, 28, 32, 30, 30]" :key="h"
                            style="width: 16px; border-radius: 5px; background: rgba(255,255,255,.25)"
                            :style="{ height: h + '%' }">
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cash flow card (zero state) -->
            <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 20px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
                <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px; margin-bottom: 16px">
                    Cash flow · {{ cycleLabel }}
                </div>
                <div style="display: flex; flex-direction: column; gap: 13px">
                    <div style="display: flex; align-items: center; gap: 10px">
                        <span style="width: 30px; height: 30px; border-radius: 9px; background: #dff3e6; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" style="font-size: 17px; color: #2f7d5b">south_west</span>
                        </span>
                        <span style="font-size: 13.5px; color: #6b6355">Money in</span>
                        <span style="margin-left: auto; font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; color: #2f7d5b">₱0</span>
                    </div>
                    <div style="display: flex; align-items: center; gap: 10px">
                        <span style="width: 30px; height: 30px; border-radius: 9px; background: #f8ecd6; display: flex; align-items: center; justify-content: center; flex: none">
                            <span class="msf" style="font-size: 17px; color: #a06a2b">north_east</span>
                        </span>
                        <span style="font-size: 13.5px; color: #6b6355">Money out</span>
                        <span style="margin-left: auto; font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; color: #211d17">₱0</span>
                    </div>
                    <div style="height: 9px; border-radius: 20px; background: #efe9dc; overflow: hidden; margin-top: 2px">
                        <div style="height: 100%; width: 0; background: #d98a2b; border-radius: 20px"></div>
                    </div>
                    <div style="display: flex; align-items: center; justify-content: space-between; padding-top: 11px; border-top: 1px solid #efe9dc">
                        <span style="font-size: 13.5px; font-weight: 600">Net saved</span>
                        <span style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px; color: var(--hab-accent)">₱0</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Empty state -->
        <div v-if="!groups.length" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 24px; padding: 56px 32px; text-align: center; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <span class="material-symbols-rounded" style="font-size: 40px; color: #c3bbab">account_balance</span>
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 18px; margin-top: 12px; color: #211d17">No accounts yet</div>
            <div style="color: #8a8172; font-size: 14px; margin-top: 4px">Add your first account to start tracking your net worth</div>
            <button @click="openAdd" style="margin-top: 20px; display: inline-flex; align-items: center; gap: 7px; background: #211d17; color: #fdfbf5; border: none; border-radius: 12px; padding: 0 16px; height: 40px; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; cursor: pointer">
                <span class="material-symbols-rounded" style="font-size: 18px">add</span> Add account
            </button>
        </div>

        <!-- Grouped accounts card -->
        <div v-if="groups.length" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 24px; padding: 10px 26px 16px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">
            <div v-for="group in groups" :key="group.type" style="padding: 16px 0 4px">
                <div style="display: flex; align-items: baseline; justify-content: space-between; margin-bottom: 4px">
                    <div style="font-size: 11.5px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase; color: #a49b8a">
                        {{ group.label }}
                    </div>
                    <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 14px;" :style="{ color: group.subtotal < 0 ? '#c0512c' : '#4a4436' }">
                        {{ formatMoney(group.subtotal) }}
                    </div>
                </div>

                <div v-for="account in group.accounts" :key="account.id" class="group/account"
                    style="display: flex; align-items: center; gap: 13px; padding: 13px 0; border-top: 1px solid #efe9dc">
                    <span :style="{ background: typeColors[account.type].bg }"
                        style="width: 40px; height: 40px; border-radius: 12px; display: flex; align-items: center; justify-content: center; flex: none">
                        <span class="msf" :style="{ color: typeColors[account.type].color }" style="font-size: 21px">
                            {{ typeIcons[account.type] }}
                        </span>
                    </span>
                    <div style="min-width: 0; flex: 1">
                        <div style="font-weight: 600; font-size: 14.5px">{{ account.name }}</div>
                        <div style="font-size: 12.5px; color: #8a8172; margin-top: 1px">
                            {{ account.institution || '—' }}{{ account.last4 ? ' •• ' + account.last4 : '' }}
                        </div>
                    </div>

                    <!-- Normal state: balance + hover actions -->
                    <template v-if="deleteConfirmId !== account.id">
                        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 16px"
                            :style="{ color: parseFloat(account.balance) < 0 ? '#c0512c' : '#211d17' }">
                            {{ formatMoney(account.balance) }}
                        </div>
                        <div class="hidden group-hover/account:flex items-center gap-1">
                            <button @click.stop="openEdit(account)"
                                style="width: 32px; height: 32px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                                <span class="material-symbols-rounded" style="font-size: 17px">edit</span>
                            </button>
                            <button @click.stop="startDelete(account.id)"
                                style="width: 32px; height: 32px; border: 1px solid #fbe2d6; background: #fbe2d6; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #c0512c">
                                <span class="material-symbols-rounded" style="font-size: 17px">delete</span>
                            </button>
                        </div>
                        <span class="material-symbols-rounded group-hover/account:hidden" style="font-size: 20px; color: #c3bbab">chevron_right</span>
                    </template>

                    <!-- Delete confirm state -->
                    <template v-else>
                        <div style="display: flex; align-items: center; gap: 6px; margin-left: auto">
                            <button @click="cancelDelete"
                                style="padding: 0 12px; height: 32px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #6b6355">
                                Cancel
                            </button>
                            <button @click="confirmDelete(account.id)"
                                style="padding: 0 12px; height: 32px; border: none; background: #c0512c; border-radius: 9px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; font-weight: 600; color: #fff">
                                Confirm?
                            </button>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>

    <!-- Add / Edit modal -->
    <Dialog v-model:open="modalOpen">
        <DialogContent style="max-width: 440px">
            <DialogHeader>
                <DialogTitle>{{ editingAccount ? 'Edit account' : 'Add account' }}</DialogTitle>
            </DialogHeader>
            <form @submit.prevent="submit" style="display: flex; flex-direction: column; gap: 16px; margin-top: 8px">
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Name *</label>
                    <input v-model="form.name" required placeholder="e.g. BDO Checking"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                </div>
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px">
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Institution</label>
                        <input v-model="form.institution" placeholder="e.g. BDO"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                    <div>
                        <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Last 4 digits</label>
                        <input v-model="form.last4" maxlength="4" placeholder="1234"
                            style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    </div>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 6px">Balance *</label>
                    <input v-model="form.balance" type="number" step="0.01" required placeholder="e.g. 15000 (or -612 for debt)"
                        style="width: 100%; height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none; color: #211d17" />
                    <div style="font-size: 12px; color: #8a8172; margin-top: 4px">Enter a negative number for debt you owe</div>
                </div>
                <div>
                    <label style="font-size: 13px; font-weight: 600; color: #4a4436; display: block; margin-bottom: 8px">Type *</label>
                    <div style="display: flex; gap: 8px">
                        <button v-for="t in typeOptions" :key="t.value" type="button" @click="form.type = t.value"
                            style="flex: 1; height: 38px; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-size: 13px; border: 1px solid"
                            :style="{
                                borderColor: form.type === t.value ? 'var(--hab-accent)' : '#e0dacd',
                                background: form.type === t.value ? 'color-mix(in srgb, var(--hab-accent) 12%, transparent)' : '#fffdf8',
                                color: form.type === t.value ? 'var(--hab-accent-deep)' : '#6b6355',
                                fontWeight: form.type === t.value ? 600 : 500,
                            }">
                            {{ t.label }}
                        </button>
                    </div>
                </div>
                <div style="display: flex; gap: 8px; justify-content: flex-end; margin-top: 4px">
                    <button type="button" @click="closeModal"
                        style="padding: 0 16px; height: 40px; border: 1px solid #e0dacd; background: #fffdf8; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #6b6355">
                        Cancel
                    </button>
                    <button type="submit"
                        style="padding: 0 16px; height: 40px; border: none; background: #211d17; border-radius: 10px; cursor: pointer; font-family: 'Instrument Sans'; font-weight: 600; font-size: 13.5px; color: #fdfbf5">
                        {{ editingAccount ? 'Save changes' : 'Add account' }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
```

- [ ] **Step 2: Wire the Accounts nav link in the sidebar**

Open `resources/js/components/AppSidebar.vue`. Find the "Accounts" nav item and update its `href` to point to `/accounts` (or the Wayfinder `index()` function). The existing sidebar likely uses a static href or a stub. Update it to:

```ts
// In the nav items array for the MONEY section, find the Accounts item and set:
href: '/accounts',
```

- [ ] **Step 3: Start the dev server and verify the page**

```bash
composer run dev
```

Navigate to `http://habitat.test/accounts`.

Verify:
- Page loads without errors
- Hero card shows gradient background and "Total net worth" with ₱0 (no accounts yet)
- Cash flow card shows ₱0 rows and cycle label (e.g., "Jul 19 – Aug 17")
- Empty state card shows "No accounts yet" with an "Add account" button
- Clicking "Add account" opens the modal
- Fill in the form and submit — account appears in the grouped list
- Hover over an account row — edit and delete buttons appear
- Click edit — modal opens pre-filled
- Click delete — "Confirm?" button appears, clicking it removes the account, clicking Cancel reverts

- [ ] **Step 4: Check browser console for errors**

```bash
# In the browser, open DevTools → Console
# Expected: no errors
```

If you see "Cannot find module '@/actions/AccountController'", re-run `php artisan wayfinder:generate` and restart the dev server.

- [ ] **Step 5: Commit**

```bash
git add resources/js/pages/Accounts.vue resources/js/components/AppSidebar.vue
git commit -m "$(cat <<'EOF'
feat: add Accounts page with CRUD UI (net worth hero, grouped accounts, add/edit/delete modal)

Co-Authored-By: Claude Sonnet 4.6 <noreply@anthropic.com>
EOF
)"
```
