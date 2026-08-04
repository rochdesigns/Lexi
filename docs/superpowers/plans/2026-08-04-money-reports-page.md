# Money Reports Page Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a Reports page to the Money section: an overview of income/expenses/net for a selected calendar period (Month/Quarter/Year), spending and budget-vs-actual breakdowns, a cash-flow chart with trend-vs-budget projections, and debt/goal payoff timelines.

**Architecture:** One new route/controller/page, following the existing `BudgetController`/`Budgets.vue` pattern exactly: a single controller action computes everything for the requested period in one pass and renders one Inertia page. Tabs switch client-side with no extra requests. Charts use `chart.js` + `vue-chartjs` (new dependency — approved during design, since no charting library exists in the app today).

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, Inertia v3, Vue 3, TypeScript, Tailwind v4, Chart.js + vue-chartjs.

## Global Constraints

- All PHP must pass `vendor/bin/pint --dirty --format agent` before committing
- Tests run with `php artisan test --compact`
- Periods are calendar-based (month/quarter/year) — not the user's custom budget cycle
- Next-period navigation is capped at the current period — no browsing into the future
- New dependency `chart.js` + `vue-chartjs` is approved for this feature only
- After adding the route, regenerate Wayfinder actions: `php artisan wayfinder:generate`
- Design spec: [docs/superpowers/specs/2026-08-04-money-reports-page-design.md](../specs/2026-08-04-money-reports-page-design.md)

---

### Task 1: Route, controller skeleton, period resolution, nav link, page skeleton

**Files:**
- Create: `app/Http/Controllers/ReportController.php`
- Modify: `routes/web.php`
- Modify: `resources/js/components/AppSidebar.vue`
- Create: `resources/js/pages/Reports.vue`
- Test: `tests/Feature/ReportTest.php`

**Interfaces:**
- Produces: `ReportController::index()` renders Inertia page `'Reports'` with a `period` prop: `{ type: 'month'|'quarter'|'year', label: string, prevDate: string, nextDate: string|null, isCurrent: bool }`. Later tasks add more top-level props to this same render call — do not rename or restructure `period`.
- Produces: a private `resolvePeriod(string $type, Carbon $anchor): array` returning `{start: Carbon, end: Carbon, label: string, prevDate: string, nextDate: ?string, isCurrent: bool, monthsInPeriod: int}` — later tasks call this same method and consume `start`/`end`/`monthsInPeriod`/`isCurrent`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/ReportTest.php`:

```php
<?php

use App\Models\User;
use Carbon\Carbon;

test('guest is redirected from reports page', function () {
    $this->get(route('reports'))->assertRedirect(route('login'));
});

test('reports page renders with Inertia component and month period by default', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->component('Reports')
            ->where('period.type', 'month')
            ->where('period.label', 'August 2026')
            ->where('period.prevDate', '2026-07-15')
            ->where('period.isCurrent', true)
            ->where('period.nextDate', null)
        );

    Carbon::setTestNow();
});

test('reports page resolves quarter and year periods', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page
            ->where('period.type', 'quarter')
            ->where('period.label', 'Q3 2026')
        );

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'year']))
        ->assertInertia(fn ($page) => $page
            ->where('period.type', 'year')
            ->where('period.label', '2026')
        );

    Carbon::setTestNow();
});

test('reports page allows navigating to a past period and re-enables next', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month', 'date' => '2026-06-01']))
        ->assertInertia(fn ($page) => $page
            ->where('period.label', 'June 2026')
            ->where('period.isCurrent', false)
            ->where('period.nextDate', '2026-07-01')
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=ReportTest
```

Expected: FAIL — route `reports` not defined (404 on `route('reports')` resolution).

- [ ] **Step 3: Add the route**

In `routes/web.php`, add the import near the other controller imports:

```php
use App\Http\Controllers\ReportController;
```

Add the route inside the `auth`/`verified` group, directly after the `debts.payment` route (before `Route::inertia('/photos', 'Stub')`):

```php
Route::get('/reports', [ReportController::class, 'index'])->name('reports');
```

- [ ] **Step 4: Create `app/Http/Controllers/ReportController.php`**

```php
<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ReportController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $request->user();

        $periodType = in_array($request->query('period'), ['month', 'quarter', 'year'], true)
            ? $request->query('period')
            : 'month';

        $anchor = $request->query('date')
            ? Carbon::parse($request->query('date'))
            : Carbon::now();

        $period = $this->resolvePeriod($periodType, $anchor);

        return Inertia::render('Reports', [
            'period' => [
                'type' => $periodType,
                'label' => $period['label'],
                'prevDate' => $period['prevDate'],
                'nextDate' => $period['nextDate'],
                'isCurrent' => $period['isCurrent'],
            ],
        ]);
    }

    /**
     * @return array{start: Carbon, end: Carbon, label: string, prevDate: string, nextDate: ?string, isCurrent: bool, monthsInPeriod: int}
     */
    private function resolvePeriod(string $type, Carbon $anchor): array
    {
        [$start, $end, $label, $prevAnchor, $nextAnchor, $monthsInPeriod] = match ($type) {
            'quarter' => [
                $anchor->copy()->startOfQuarter(),
                $anchor->copy()->endOfQuarter(),
                'Q'.$anchor->quarter.' '.$anchor->year,
                $anchor->copy()->subQuarterNoOverflow(),
                $anchor->copy()->addQuarterNoOverflow(),
                3,
            ],
            'year' => [
                $anchor->copy()->startOfYear(),
                $anchor->copy()->endOfYear(),
                (string) $anchor->year,
                $anchor->copy()->subYear(),
                $anchor->copy()->addYear(),
                12,
            ],
            default => [
                $anchor->copy()->startOfMonth(),
                $anchor->copy()->endOfMonth(),
                $anchor->format('F Y'),
                $anchor->copy()->subMonthNoOverflow(),
                $anchor->copy()->addMonthNoOverflow(),
                1,
            ],
        };

        $now = Carbon::now();
        $isCurrent = $now->between($start, $end);

        return [
            'start' => $start,
            'end' => $end,
            'label' => $label,
            'prevDate' => $prevAnchor->toDateString(),
            'nextDate' => $isCurrent ? null : $nextAnchor->toDateString(),
            'isCurrent' => $isCurrent,
            'monthsInPeriod' => $monthsInPeriod,
        ];
    }
}
```

- [ ] **Step 5: Add the "Reports" nav item**

In `resources/js/components/AppSidebar.vue`, in the `navSections` array, add to the "Money" section's `items`, after the `Debt` entry:

```ts
{ title: 'Reports', href: '/reports', icon: 'bar_chart' },
```

- [ ] **Step 6: Create `resources/js/pages/Reports.vue`**

```vue
<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { index } from '@/actions/App/Http/Controllers/ReportController';

type PeriodType = 'month' | 'quarter' | 'year';

type Period = {
    type: PeriodType;
    label: string;
    prevDate: string;
    nextDate: string | null;
    isCurrent: boolean;
};

const props = defineProps<{
    period: Period;
}>();

const periodOptions: { value: PeriodType; label: string }[] = [
    { value: 'month', label: 'Month' },
    { value: 'quarter', label: 'Quarter' },
    { value: 'year', label: 'Year' },
];

function switchPeriod(type: PeriodType) {
    router.get(index({ query: { period: type } }).url, {}, { preserveScroll: true, preserveState: true, replace: true });
}

function navigateDate(date: string) {
    router.get(index({ query: { period: props.period.type, date } }).url, {}, { preserveScroll: true, preserveState: true, replace: true });
}
</script>

<template>
    <Head title="Reports" />

    <div class="mx-auto w-full max-w-[1180px] px-4 sm:px-6 lg:px-[30px] pt-6 pb-[46px] flex flex-col gap-5">
        <div style="display: flex; align-items: flex-end; gap: 16px; flex-wrap: wrap">
            <div>
                <h1 style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 28px; letter-spacing: -.02em; line-height: 1">Reports</h1>
                <p style="color: #8a8172; font-size: 14px; margin-top: 4px">Where your money's been, and where it's headed</p>
            </div>

            <div style="margin-left: auto; display: flex; align-items: center; gap: 10px; flex-wrap: wrap">
                <div style="display: flex; align-items: center; border: 1px solid #e0dacd; border-radius: 12px; overflow: hidden; background: #fffdf8">
                    <button
                        v-for="opt in periodOptions"
                        :key="opt.value"
                        type="button"
                        @click="switchPeriod(opt.value)"
                        :style="{
                            padding: '0 14px',
                            height: '38px',
                            border: 'none',
                            fontSize: '13.5px',
                            fontWeight: 600,
                            cursor: 'pointer',
                            background: period.type === opt.value ? '#211d17' : 'transparent',
                            color: period.type === opt.value ? '#fdfbf5' : '#6b6355',
                        }"
                    >{{ opt.label }}</button>
                </div>

                <div style="display: flex; align-items: center; border: 1px solid #e0dacd; border-radius: 12px; overflow: hidden; background: #fffdf8">
                    <button type="button" @click="navigateDate(period.prevDate)"
                        style="width: 38px; height: 38px; border: none; background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: #6b6355">
                        <span class="material-symbols-rounded" style="font-size: 20px">chevron_left</span>
                    </button>
                    <span style="padding: 0 12px; font-size: 13.5px; font-weight: 600; color: #211d17; border-left: 1px solid #e0dacd; border-right: 1px solid #e0dacd; height: 38px; display: flex; align-items: center">
                        {{ period.label }}
                    </span>
                    <button type="button"
                        @click="period.nextDate && navigateDate(period.nextDate)"
                        :disabled="!period.nextDate"
                        :style="{
                            width: '38px', height: '38px', border: 'none', background: 'transparent',
                            display: 'flex', alignItems: 'center', justifyContent: 'center', color: '#6b6355',
                            opacity: period.nextDate ? 1 : 0.35, cursor: period.nextDate ? 'pointer' : 'default',
                        }">
                        <span class="material-symbols-rounded" style="font-size: 20px">chevron_right</span>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
```

- [ ] **Step 7: Regenerate Wayfinder actions**

```bash
php artisan wayfinder:generate
```

- [ ] **Step 8: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Run the tests to confirm they pass**

```bash
php artisan test --compact --filter=ReportTest
```

Expected: 4 passing.

- [ ] **Step 10: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 11: Commit**

```bash
git add app/Http/Controllers/ReportController.php routes/web.php resources/js/components/AppSidebar.vue resources/js/pages/Reports.vue resources/js/actions/App/Http/Controllers/ReportController.ts resources/js/routes/reports.ts tests/Feature/ReportTest.php
git commit -m "feat: add Reports page route with period navigation"
```

(If Wayfinder generated the route/action files under slightly different paths, `git add -A -- resources/js/actions resources/js/routes` instead, but review `git status` first to avoid staging unrelated generated files.)

---

### Task 2: Overview stats

**Files:**
- Modify: `app/Http/Controllers/ReportController.php`
- Modify: `resources/js/pages/Reports.vue`
- Modify: `tests/Feature/ReportTest.php`

**Interfaces:**
- Consumes: `resolvePeriod()` output from Task 1 (`start`, `end`, `monthsInPeriod`, `isCurrent`)
- Produces: private `computeOverview(User $user, Carbon $start, Carbon $end, int $monthsInPeriod, bool $isCurrent): array` returning `{income: float, expenses: float, net: float, budgetTotal: float, budgetUsedPct: float, projectedTotal: float|null}`, rendered as the `overview` prop. Later tasks (spending, budget-vs-actual, cash flow) read `overview.expenses`/`overview.budgetTotal` conceptually but do not call this method directly — each has its own computation.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ReportTest.php` (needs `Account`, `Transaction`, `BudgetCategory` imports added to the `use` block at the top):

```php
use App\Models\Account;
use App\Models\BudgetCategory;
use App\Models\Transaction;
```

```php
test('reports overview computes income, expenses, and net for the month', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'income', 'amount' => 5000, 'transacted_at' => '2026-08-05']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 1200, 'transacted_at' => '2026-08-10']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 999, 'transacted_at' => '2026-07-31']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('overview.income', 5000.0)
            ->where('overview.expenses', 1200.0)
            ->where('overview.net', 3800.0)
        );

    Carbon::setTestNow();
});

test('reports overview scales budget total by months in period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 1000]);
    BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 500]);

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month']))
        ->assertInertia(fn ($page) => $page->where('overview.budgetTotal', 1500.0));

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page->where('overview.budgetTotal', 4500.0));

    Carbon::setTestNow();
});

test('reports overview projects a full-period total from spending so far', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-11'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 1100, 'transacted_at' => '2026-08-10']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page->where('overview.projectedTotal', 3100.0));

    Carbon::setTestNow();
});

test('reports overview omits projected total for a past period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month', 'date' => '2026-06-01']))
        ->assertInertia(fn ($page) => $page->where('overview.projectedTotal', null));

    Carbon::setTestNow();
});

test('reports overview budget used pct is zero with no budget categories', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page->where('overview.budgetUsedPct', 0.0));

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter=ReportTest
```

Expected: the 5 new tests fail (`overview` key missing from the Inertia response).

- [ ] **Step 3: Add `computeOverview()` to `ReportController`**

Add this private method after `resolvePeriod()`:

```php
    private function computeOverview(User $user, Carbon $start, Carbon $end, int $monthsInPeriod, bool $isCurrent): array
    {
        $income = (float) $user->transactions()
            ->where('type', 'income')
            ->whereDate('transacted_at', '>=', $start)
            ->whereDate('transacted_at', '<=', $end)
            ->sum('amount');

        $expenses = (float) $user->transactions()
            ->where('type', 'expense')
            ->whereDate('transacted_at', '>=', $start)
            ->whereDate('transacted_at', '<=', $end)
            ->sum('amount');

        $budgetTotal = (float) $user->budgetCategories()->sum('limit_amount') * $monthsInPeriod;
        $budgetUsedPct = $budgetTotal > 0 ? $expenses / $budgetTotal : 0.0;

        $projectedTotal = null;
        if ($isCurrent) {
            $today = Carbon::now()->startOfDay();
            $elapsedDays = max(1, $start->copy()->startOfDay()->diffInDays($today) + 1);
            $totalDays = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
            $projectedTotal = round($expenses / $elapsedDays * $totalDays, 2);
        }

        return [
            'income' => $income,
            'expenses' => $expenses,
            'net' => $income - $expenses,
            'budgetTotal' => $budgetTotal,
            'budgetUsedPct' => $budgetUsedPct,
            'projectedTotal' => $projectedTotal,
        ];
    }
```

Add the `User` import at the top of the file:

```php
use App\Models\User;
```

Wire it into `index()` — replace the `return Inertia::render(...)` block with:

```php
        $overview = $this->computeOverview($user, $period['start'], $period['end'], $period['monthsInPeriod'], $period['isCurrent']);

        return Inertia::render('Reports', [
            'period' => [
                'type' => $periodType,
                'label' => $period['label'],
                'prevDate' => $period['prevDate'],
                'nextDate' => $period['nextDate'],
                'isCurrent' => $period['isCurrent'],
            ],
            'overview' => $overview,
        ]);
```

- [ ] **Step 4: Add overview stat cards to `Reports.vue`**

Add the `Overview` type and prop:

```ts
type Overview = {
    income: number;
    expenses: number;
    net: number;
    budgetTotal: number;
    budgetUsedPct: number;
    projectedTotal: number | null;
};
```

Add `overview: Overview;` to `defineProps<{...}>()`.

Add a `formatMoney` helper (same convention as `Budgets.vue`), after the `navigateDate` function:

```ts
function formatMoney(n: number): string {
    const abs = Math.abs(n);
    const formatted = '₱' + abs.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    return n < 0 ? `-${formatted}` : formatted;
}
```

Add the overview cards row in the template, directly after the closing `</div>` of the header row (the one containing the period pills and prev/next arrows):

```html
<!-- Overview stat cards -->
<div class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-5">
    <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
        <div style="font-size: 12px; color: #8a8172">Income</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #2f7d5b; margin-top: 4px">{{ formatMoney(overview.income) }}</div>
    </div>
    <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
        <div style="font-size: 12px; color: #8a8172">Expenses</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; color: #c0512c; margin-top: 4px">{{ formatMoney(overview.expenses) }}</div>
    </div>
    <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
        <div style="font-size: 12px; color: #8a8172">Net</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; margin-top: 4px" :style="{ color: overview.net >= 0 ? '#2f7d5b' : '#c0512c' }">{{ formatMoney(overview.net) }}</div>
    </div>
    <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
        <div style="font-size: 12px; color: #8a8172">Budget used</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; margin-top: 4px">{{ Math.round(overview.budgetUsedPct * 100) }}%</div>
    </div>
    <div v-if="overview.projectedTotal !== null" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 14px 16px">
        <div style="font-size: 12px; color: #8a8172">Projected total</div>
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 20px; margin-top: 4px">{{ formatMoney(overview.projectedTotal) }}</div>
    </div>
</div>
```

- [ ] **Step 5: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 6: Run the tests to confirm they pass**

```bash
php artisan test --compact --filter=ReportTest
```

Expected: all passing.

- [ ] **Step 7: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/js/pages/Reports.vue tests/Feature/ReportTest.php
git commit -m "feat: add overview stats to Reports page"
```

---

### Task 3: Spending tab + reusable bar chart + Chart.js install

**Files:**
- Modify: `package.json` (via `npm install`)
- Create: `resources/js/components/reports/ReportBarChart.vue`
- Modify: `app/Http/Controllers/ReportController.php`
- Modify: `resources/js/pages/Reports.vue`
- Modify: `tests/Feature/ReportTest.php`

**Interfaces:**
- Produces: `ReportBarChart.vue` — `defineProps<{ labels: string[]; datasets: { label?: string; data: number[]; backgroundColor: string | string[]; borderRadius?: number }[]; showLegend?: boolean }>()`. Reused as-is by Task 4 (Budget vs Actual) with two datasets.
- Produces: private `computeSpending(User $user, Carbon $start, Carbon $end): array` returning a list of `{ category: {id, name, icon, color}, amount: float, pct: float }` sorted by `amount` descending, rendered as the `spending` prop.
- Consumes: `resolvePeriod()` output from Task 1.

- [ ] **Step 1: Install Chart.js + vue-chartjs**

```bash
npm install chart.js vue-chartjs
```

- [ ] **Step 2: Write the failing test**

Add to `tests/Feature/ReportTest.php`:

```php
test('reports spending groups expenses by category and sorts by amount', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $groceries = BudgetCategory::factory()->create(['user_id' => $user->id, 'name' => 'Groceries']);
    $dining = BudgetCategory::factory()->create(['user_id' => $user->id, 'name' => 'Dining out']);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $groceries->id, 'type' => 'expense', 'amount' => 300, 'transacted_at' => '2026-08-05']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $dining->id, 'type' => 'expense', 'amount' => 600, 'transacted_at' => '2026-08-06']);
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => null, 'type' => 'expense', 'amount' => 100, 'transacted_at' => '2026-08-07']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->has('spending', 3)
            ->where('spending.0.category.name', 'Dining out')
            ->where('spending.0.amount', 600.0)
            ->where('spending.0.pct', 0.6)
            ->where('spending.1.category.name', 'Groceries')
            ->where('spending.1.amount', 300.0)
            ->where('spending.2.category.name', 'Uncategorized')
            ->where('spending.2.amount', 100.0)
            ->where('spending.2.pct', 0.1)
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 3: Run test to confirm it fails**

```bash
php artisan test --compact --filter="reports spending"
```

Expected: FAIL — `spending` key missing.

- [ ] **Step 4: Add `computeSpending()` to `ReportController`**

Add this private method after `computeOverview()`:

```php
    private function computeSpending(User $user, Carbon $start, Carbon $end): array
    {
        $expenses = $user->transactions()
            ->with('budgetCategory')
            ->where('type', 'expense')
            ->whereDate('transacted_at', '>=', $start)
            ->whereDate('transacted_at', '<=', $end)
            ->get();

        $total = (float) $expenses->sum('amount');

        return $expenses
            ->groupBy('budget_category_id')
            ->map(function ($group) use ($total) {
                $amount = (float) $group->sum('amount');
                $category = $group->first()->budgetCategory;

                return [
                    'category' => $category ? [
                        'id' => $category->id,
                        'name' => $category->name,
                        'icon' => $category->icon,
                        'color' => $category->color,
                    ] : [
                        'id' => null,
                        'name' => 'Uncategorized',
                        'icon' => 'help',
                        'color' => '#8a8172',
                    ],
                    'amount' => $amount,
                    'pct' => $total > 0 ? $amount / $total : 0.0,
                ];
            })
            ->sortByDesc('amount')
            ->values()
            ->all();
    }
```

Wire into `index()` — add after `$overview = ...`:

```php
        $spending = $this->computeSpending($user, $period['start'], $period['end']);
```

Add `'spending' => $spending,` to the `Inertia::render` array, after `'overview' => $overview,`.

- [ ] **Step 5: Run test to confirm it passes**

```bash
php artisan test --compact --filter="reports spending"
```

Expected: PASS.

- [ ] **Step 6: Create `resources/js/components/reports/ReportBarChart.vue`**

```vue
<script setup lang="ts">
import { Bar } from 'vue-chartjs';
import { BarElement, CategoryScale, Chart as ChartJS, Legend, LinearScale, Title, Tooltip } from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, BarElement, CategoryScale, LinearScale);

type Dataset = {
    label?: string;
    data: number[];
    backgroundColor: string | string[];
    borderRadius?: number;
};

const props = defineProps<{
    labels: string[];
    datasets: Dataset[];
    showLegend?: boolean;
}>();

const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: props.showLegend ?? false } },
    scales: { y: { beginAtZero: true } },
};
</script>

<template>
    <div style="height: 220px">
        <Bar :data="{ labels, datasets }" :options="options" />
    </div>
</template>
```

- [ ] **Step 7: Add the tab bar and Spending tab to `Reports.vue`**

Add the `SpendingItem` type and `spending` prop:

```ts
type SpendingItem = {
    category: { id: number | null; name: string; icon: string; color: string };
    amount: number;
    pct: number;
};
```

Add `spending: SpendingItem[];` to `defineProps<{...}>()`.

Import the chart and add tab state, after the existing imports and before `const props = ...`:

```ts
import { ref } from 'vue';
import ReportBarChart from '@/components/reports/ReportBarChart.vue';
```

After `const periodOptions = ...`, add:

```ts
type Tab = 'spending' | 'budget' | 'cashflow' | 'payoff';

const activeTab = ref<Tab>('spending');

const tabOptions: { value: Tab; label: string }[] = [
    { value: 'spending', label: 'Spending' },
    { value: 'budget', label: 'Budget vs Actual' },
    { value: 'cashflow', label: 'Cash Flow' },
    { value: 'payoff', label: 'Payoff' },
];
```

Add the tab bar and Spending tab content to the template, after the overview stat cards `<div>`:

```html
<!-- Tab bar -->
<div style="display: flex; gap: 6px; border-bottom: 1px solid #e0dacd; padding-bottom: 2px">
    <button
        v-for="tab in tabOptions"
        :key="tab.value"
        type="button"
        @click="activeTab = tab.value"
        :style="{
            padding: '8px 14px',
            border: 'none',
            background: 'transparent',
            fontSize: '13.5px',
            fontWeight: 600,
            cursor: 'pointer',
            color: activeTab === tab.value ? '#211d17' : '#8a8172',
            borderBottom: activeTab === tab.value ? '2px solid #211d17' : '2px solid transparent',
        }"
    >{{ tab.label }}</button>
</div>

<!-- Spending tab -->
<div v-if="activeTab === 'spending'" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
    <div v-if="spending.length === 0" style="text-align: center; padding: 24px 0; color: #8a8172; font-size: 14px">
        No transactions in this period yet
    </div>
    <template v-else>
        <ReportBarChart
            :labels="spending.map(s => s.category.name)"
            :datasets="[{ data: spending.map(s => s.amount), backgroundColor: spending.map(s => s.category.color), borderRadius: 6 }]"
        />
        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 16px">
            <div v-for="item in spending" :key="item.category.id ?? 'uncategorized'" style="display: flex; align-items: center; gap: 10px">
                <span class="material-symbols-rounded" style="font-size: 18px" :style="{ color: item.category.color }">{{ item.category.icon }}</span>
                <span style="font-size: 13.5px; font-weight: 500; flex: 1">{{ item.category.name }}</span>
                <span style="font-size: 12px; color: #8a8172">{{ Math.round(item.pct * 100) }}%</span>
                <span style="font-size: 13.5px; font-weight: 700; width: 100px; text-align: right">{{ formatMoney(item.amount) }}</span>
            </div>
        </div>
    </template>
</div>
```

- [ ] **Step 8: Manually verify in the browser**

Open the app at `/reports`. Confirm the Spending tab shows a bar chart and list of categories sorted by amount, and other tab buttons switch the active tab (their content is added in later tasks).

- [ ] **Step 9: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 10: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 11: Commit**

```bash
git add package.json package-lock.json app/Http/Controllers/ReportController.php resources/js/components/reports/ReportBarChart.vue resources/js/pages/Reports.vue tests/Feature/ReportTest.php
git commit -m "feat: add spending breakdown tab with bar chart to Reports page"
```

---

### Task 4: Budget vs Actual tab

**Files:**
- Modify: `app/Http/Controllers/ReportController.php`
- Modify: `resources/js/pages/Reports.vue`
- Modify: `tests/Feature/ReportTest.php`

**Interfaces:**
- Consumes: `ReportBarChart.vue` from Task 3 (used here with two datasets and `showLegend: true`)
- Produces: private `computeBudgetVsActual(User $user, Carbon $start, Carbon $end, int $monthsInPeriod): array` returning a list of `{ category: {id, name, icon, color}, budgeted: float, actual: float, status: 'over'|'under' }`, rendered as the `budgetVsActual` prop.

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/ReportTest.php`:

```php
test('reports budget vs actual scales limit by months and flags over/under', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    $groceries = BudgetCategory::factory()->create(['user_id' => $user->id, 'name' => 'Groceries', 'limit_amount' => 1000]);

    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'budget_category_id' => $groceries->id, 'type' => 'expense', 'amount' => 1200, 'transacted_at' => '2026-08-05']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->has('budgetVsActual', 1)
            ->where('budgetVsActual.0.category.name', 'Groceries')
            ->where('budgetVsActual.0.budgeted', 1000.0)
            ->where('budgetVsActual.0.actual', 1200.0)
            ->where('budgetVsActual.0.status', 'over')
        );

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page
            ->where('budgetVsActual.0.budgeted', 3000.0)
            ->where('budgetVsActual.0.status', 'under')
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
php artisan test --compact --filter="budget vs actual"
```

Expected: FAIL — `budgetVsActual` key missing.

- [ ] **Step 3: Add `computeBudgetVsActual()` to `ReportController`**

Add this private method after `computeSpending()`:

```php
    private function computeBudgetVsActual(User $user, Carbon $start, Carbon $end, int $monthsInPeriod): array
    {
        return $user->budgetCategories()
            ->orderBy('name')
            ->get()
            ->map(function ($cat) use ($start, $end, $monthsInPeriod) {
                $actual = (float) $cat->transactions()
                    ->where('type', 'expense')
                    ->whereDate('transacted_at', '>=', $start)
                    ->whereDate('transacted_at', '<=', $end)
                    ->sum('amount');

                $budgeted = (float) $cat->limit_amount * $monthsInPeriod;

                return [
                    'category' => [
                        'id' => $cat->id,
                        'name' => $cat->name,
                        'icon' => $cat->icon,
                        'color' => $cat->color,
                    ],
                    'budgeted' => $budgeted,
                    'actual' => $actual,
                    'status' => $actual > $budgeted ? 'over' : 'under',
                ];
            })
            ->values()
            ->all();
    }
```

Wire into `index()` — add after `$spending = ...`:

```php
        $budgetVsActual = $this->computeBudgetVsActual($user, $period['start'], $period['end'], $period['monthsInPeriod']);
```

Add `'budgetVsActual' => $budgetVsActual,` to the `Inertia::render` array, after `'spending' => $spending,`.

- [ ] **Step 4: Run test to confirm it passes**

```bash
php artisan test --compact --filter="budget vs actual"
```

Expected: PASS.

- [ ] **Step 5: Add the Budget vs Actual tab to `Reports.vue`**

Add the `BudgetVsActualItem` type and prop:

```ts
type BudgetVsActualItem = {
    category: { id: number; name: string; icon: string; color: string };
    budgeted: number;
    actual: number;
    status: 'over' | 'under';
};
```

Add `budgetVsActual: BudgetVsActualItem[];` to `defineProps<{...}>()`.

Add the tab content block, directly after the closing `</div>` of the Spending tab block:

```html
<!-- Budget vs Actual tab -->
<div v-if="activeTab === 'budget'" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
    <div v-if="budgetVsActual.length === 0" style="text-align: center; padding: 24px 0; color: #8a8172; font-size: 14px">
        No budget categories yet
    </div>
    <template v-else>
        <ReportBarChart
            :labels="budgetVsActual.map(b => b.category.name)"
            :datasets="[
                { label: 'Budgeted', data: budgetVsActual.map(b => b.budgeted), backgroundColor: '#c3d1c9', borderRadius: 6 },
                { label: 'Actual', data: budgetVsActual.map(b => b.actual), backgroundColor: budgetVsActual.map(b => b.status === 'over' ? '#c0512c' : '#2f7d5b'), borderRadius: 6 },
            ]"
            :show-legend="true"
        />
        <div style="display: flex; flex-direction: column; gap: 8px; margin-top: 16px">
            <div v-for="item in budgetVsActual" :key="item.category.id" style="display: flex; align-items: center; gap: 10px">
                <span class="material-symbols-rounded" style="font-size: 18px" :style="{ color: item.category.color }">{{ item.category.icon }}</span>
                <span style="font-size: 13.5px; font-weight: 500; flex: 1">{{ item.category.name }}</span>
                <span
                    style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 20px"
                    :style="item.status === 'over' ? { background: '#fbe2d6', color: '#c0512c' } : { background: '#dff3e6', color: '#2f7d5b' }"
                >{{ item.status === 'over' ? 'Over' : 'Under' }}</span>
                <span style="font-size: 13.5px; font-weight: 700; width: 140px; text-align: right">{{ formatMoney(item.actual) }} / {{ formatMoney(item.budgeted) }}</span>
            </div>
        </div>
    </template>
</div>
```

- [ ] **Step 6: Manually verify in the browser**

Open `/reports`, switch to the "Budget vs Actual" tab. Confirm the grouped bar chart and list render, and over/under categories are colored correctly.

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/js/pages/Reports.vue tests/Feature/ReportTest.php
git commit -m "feat: add budget vs actual tab to Reports page"
```

---

### Task 5: Cash Flow tab with trend/budget projections

**Files:**
- Create: `resources/js/components/reports/ReportLineChart.vue`
- Modify: `app/Http/Controllers/ReportController.php`
- Modify: `resources/js/pages/Reports.vue`
- Modify: `tests/Feature/ReportTest.php`

**Interfaces:**
- Consumes: `resolvePeriod()` output (`start`, `end`, `isCurrent`) from Task 1, and `overview.budgetTotal` value (passed explicitly, not read from the `$overview` array structure) from Task 2.
- Produces: private `computeCashFlow(User $user, string $periodType, Carbon $start, Carbon $end, bool $isCurrent, float $budgetTotal): array` returning `{ series: {label, income, expenses, net}[], trendLine: (float|null)[]|null, budgetLine: (float|null)[]|null }`, rendered as the `cashFlow` prop.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ReportTest.php`:

```php
test('reports cash flow buckets the month weekly with actuals and projections', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    $account = Account::factory()->create(['user_id' => $user->id]);
    BudgetCategory::factory()->create(['user_id' => $user->id, 'limit_amount' => 3100]);

    // 750 spent by "today" (Aug 15) over 15 elapsed days -> daily trend rate of 50
    Transaction::factory()->create(['user_id' => $user->id, 'account_id' => $account->id, 'type' => 'expense', 'amount' => 750, 'transacted_at' => '2026-08-05']);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->has('cashFlow.series', 5)
            ->where('cashFlow.series.0.label', 'Aug 1')
            ->where('cashFlow.series.0.expenses', 750.0)
            ->where('cashFlow.series.0.net', -750.0)
            ->where('cashFlow.series.1.expenses', 0.0)
            ->where('cashFlow.series.3.expenses', null)
            ->where('cashFlow.series.4.expenses', null)
            ->where('cashFlow.trendLine', [null, null, null, 350.0, 500.0])
            ->where('cashFlow.budgetLine', [null, null, null, 700.0, 1000.0])
        );

    Carbon::setTestNow();
});

test('reports cash flow buckets a quarter into 3 monthly buckets', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'quarter']))
        ->assertInertia(fn ($page) => $page
            ->has('cashFlow.series', 3)
            ->where('cashFlow.series.0.label', 'Jul')
            ->where('cashFlow.series.1.label', 'Aug')
            ->where('cashFlow.series.2.label', 'Sep')
        );

    Carbon::setTestNow();
});

test('reports cash flow has no projection lines for a past period', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('reports', ['period' => 'month', 'date' => '2026-06-01']))
        ->assertInertia(fn ($page) => $page
            ->where('cashFlow.trendLine', null)
            ->where('cashFlow.budgetLine', null)
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter="cash flow"
```

Expected: FAIL — `cashFlow` key missing.

- [ ] **Step 3: Add `computeCashFlow()` to `ReportController`**

Add this private method after `computeBudgetVsActual()`:

```php
    private function computeCashFlow(User $user, string $periodType, Carbon $start, Carbon $end, bool $isCurrent, float $budgetTotal): array
    {
        $buckets = [];

        if ($periodType === 'month') {
            $cursor = $start->copy();
            while ($cursor->lte($end)) {
                $bucketEnd = $cursor->copy()->addDays(6);
                if ($bucketEnd->gt($end)) {
                    $bucketEnd = $end->copy();
                }
                $buckets[] = ['start' => $cursor->copy(), 'end' => $bucketEnd->copy(), 'label' => $cursor->format('M j')];
                $cursor = $bucketEnd->copy()->addDay();
            }
        } else {
            $cursor = $start->copy()->startOfMonth();
            while ($cursor->lte($end)) {
                $bucketEnd = $cursor->copy()->endOfMonth();
                if ($bucketEnd->gt($end)) {
                    $bucketEnd = $end->copy();
                }
                $buckets[] = ['start' => $cursor->copy(), 'end' => $bucketEnd->copy(), 'label' => $cursor->format('M')];
                $cursor = $cursor->copy()->addMonthNoOverflow()->startOfMonth();
            }
        }

        $today = Carbon::now()->startOfDay();

        $series = [];
        foreach ($buckets as $bucket) {
            $isFuture = $isCurrent && $bucket['start']->copy()->startOfDay()->gt($today);

            if ($isFuture) {
                $series[] = ['label' => $bucket['label'], 'income' => null, 'expenses' => null, 'net' => null];

                continue;
            }

            $income = (float) $user->transactions()
                ->where('type', 'income')
                ->whereDate('transacted_at', '>=', $bucket['start'])
                ->whereDate('transacted_at', '<=', $bucket['end'])
                ->sum('amount');

            $expenses = (float) $user->transactions()
                ->where('type', 'expense')
                ->whereDate('transacted_at', '>=', $bucket['start'])
                ->whereDate('transacted_at', '<=', $bucket['end'])
                ->sum('amount');

            $series[] = ['label' => $bucket['label'], 'income' => $income, 'expenses' => $expenses, 'net' => $income - $expenses];
        }

        $trendLine = null;
        $budgetLine = null;

        if ($isCurrent) {
            $elapsedDays = max(1, $start->copy()->startOfDay()->diffInDays($today) + 1);
            $totalDays = max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1);

            $expensesSoFar = (float) $user->transactions()
                ->where('type', 'expense')
                ->whereDate('transacted_at', '>=', $start)
                ->whereDate('transacted_at', '<=', $today)
                ->sum('amount');

            $dailyTrendRate = $expensesSoFar / $elapsedDays;
            $dailyBudgetRate = $budgetTotal / $totalDays;

            $runningTrend = 0.0;
            $runningBudget = 0.0;
            $trendLine = [];
            $budgetLine = [];

            foreach ($buckets as $bucket) {
                $isFuture = $bucket['start']->copy()->startOfDay()->gt($today);

                if (! $isFuture) {
                    $trendLine[] = null;
                    $budgetLine[] = null;

                    continue;
                }

                $bucketDays = $bucket['start']->copy()->startOfDay()->diffInDays($bucket['end']->copy()->startOfDay()) + 1;
                $runningTrend += $dailyTrendRate * $bucketDays;
                $runningBudget += $dailyBudgetRate * $bucketDays;
                $trendLine[] = round($runningTrend, 2);
                $budgetLine[] = round($runningBudget, 2);
            }
        }

        return [
            'series' => $series,
            'trendLine' => $trendLine,
            'budgetLine' => $budgetLine,
        ];
    }
```

Wire into `index()` — add after `$budgetVsActual = ...`:

```php
        $cashFlow = $this->computeCashFlow($user, $periodType, $period['start'], $period['end'], $period['isCurrent'], $overview['budgetTotal']);
```

Add `'cashFlow' => $cashFlow,` to the `Inertia::render` array, after `'budgetVsActual' => $budgetVsActual,`.

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php artisan test --compact --filter="cash flow"
```

Expected: PASS.

- [ ] **Step 5: Create `resources/js/components/reports/ReportLineChart.vue`**

```vue
<script setup lang="ts">
import { Line } from 'vue-chartjs';
import { CategoryScale, Chart as ChartJS, Legend, LinearScale, LineElement, PointElement, Title, Tooltip } from 'chart.js';

ChartJS.register(Title, Tooltip, Legend, LineElement, PointElement, CategoryScale, LinearScale);

type Dataset = {
    label: string;
    data: (number | null)[];
    borderColor: string;
    borderDash?: number[];
    backgroundColor?: string;
    fill?: boolean;
    spanGaps?: boolean;
};

defineProps<{
    labels: string[];
    datasets: Dataset[];
}>();

const options = {
    responsive: true,
    maintainAspectRatio: false,
    plugins: { legend: { display: true } },
    scales: { y: { beginAtZero: true } },
};
</script>

<template>
    <div style="height: 240px">
        <Line :data="{ labels, datasets }" :options="options" />
    </div>
</template>
```

- [ ] **Step 6: Add the Cash Flow tab to `Reports.vue`**

Add the `CashFlowBucket` and `CashFlow` types and prop:

```ts
type CashFlowBucket = { label: string; income: number | null; expenses: number | null; net: number | null };
type CashFlow = { series: CashFlowBucket[]; trendLine: (number | null)[] | null; budgetLine: (number | null)[] | null };
```

Add `cashFlow: CashFlow;` to `defineProps<{...}>()`.

Import the line chart:

```ts
import ReportLineChart from '@/components/reports/ReportLineChart.vue';
```

Add a computed just above the template's opening tag (in the `<script setup>` block, after `tabOptions`):

```ts
const hasCashFlowData = computed(() =>
    props.cashFlow.series.some(b => (b.income ?? 0) > 0 || (b.expenses ?? 0) > 0) || props.cashFlow.trendLine !== null,
);
```

Make sure `computed` is imported alongside `ref` from `'vue'`.

Add the tab content block, directly after the closing `</div>` of the Budget vs Actual tab block:

```html
<!-- Cash Flow tab -->
<div v-if="activeTab === 'cashflow'" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
    <div v-if="!hasCashFlowData" style="text-align: center; padding: 24px 0; color: #8a8172; font-size: 14px">
        No transactions in this period yet
    </div>
    <ReportLineChart
        v-else
        :labels="cashFlow.series.map(b => b.label)"
        :datasets="[
            { label: 'Net', data: cashFlow.series.map(b => b.net), borderColor: '#211d17', backgroundColor: '#211d17' },
            ...(cashFlow.trendLine ? [{ label: 'Trend projection', data: cashFlow.trendLine, borderColor: '#3f7fb0', borderDash: [6, 4], spanGaps: false }] : []),
            ...(cashFlow.budgetLine ? [{ label: 'Budget projection', data: cashFlow.budgetLine, borderColor: '#2f7d5b', borderDash: [6, 4], spanGaps: false }] : []),
        ]"
    />
</div>
```

- [ ] **Step 7: Manually verify in the browser**

Open `/reports`, switch to the "Cash Flow" tab. Confirm the line chart renders with the actual net line, and (for the current month) dashed trend/budget projection lines extending past today.

- [ ] **Step 8: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 10: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/js/components/reports/ReportLineChart.vue resources/js/pages/Reports.vue tests/Feature/ReportTest.php
git commit -m "feat: add cash flow tab with trend/budget projections to Reports page"
```

---

### Task 6: Payoff tab (debts + goals)

**Files:**
- Modify: `app/Http/Controllers/ReportController.php`
- Modify: `resources/js/pages/Reports.vue`
- Modify: `tests/Feature/ReportTest.php`

**Interfaces:**
- Produces: private `computePayoff(User $user): array` returning `{ debts: {id, name, icon, color, months: int|null, payoffDate: string|null, onTrack: bool}[], goals: {id, name, icon, color, months: int|null, targetDate: string|null}[] }`, rendered as the `payoff` prop.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/ReportTest.php` (needs `Debt`, `Goal` added to the `use` block):

```php
use App\Models\Debt;
use App\Models\Goal;
```

```php
test('reports payoff computes months and date for a debt with no interest', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Debt::factory()->create(['user_id' => $user->id, 'name' => 'Car Loan', 'balance' => 1200, 'per_month' => 100, 'interest_rate' => null]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('payoff.debts.0.months', 12)
            ->where('payoff.debts.0.payoffDate', 'Aug 2027')
            ->where('payoff.debts.0.onTrack', true)
        );

    Carbon::setTestNow();
});

test('reports payoff flags a debt as not on track when payment does not cover interest', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Debt::factory()->create([
        'user_id' => $user->id,
        'balance' => 10000,
        'per_month' => 50,
        'interest_rate' => 24,
        'interest_rate_period' => 'annual',
    ]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('payoff.debts.0.onTrack', false)
            ->where('payoff.debts.0.months', null)
            ->where('payoff.debts.0.payoffDate', null)
        );

    Carbon::setTestNow();
});

test('reports payoff computes months and date for a goal', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Goal::factory()->create(['user_id' => $user->id, 'saved' => 2000, 'target' => 5000, 'per_month' => 1000]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->where('payoff.goals.0.months', 3)
            ->where('payoff.goals.0.targetDate', 'Nov 2026')
        );

    Carbon::setTestNow();
});

test('reports payoff shows null months for a goal with no monthly amount', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Goal::factory()->create(['user_id' => $user->id, 'saved' => 1000, 'target' => 5000, 'per_month' => 0]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page->where('payoff.goals.0.months', null));

    Carbon::setTestNow();
});

test('reports payoff excludes paid-off debts and completed goals', function () {
    Carbon::setTestNow(Carbon::parse('2026-08-15'));
    $user = User::factory()->create();
    Debt::factory()->create(['user_id' => $user->id, 'balance' => 0]);
    Goal::factory()->create(['user_id' => $user->id, 'saved' => 5000, 'target' => 5000]);

    $this->actingAs($user)
        ->get(route('reports'))
        ->assertInertia(fn ($page) => $page
            ->has('payoff.debts', 0)
            ->has('payoff.goals', 0)
        );

    Carbon::setTestNow();
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter="reports payoff"
```

Expected: FAIL — `payoff` key missing.

- [ ] **Step 3: Add `computePayoff()` to `ReportController`**

Add this private method after `computeCashFlow()`:

```php
    private function computePayoff(User $user): array
    {
        $today = Carbon::now()->startOfDay();

        $debts = $user->debts()
            ->where('balance', '>', 0)
            ->orderBy('position')
            ->get()
            ->map(function ($debt) use ($today) {
                $monthlyRate = $debt->interest_rate === null ? 0.0
                    : ($debt->interest_rate_period === 'monthly'
                        ? (float) $debt->interest_rate / 100
                        : (float) $debt->interest_rate / 100 / 12);

                $balance = (float) $debt->balance;
                $perMonth = (float) $debt->per_month;
                $months = 0;
                $onTrack = true;

                while ($balance > 0 && $months < 600) {
                    $interestAccrued = $balance * $monthlyRate;

                    if ($monthlyRate > 0 && $perMonth <= $interestAccrued) {
                        $onTrack = false;

                        break;
                    }

                    $balance = $balance + $interestAccrued - $perMonth;
                    $months++;
                }

                if ($balance > 0) {
                    $onTrack = false;
                }

                return [
                    'id' => $debt->id,
                    'name' => $debt->name,
                    'icon' => $debt->icon,
                    'color' => $debt->color,
                    'months' => $onTrack ? $months : null,
                    'payoffDate' => $onTrack ? $today->copy()->addMonthsNoOverflow($months)->format('M Y') : null,
                    'onTrack' => $onTrack,
                ];
            })
            ->values()
            ->all();

        $goals = $user->goals()
            ->whereColumn('saved', '<', 'target')
            ->orderBy('position')
            ->get()
            ->map(function ($goal) use ($today) {
                $perMonth = (float) $goal->per_month;
                $months = $perMonth > 0
                    ? (int) ceil(((float) $goal->target - (float) $goal->saved) / $perMonth)
                    : null;

                return [
                    'id' => $goal->id,
                    'name' => $goal->name,
                    'icon' => $goal->icon,
                    'color' => $goal->color,
                    'months' => $months,
                    'targetDate' => $months !== null ? $today->copy()->addMonthsNoOverflow($months)->format('M Y') : null,
                ];
            })
            ->values()
            ->all();

        return ['debts' => $debts, 'goals' => $goals];
    }
```

Wire into `index()` — add after `$cashFlow = ...`:

```php
        $payoff = $this->computePayoff($user);
```

Add `'payoff' => $payoff,` to the `Inertia::render` array, after `'cashFlow' => $cashFlow,`.

- [ ] **Step 4: Run tests to confirm they pass**

```bash
php artisan test --compact --filter="reports payoff"
```

Expected: PASS.

- [ ] **Step 5: Add the Payoff tab to `Reports.vue`**

Add the `PayoffDebt`, `PayoffGoal`, `Payoff` types and prop:

```ts
type PayoffDebt = { id: number; name: string; icon: string; color: string; months: number | null; payoffDate: string | null; onTrack: boolean };
type PayoffGoal = { id: number; name: string; icon: string; color: string; months: number | null; targetDate: string | null };
type Payoff = { debts: PayoffDebt[]; goals: PayoffGoal[] };
```

Add `payoff: Payoff;` to `defineProps<{...}>()`.

Add the tab content block, directly after the closing `</div>` of the Cash Flow tab block:

```html
<!-- Payoff tab -->
<div v-if="activeTab === 'payoff'" style="display: flex; flex-direction: column; gap: 16px">
    <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; margin-bottom: 10px">Debts</div>
        <div v-if="payoff.debts.length === 0" style="text-align: center; padding: 16px 0; color: #8a8172; font-size: 14px">No debts to pay off</div>
        <div v-else style="display: flex; flex-direction: column; gap: 8px">
            <div v-for="debt in payoff.debts" :key="debt.id" style="display: flex; align-items: center; gap: 10px">
                <span class="material-symbols-rounded" style="font-size: 18px" :style="{ color: debt.color }">{{ debt.icon }}</span>
                <span style="font-size: 13.5px; font-weight: 500; flex: 1">{{ debt.name }}</span>
                <span v-if="debt.onTrack" style="font-size: 13.5px; font-weight: 700">{{ debt.payoffDate }} · {{ debt.months }} mo</span>
                <span v-else style="font-size: 12px; font-weight: 600; padding: 2px 8px; border-radius: 20px; background: #fbe2d6; color: #c0512c">Not on track</span>
            </div>
        </div>
    </div>

    <div style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 14px; padding: 18px 20px">
        <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 15px; margin-bottom: 10px">Goals</div>
        <div v-if="payoff.goals.length === 0" style="text-align: center; padding: 16px 0; color: #8a8172; font-size: 14px">No goals in progress</div>
        <div v-else style="display: flex; flex-direction: column; gap: 8px">
            <div v-for="goal in payoff.goals" :key="goal.id" style="display: flex; align-items: center; gap: 10px">
                <span class="material-symbols-rounded" style="font-size: 18px" :style="{ color: goal.color }">{{ goal.icon }}</span>
                <span style="font-size: 13.5px; font-weight: 500; flex: 1">{{ goal.name }}</span>
                <span v-if="goal.months !== null" style="font-size: 13.5px; font-weight: 700">{{ goal.targetDate }} · {{ goal.months }} mo</span>
                <span v-else style="font-size: 12px; color: #8a8172">Set a monthly amount to see an estimate</span>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 6: Manually verify in the browser**

Open `/reports`, switch to the "Payoff" tab. Confirm debts and goals list with correct payoff estimates, and the "Not on track" / "Set a monthly amount" fallbacks render for the appropriate edge cases (test with a debt whose `per_month` is very low relative to its interest rate, and a goal with `per_month = 0`, via the Debt/Goals pages).

- [ ] **Step 7: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 8: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/ReportController.php resources/js/pages/Reports.vue tests/Feature/ReportTest.php
git commit -m "feat: add debt and goal payoff tab to Reports page"
```

---

### Task 7: Final polish and full verification

**Files:**
- Modify: `resources/js/pages/Reports.vue` (empty states already added per-tab in Tasks 3, 4, 6 — this task only adds the today-context strip and does a full pass)

**Interfaces:**
- None new — this task wires nothing new, only verifies the full page end-to-end.

- [ ] **Step 1: Add a "today context" strip for consistency with other Money pages**

`Budgets.vue` and other Money pages show a small strip with today's date at the top. Skip this for Reports — the spec doesn't call for it and adding it would require a new controller prop (`todayContext`) not in the design. Confirm by re-reading [the design spec](../specs/2026-08-04-money-reports-page-design.md) that no such strip was specified. (No code change in this step — it's a deliberate no-op to avoid scope creep.)

- [ ] **Step 2: Run the full backend test suite**

```bash
php artisan test --compact
```

Expected: all passing, including all `ReportTest.php` tests from Tasks 1–6.

- [ ] **Step 3: Run Pint one more time**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 4: Full browser verification**

Start the dev server preview and walk through the whole page:

1. Navigate to `/reports`. Confirm the "Reports" nav item in the Money sidebar section is highlighted.
2. Confirm the Month/Quarter/Year pills switch periods and the period label updates.
3. Click the prev arrow — confirm it navigates to the previous period and the next arrow becomes enabled.
4. Click the next arrow repeatedly until back at the current period — confirm it becomes disabled again.
5. Click through all four tabs (Spending, Budget vs Actual, Cash Flow, Payoff) — confirm each renders without console errors.
6. Check the browser console and network tab for errors on each interaction.
7. Resize to a mobile viewport — confirm the header row and stat cards wrap sensibly (no horizontal overflow).

- [ ] **Step 5: Fix any issues found during verification**

If any issues are found, fix them in the relevant file (`ReportController.php` or `Reports.vue`), re-run the affected test filter, then re-run the full suite (Step 2) before proceeding.

- [ ] **Step 6: Commit (only if fixes were made in Step 5)**

```bash
git add -A -- app/Http/Controllers/ReportController.php resources/js/pages/Reports.vue resources/js/components/reports
git commit -m "fix: polish Reports page after browser verification"
```

If no fixes were needed, skip this step — there is nothing to commit.
