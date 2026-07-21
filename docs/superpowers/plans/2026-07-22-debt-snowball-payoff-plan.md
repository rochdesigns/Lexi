# Debt Snowball Payoff Plan Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an optional interest rate field to debts and a reactive snowball payoff plan section to the Debt page.

**Architecture:** Interest rate is stored on each debt (nullable). A pure TypeScript utility (`snowball.ts`) computes the month-by-month simulation. Debt.vue imports it and makes the result reactive to a user-editable monthly budget input. No new routes or pages.

**Tech Stack:** PHP 8.4, Laravel 13, Pest 4, Inertia v3, Vue 3, TypeScript, Tailwind v4.

## Global Constraints

- All PHP must pass `vendor/bin/pint --dirty --format agent` before committing
- Tests run with `php artisan test --compact`
- No new routes or pages — all changes are within existing Debt page
- `interest_rate` stored as a percentage (e.g. `19.99` = 19.99% APR), nullable
- No new npm dependencies

---

### Task 1: Add `interest_rate` to the debt data model

**Files:**
- Create: `database/migrations/[timestamp]_add_interest_rate_to_debts_table.php` (via artisan)
- Modify: `app/Models/Debt.php`
- Modify: `database/factories/DebtFactory.php`
- Test: `tests/Feature/DebtTest.php`

**Interfaces:**
- Produces: `Debt::$fillable` includes `interest_rate`; `Debt::$casts` maps it to `'decimal:2'`; factory can create debts with or without a rate

- [ ] **Step 1: Write the failing tests**

Add these four tests to `tests/Feature/DebtTest.php` before the final closing `});`:

```php
test('interest_rate is stored when creating a debt', function (): void {
    $this->post('/debts', [
        'name' => 'Credit card',
        'icon' => 'credit_card',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 5000,
        'original' => 10000,
        'per_month' => 200,
        'interest_rate' => 19.99,
    ])->assertRedirect();

    $debt = $this->user->debts()->where('name', 'Credit card')->first();
    expect((float) $debt->interest_rate)->toBe(19.99);
});

test('interest_rate is nullable when creating a debt', function (): void {
    $this->post('/debts', [
        'name' => 'Car loan',
        'icon' => 'directions_car',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 8000,
        'original' => 15000,
        'per_month' => 300,
    ])->assertRedirect();

    $debt = $this->user->debts()->where('name', 'Car loan')->first();
    expect($debt->interest_rate)->toBeNull();
});

test('interest_rate is validated between 0 and 100', function (): void {
    $this->post('/debts', [
        'name' => 'Test',
        'icon' => 'credit_card',
        'color' => '#3f7fb0',
        'bg' => '#dcebf5',
        'balance' => 1000,
        'original' => 2000,
        'per_month' => 100,
        'interest_rate' => 150,
    ])->assertSessionHasErrors('interest_rate');
});

test('interest_rate is returned in the debts index', function (): void {
    Debt::factory()->for($this->user)->create(['interest_rate' => 24.0]);

    $this->get('/debt')
        ->assertInertia(fn ($page) => $page
            ->where('debts.0.interest_rate', 24.0)
        );
});
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test --compact --filter="interest_rate"
```

Expected: 4 failures (column doesn't exist / field not validated).

- [ ] **Step 3: Create the migration**

```bash
php artisan make:migration add_interest_rate_to_debts_table --no-interaction
```

Open the generated file (check `database/migrations/` for the newest file) and fill in:

```php
public function up(): void
{
    Schema::table('debts', function (Blueprint $table): void {
        $table->decimal('interest_rate', 5, 2)->nullable()->after('per_month');
    });
}

public function down(): void
{
    Schema::table('debts', function (Blueprint $table): void {
        $table->dropColumn('interest_rate');
    });
}
```

- [ ] **Step 4: Run the migration**

```bash
php artisan migrate --no-interaction
```

- [ ] **Step 5: Update `app/Models/Debt.php`**

In `$fillable`, add `'interest_rate'` after `'per_month'`:

```php
protected $fillable = ['user_id', 'name', 'icon', 'color', 'bg', 'balance', 'original', 'per_month', 'interest_rate', 'due_day', 'position'];
```

In `$casts`, add `'interest_rate' => 'decimal:2'` after the `per_month` cast:

```php
protected $casts = [
    'balance' => 'decimal:2',
    'original' => 'decimal:2',
    'per_month' => 'decimal:2',
    'interest_rate' => 'decimal:2',
    'due_day' => 'integer',
];
```

- [ ] **Step 6: Update `app/Http/Controllers/DebtController.php`**

In the `index()` map closure, add after the `'per_month'` line:

```php
'interest_rate' => $d->interest_rate ? (float) $d->interest_rate : null,
```

In the `rules()` method, add after the `'per_month'` rule:

```php
'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
```

- [ ] **Step 7: Update `database/factories/DebtFactory.php`**

Add `'interest_rate' => null` to the `definition()` return array (after `per_month`):

```php
'per_month' => $this->faker->randomElement([100, 200, 300, 500]),
'interest_rate' => null,
'due_day' => $this->faker->numberBetween(1, 28),
```

- [ ] **Step 8: Run Pint**

```bash
vendor/bin/pint --dirty --format agent
```

- [ ] **Step 9: Run the failing tests**

```bash
php artisan test --compact --filter="interest_rate"
```

Expected: 4 passing.

- [ ] **Step 10: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 11: Commit**

```bash
git add database/migrations app/Models/Debt.php app/Http/Controllers/DebtController.php database/factories/DebtFactory.php tests/Feature/DebtTest.php
git commit -m "feat: add nullable interest_rate field to debts"
```

---

### Task 2: Wire `interest_rate` into the Debt form

**Files:**
- Modify: `resources/js/pages/Debt.vue`

**Interfaces:**
- Consumes: `interest_rate: number | null` on the `Debt` prop type (from Task 1 controller change)
- Produces: `interest_rate` is sent in POST/PATCH requests, displayed in the add/edit form

- [ ] **Step 1: Add `interest_rate` to the `Debt` TypeScript type**

In `Debt.vue`, the `Debt` type definition currently ends with `position: number`. Add `interest_rate` before `position`:

```ts
type Debt = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    balance: number;
    original: number;
    per_month: number;
    interest_rate: number | null;
    due_day: number | null;
    due_label: string | null;
    months_left: number | null;
    position: number;
};
```

- [ ] **Step 2: Add `interest_rate` to the `useForm` object**

The `form` object currently has `due_day: '' as number | string`. Add `interest_rate` after it:

```ts
const form = useForm({
    name: '',
    icon: 'credit_card',
    color: '#3f7fb0',
    bg: '#dcebf5',
    balance: '' as number | string,
    original: '' as number | string,
    per_month: '' as number | string,
    due_day: '' as number | string,
    interest_rate: '' as number | string,
});
```

- [ ] **Step 3: Populate `interest_rate` in `openEdit`**

In `openEdit(debt: Debt)`, after the line `form.due_day = debt.due_day ? String(debt.due_day) : '';`, add:

```ts
form.interest_rate = debt.interest_rate ? String(debt.interest_rate) : '';
```

- [ ] **Step 4: Include `interest_rate` in `form.transform`**

In `submitForm()`, the `form.transform` call currently maps `due_day`. Add `interest_rate` to the returned object:

```ts
form.transform((data) => ({
    ...data,
    balance: (evaluateArithmetic(String(data.balance)) ?? parseFloat(String(data.balance))) || 0,
    original: (evaluateArithmetic(String(data.original)) ?? parseFloat(String(data.original))) || 0,
    per_month: (evaluateArithmetic(String(data.per_month)) ?? parseFloat(String(data.per_month))) || 0,
    due_day: data.due_day ? parseInt(String(data.due_day), 10) : null,
    interest_rate: data.interest_rate ? parseFloat(String(data.interest_rate)) : null,
}));
```

- [ ] **Step 5: Add the interest rate form field to the template**

In the template, after the "Monthly payment + Due day" `<div>` grid block (which ends around the `</div>` closing the two-column grid containing "Payment due day"), add a new full-width field row before the `<!-- Buttons -->` comment:

```html
<!-- Interest rate (optional) -->
<div style="display: flex; flex-direction: column; gap: 6px">
    <label style="font-size: 13px; font-weight: 600; color: #4a4436">
        Annual interest rate %
        <span style="font-weight: 400; color: #8a8172">(optional)</span>
    </label>
    <input v-model="form.interest_rate" type="number" inputmode="decimal" min="0" max="100" step="0.01"
        placeholder="e.g. 19.99"
        style="height: 40px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 12px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; width: 100%; box-sizing: border-box; outline: none" />
    <div v-if="form.errors.interest_rate" style="font-size: 12px; color: #c0512c">{{ form.errors.interest_rate }}</div>
</div>
```

- [ ] **Step 6: Verify manually in the browser**

Open the app at the Debt page. Add a debt with an interest rate — confirm the form submits and the value persists. Edit the debt — confirm the rate pre-fills. Add a debt without a rate — confirm it saves as null without errors.

- [ ] **Step 7: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 8: Commit**

```bash
git add resources/js/pages/Debt.vue
git commit -m "feat: add interest rate field to debt add/edit form"
```

---

### Task 3: Snowball utility + Payoff Plan UI section

**Files:**
- Create: `resources/js/utils/snowball.ts`
- Modify: `resources/js/pages/Debt.vue`

**Interfaces:**
- Consumes: `Debt` prop type from Task 2 (includes `interest_rate`, `original`)
- Produces: `computeSnowball(debts, budget)` returns `SnowballResult[] | null`; Debt.vue renders the Payoff Plan section below the card grid

- [ ] **Step 1: Create `resources/js/utils/snowball.ts`**

```typescript
export type SnowballDebt = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    balance: number;
    original: number;
    per_month: number;
    interest_rate: number | null;
};

export type SnowballResult = {
    id: number;
    name: string;
    icon: string;
    color: string;
    bg: string;
    startBalance: number;
    paidPct: number;
    payoffMonth: number;
    payoffDate: string;
    totalInterest: number | null;
};

export function computeSnowball(
    debts: SnowballDebt[],
    monthlyBudget: number,
): SnowballResult[] | null {
    const active = debts.filter((d) => d.balance > 0);
    if (active.length === 0) return [];

    const totalMin = active.reduce((s, d) => s + d.per_month, 0);
    if (monthlyBudget < totalMin) return null;

    type Work = {
        id: number; name: string; icon: string; color: string; bg: string;
        original: number; startBalance: number; balance: number;
        perMonth: number; rate: number | null;
        totalInterest: number; payoffMonth: number | null;
    };

    const work: Work[] = active
        .slice()
        .sort((a, b) => a.balance - b.balance)
        .map((d) => ({
            id: d.id, name: d.name, icon: d.icon, color: d.color, bg: d.bg,
            original: d.original, startBalance: d.balance, balance: d.balance,
            perMonth: d.per_month, rate: d.interest_rate,
            totalInterest: 0, payoffMonth: null,
        }));

    let month = 0;
    while (work.some((d) => d.payoffMonth === null) && month < 600) {
        month++;

        for (const d of work) {
            if (d.payoffMonth !== null) continue;
            if (d.rate) {
                const interest = d.balance * (d.rate / 100 / 12);
                d.balance += interest;
                d.totalInterest += interest;
            }
        }

        let leftover = monthlyBudget;
        for (const d of work) {
            if (d.payoffMonth !== null) continue;
            const payment = Math.min(d.balance, d.perMonth);
            d.balance = Math.round((d.balance - payment) * 100) / 100;
            leftover -= payment;
            if (d.balance <= 0) {
                d.balance = 0;
                d.payoffMonth = month;
            }
        }

        const target = work.find((d) => d.payoffMonth === null);
        if (target && leftover > 0.001) {
            target.balance = Math.max(0, Math.round((target.balance - leftover) * 100) / 100);
            if (target.balance <= 0) {
                target.balance = 0;
                target.payoffMonth = month;
            }
        }
    }

    const now = new Date();
    return work.map((d) => {
        const months = d.payoffMonth ?? 600;
        const date = new Date(now.getFullYear(), now.getMonth() + months, 1);
        const paidPct = d.original > 0
            ? Math.min(100, Math.round(((d.original - d.startBalance) / d.original) * 100))
            : 0;
        return {
            id: d.id,
            name: d.name,
            icon: d.icon,
            color: d.color,
            bg: d.bg,
            startBalance: d.startBalance,
            paidPct,
            payoffMonth: months,
            payoffDate: date.toLocaleDateString('en-PH', { month: 'short', year: 'numeric' }),
            totalInterest: d.rate ? Math.round(d.totalInterest * 100) / 100 : null,
        };
    });
}
```

- [ ] **Step 2: Add imports and reactive state to Debt.vue**

At the top of the `<script setup>` block, add the import alongside existing imports:

```ts
import { computeSnowball } from '@/utils/snowball';
```

After the existing `computed` usages (around line 207, after the arithmetic hints), add:

```ts
// ── Snowball plan ──────────────────────────────────────────────────────────────

const monthlyBudget = ref(
    props.debts.filter(d => d.balance > 0).reduce((s, d) => s + d.per_month, 0)
);

const snowballPlan = computed(() =>
    computeSnowball(props.debts, monthlyBudget.value)
);

const minimumsTotal = computed(() =>
    props.debts.filter(d => d.balance > 0).reduce((s, d) => s + d.per_month, 0)
);
```

Make sure `ref` is already imported from `'vue'` — it is, alongside `computed`.

- [ ] **Step 3: Add the Payoff Plan section to the template**

Add this block after the closing `</div>` of the debt cards grid (the `v-else` block), before the `<!-- CRUD modal -->` comment:

```html
<!-- Payoff Plan -->
<div v-if="debts.length > 0" style="background: #fffdf8; border: 1px solid #e0dacd; border-radius: 22px; padding: 28px 30px; box-shadow: 0 8px 24px -20px rgba(60,48,20,.5)">

    <!-- Header + budget input -->
    <div style="display: flex; align-items: center; gap: 16px; flex-wrap: wrap; margin-bottom: 20px">
        <div>
            <div style="font-family: 'Bricolage Grotesque'; font-weight: 700; font-size: 18px; letter-spacing: -.01em">Snowball Payoff Plan</div>
            <div style="font-size: 13px; color: #8a8172; margin-top: 2px">Pay off the smallest balance first, roll it into the next</div>
        </div>
        <div style="margin-left: auto; display: flex; align-items: center; gap: 8px; flex-wrap: wrap">
            <label style="font-size: 13px; font-weight: 600; color: #4a4436; white-space: nowrap">Monthly budget (₱)</label>
            <input v-model.number="monthlyBudget" type="number" min="0" step="100"
                style="width: 130px; height: 36px; border: 1px solid #e0dacd; border-radius: 10px; padding: 0 10px; font-family: 'Instrument Sans'; font-size: 14px; background: #fffdf8; outline: none" />
        </div>
    </div>

    <!-- Budget too low warning -->
    <div v-if="snowballPlan === null"
        style="display: flex; align-items: center; gap: 8px; background: #fdf3ed; border: 1px solid #f5c9a8; border-radius: 12px; padding: 12px 16px; font-size: 13.5px; color: #8a3800">
        <span class="material-symbols-rounded" style="font-size: 18px">warning</span>
        Increase your budget to at least ₱{{ Math.ceil(minimumsTotal).toLocaleString('en-PH') }} to cover all minimum payments.
    </div>

    <!-- All debts paid -->
    <div v-else-if="snowballPlan.length === 0"
        style="text-align: center; padding: 24px 0">
        <span class="msf" style="font-size: 36px; display: block; margin-bottom: 8px; color: #2f7d5b">celebration</span>
        <div style="font-weight: 600; font-size: 15px; color: #2f7d5b">All debts paid off!</div>
    </div>

    <!-- Snowball ranked list -->
    <div v-else style="display: flex; flex-direction: column; gap: 10px">
        <div v-for="(item, index) in snowballPlan" :key="item.id"
            style="display: flex; align-items: center; gap: 12px; padding: 14px 16px; border: 1px solid #e0dacd; border-radius: 14px; background: #fdfbf5">

            <!-- Rank pill -->
            <div style="width: 28px; height: 28px; border-radius: 50%; background: #f1ede3; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 700; color: #4a4436; flex: none">
                {{ index + 1 }}
            </div>

            <!-- Icon tile -->
            <span style="width: 36px; height: 36px; border-radius: 10px; display: flex; align-items: center; justify-content: center; flex: none"
                :style="{ background: item.bg }">
                <span class="msf" style="font-size: 19px" :style="{ color: item.color }">{{ item.icon }}</span>
            </span>

            <!-- Name + meta -->
            <div style="flex: 1; min-width: 0">
                <div style="display: flex; align-items: center; gap: 7px; flex-wrap: wrap">
                    <span style="font-weight: 600; font-size: 14px">{{ item.name }}</span>
                    <span v-if="index === 0"
                        style="font-size: 11px; font-weight: 600; background: #dff3e6; color: #2f7d5b; padding: 2px 8px; border-radius: 20px">
                        Snowball target
                    </span>
                </div>
                <div style="font-size: 12.5px; color: #8a8172; margin-top: 2px">
                    {{ formatMoney(item.startBalance) }} remaining
                    <template v-if="item.totalInterest !== null">
                        · ₱{{ Math.round(item.totalInterest).toLocaleString('en-PH') }} in interest
                    </template>
                </div>
                <div style="margin-top: 8px; height: 5px; border-radius: 20px; background: #e0dacd; overflow: hidden">
                    <div style="height: 100%; border-radius: 20px; transition: width .4s"
                        :style="{ width: item.paidPct + '%', background: item.color }"></div>
                </div>
            </div>

            <!-- Payoff date -->
            <div style="text-align: right; flex: none">
                <div style="font-weight: 700; font-size: 14px; color: #211d17">{{ item.payoffDate }}</div>
                <div style="font-size: 12px; color: #8a8172">{{ item.payoffMonth }} mo</div>
            </div>
        </div>
    </div>
</div>
```

- [ ] **Step 4: Verify in the browser**

Open the Debt page. Confirm:
1. The "Payoff Plan" section appears below the cards.
2. Debts are sorted lowest balance first. The first row has the "Snowball target" badge.
3. Editing the monthly budget input updates the payoff dates in real time.
4. Setting the budget below the minimums total shows the warning.
5. If an interest rate was set on a debt, "· ₱X in interest" appears under its name.

- [ ] **Step 5: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all passing.

- [ ] **Step 6: Commit**

```bash
git add resources/js/utils/snowball.ts resources/js/pages/Debt.vue
git commit -m "feat: add snowball payoff plan section to Debt page"
```
