# Money Reports Page

**Date:** 2026-08-04
**Status:** Approved

## Overview

Add a "Reports" page to the Money section: a financial overview with spending, budget, and cash-flow breakdowns for a selected calendar period (Month / Quarter / Year), plus forward-looking projections (trend vs. budget) and debt/goal payoff timelines.

New route, new controller, new page, one new nav item. New dependency: `chart.js` + `vue-chartjs` (approved — no existing charting library in the app; other pages use hand-rolled CSS bars).

---

## Routing & Navigation

```php
Route::get('/reports', [ReportController::class, 'index'])->name('reports');
```

Placed in the Money group in [routes/web.php](../../../routes/web.php), after the `/debts/{debt}/payment` route.

Add to the "Money" section in [AppSidebar.vue](../../../resources/js/components/AppSidebar.vue):

```ts
{ title: 'Reports', href: '/reports', icon: 'bar_chart' },
```

---

## Period Model

Calendar-based periods only (not the user's custom budget cycle — quarter/year have no cycle equivalent, and calendar periods are simpler for trend math).

### Query params

| Param | Values | Default |
|---|---|---|
| `period` | `month` \| `quarter` \| `year` | `month` |
| `date` | any date string (anchor) | today |

### Controller resolution (Carbon)

```php
$anchor = $request->query('date') ? Carbon::parse($request->query('date')) : Carbon::now();
$periodType = $request->query('period', 'month');

[$start, $end, $label] = match ($periodType) {
    'month'   => [$anchor->copy()->startOfMonth(), $anchor->copy()->endOfMonth(), $anchor->format('F Y')],
    'quarter' => [$anchor->copy()->startOfQuarter(), $anchor->copy()->endOfQuarter(), 'Q'.$anchor->quarter.' '.$anchor->year],
    'year'    => [$anchor->copy()->startOfYear(), $anchor->copy()->endOfYear(), (string) $anchor->year],
};
```

`monthsInPeriod` = 1 / 3 / 12 respectively (used to scale budget limits).

### Prev/Next navigation

- `prevDate` = anchor shifted back one unit (`subMonth`/`subQuarter`/`subYear`), formatted as the query param for the "prev" link.
- `nextDate` = anchor shifted forward one unit — but **capped at the current period**: if the shifted-forward period would start after "now", `nextDate` is `null` and the Next arrow is disabled. No browsing into the future; projections already cover the forward-looking part of the current period.

---

## Data & Calculations

All computed in one `ReportController@index` pass (same shape as `BudgetController@index` today — no separate endpoints per tab).

### Overview (always visible, independent of active tab)

| Field | Calculation |
|---|---|
| `income` | sum of `type=income` transactions in `[start, end]` |
| `expenses` | sum of `type=expense` transactions in `[start, end]` |
| `net` | `income - expenses` |
| `budgetTotal` | `sum(budget_categories.limit_amount) * monthsInPeriod` |
| `budgetUsedPct` | `expenses / budgetTotal` (0 if `budgetTotal` is 0) |
| `projectedTotal` | trend-extrapolated expense total for the full period: `expenses / elapsedDays * totalDaysInPeriod` (elapsed = `max(1, days from start to min(today, end))` to avoid divide-by-zero on day one; only shown when period is the current, in-progress one) |

### Spending tab

Expenses grouped by `budget_category_id` within `[start, end]`, sorted by amount descending:

```php
[
  'category' => ['id' => ..., 'name' => ..., 'icon' => ..., 'color' => ...],
  'amount' => float,
  'pct' => amount / expenses,
]
```

Uncategorized expenses (`budget_category_id === null`) grouped under an "Uncategorized" bucket.

### Budget vs Actual tab

For each budget category:

```php
[
  'category' => [...],
  'budgeted' => limit_amount * monthsInPeriod,
  'actual' => spent in [start, end],
  'status' => actual > budgeted ? 'over' : 'under',
]
```

### Cash Flow tab

Bucketed series for the chart, bucket size depends on period type:

| Period | Bucket |
|---|---|
| Month | Weekly (ISO week within the month) |
| Quarter | Monthly (3 buckets) |
| Year | Monthly (12 buckets) |

Each bucket: `{ label, income, expenses, net }`.

**Projection lines** (only rendered for the current, in-progress period, extending from "today" to the end of the period):

- **Trend line**: average daily expense rate so far (`expenses / elapsedDays`, same `elapsedDays` guard as `projectedTotal` above) applied to each remaining bucket.
- **Budget line**: each remaining bucket assumes the category-limit-implied budgeted amount is spent exactly as budgeted.

Both rendered as dashed/muted series on the same chart as the actuals, so the gap between "if this trend continues" and "if you stick to budget" is visible.

### Payoff tab

**Debts** — for each debt with `balance > 0`:

```php
$monthlyRate = $debt->interest_rate === null ? 0.0
    : ($debt->interest_rate_period === 'monthly'
        ? $debt->interest_rate / 100
        : $debt->interest_rate / 100 / 12);

$balance = (float) $debt->balance;
$months = 0;
while ($balance > 0 && $months < 600) {
    $balance += $balance * $monthlyRate;
    $payment = min($balance, (float) $debt->per_month);
    if ($payment <= $balance * $monthlyRate && $monthlyRate > 0) {
        $onTrack = false;
        break; // payment doesn't cover interest accrual — never pays off
    }
    $balance -= $payment;
    $months++;
}
```

This mirrors the monthly-rate derivation already used client-side in `Debt.vue` (line ~311), so figures stay consistent with the existing Debt page.

Output per debt: `{ name, icon, color, months, payoffDate, onTrack: bool }`. `onTrack = false` → render "Not on track" instead of a date (per_month too low relative to interest).

**Goals** — for each goal with `saved < target`:

```php
$months = $goal->per_month > 0
    ? (int) ceil(($goal->target - $goal->saved) / $goal->per_month)
    : null;
```

Output per goal: `{ name, icon, color, months, targetDate }`. `months === null` → render "Set a monthly amount to see an estimate".

Both lists rendered as a simple vertical timeline (name/icon, months remaining, projected date or status message) — no new chart needed here.

---

## UI (`Reports.vue`)

Single Inertia page, single controller round-trip per period/navigation change. Tabs switch client-side (no additional requests).

### Layout

1. **Header row**: Month / Quarter / Year pills (like the toggle-row style used elsewhere) + prev/next arrows + period label (e.g. "August 2026", "Q3 2026", "2026"). Next arrow disabled when `nextDate` is null.
2. **Overview stat cards** (always visible, independent of active tab): Income, Expenses, Net, Budget used %, and (when in the current period) the trend-projected total.
3. **Tab bar**: Spending / Budget vs Actual / Cash Flow / Payoff.
4. **Tab content**: renders the corresponding section from the data above.

### Charts

`vue-chartjs` `<Line>` for Cash Flow (actual + two projection series), `<Bar>` for Spending breakdown and Budget vs Actual. Colors pulled from the existing `--hab-*` CSS custom properties to match the app's theme (light/dark/other palette variants already defined in `app.css`).

### Empty states

- No transactions in period → "No transactions in this period yet" in place of Spending/Budget/Cash Flow content.
- No debts → "No debts to pay off" in Payoff tab (goals still shown if present, and vice versa).

---

## Error / Edge Cases

| Scenario | Behaviour |
|---|---|
| `budgetTotal = 0` (no categories) | `budgetUsedPct` shown as 0, no divide-by-zero |
| Viewing a past period | `projectedTotal` and cash-flow projection lines omitted (only meaningful for an in-progress period) |
| Attempting to navigate past the current period | Next arrow disabled server-side (`nextDate = null`) and client-side |
| Debt payment ≤ interest accrual | `onTrack = false`, "Not on track" shown, loop breaks instead of running to the 600-month cap |
| Goal `per_month = 0` | "Set a monthly amount to see an estimate" instead of a date |
| No debts / no goals | Empty state per sub-section, not a hidden tab |

---

## Testing

- Feature test (`ReportControllerTest`): for a user with factory-created transactions, budget categories, debts, and goals —
  - correct income/expenses/net/budgetUsedPct for `period=month`, `period=quarter`, `period=year`
  - prev/next navigation shifts the anchor correctly and next is disabled at the current period
  - spending breakdown groups and sorts correctly, including an "Uncategorized" bucket
  - budget vs actual scales `limit_amount` by `monthsInPeriod`
  - payoff calculation: normal payoff case, "not on track" case (payment ≤ interest), and goal with `per_month = 0`
- Existing tests must remain passing; no changes to other controllers/models.
