# Monthly Forecast Page — Design Spec

**Goal:** A new `/forecast` page showing a 12-period-ahead, spreadsheet-style grid of income, bills, set-asides, and debt payments, plus a running account-balance projection — answering "how much money will I actually have in N months." Modeled after a personal Google Sheet the user already maintains by hand, but computed from Habitat's existing recurring-finance data instead of typed in manually.

**Non-goals:** Editing projected figures inline (all rows are derived, not manually overridable). A cumulative "carried over" figure that mixes historical actuals with projections beyond what's described below. Modeling exact final-month partial debt payments (see Debt payoff rule below). Per-source income breakdown (no income-source model exists or is being added).

## Route & Nav

- `GET /forecast` → `ForecastController@index`, Inertia page `Forecast`.
- Sidebar: add "Forecast" to the Money section in `resources/js/components/AppSidebar.vue`, alongside Accounts / Money / Set-asides / Debt. Update `AppSidebarHeader.vue`'s Money-section `paths` and `pageLabels` to include `/forecast` → "Forecast", following the exact pattern used when `/money` was added.

## The 12-period window

Periods are the app's existing budget-cycle concept, not calendar months — a cycle may be a calendar month, a custom-start-day month, or a fixed N-day window, per `Setting::cycle_type`. Using `App\Services\BudgetCycle`:

```
$period = $budgetCycle->current();
$periods = [$period];
for ($i = 0; $i < 11; $i++) {
    $period = $budgetCycle->next($period);
    $periods[] = $period;
}
```

This produces exactly 12 `CyclePeriod`s starting with the in-progress current cycle. Each period's label for the column header comes from `CyclePeriod::label()` (already handles calendar-month vs. custom-range formatting).

## Computation rules

All monthly/per-period fields (`RecurringBill::amount`, `SinkingFund::per_month`, `Debt::per_month`) already represent one period's worth of that commitment. No day-of-month/due-date matching against period boundaries is needed — each item contributes its flat amount to every period in the window, subject to the debt cutoff rule below.

**Debt rows.** Reuse `TrajectoryReportService::payoffInputs()` + `simulatePayoff()` (already built for `/money`'s debt-free projection) to get each debt's `perDebtMonths` — the period index at which its balance reaches zero, assuming only its own `per_month` payment (no avalanche extra, matching how `/money` already calls this). For period index `>= perDebtMonths`, that debt's amount is ₱0. The period *before* the cutoff still shows the flat `per_month` figure rather than the smaller true final payment — a deliberate simplification since this is a planning view, not a ledger.

**Set-aside rows.** `SinkingFund::per_month` applies to every period in the window unchanged. No payoff/completion cutoff — these are treated as ongoing recurring commitments (matches how `/money` already sums them as an ongoing per-cycle figure).

**Bill rows.** `RecurringBill::amount` applies to every period unchanged. Grouped by the bill's category's existing `group` field (fallback label `"Other"` for bills with no category), mirroring the grouping already used on `settings/Categories`.

**Projected income row / projected everyday-expenses row.** Neither has a recurring-commitment model in the app today, so both are averages of the last 3 *completed* cycles (via `BudgetCycle::previous()`, walked back from the current cycle — the in-progress current cycle is never included in the average, so it can't understate itself). Fewer than 3 prior cycles with data → average over however many exist; zero prior cycles → ₱0.
- "Income" = sum of `type = income` transactions in a cycle (same definition `MoneyController` uses for `incomeReceived`).
- "Everyday expenses" = sum of `type = expense` transactions with `debt_id` null (same definition `MoneyController` uses for `everydayExpenses`) — excludes debt-linked expense transactions so it doesn't double-count against the Debt row.

**Current period override.** The first period (index 0, the in-progress cycle) uses live actuals instead of the projected/flat figures, for the income and everyday-expenses rows specifically: the real `incomeReceived`/`everydayExpenses` totals for the cycle so far, computed the same way `MoneyController` does today. Bill/set-aside/debt rows are not overridden for the current period — they already show the correct flat commitment figure regardless of whether it's been paid yet this cycle.

**Net leftover row.** Per period: `income − everydayExpenses − debtSubtotal − setAsidesSubtotal − billsSubtotal`.

**Running balance row.** Starting balance = `$user->accounts()->sum('balance')` (today's real total across all accounts, same figure the Accounts page totals). Then, for each period in order: `runningBalance[i] = (runningBalance[i-1] ?? startingBalance) + netLeftover[i]`. This is the headline "will I be OK" figure — colored red in the UI wherever it dips below zero.

## Backend

**New:** `App\Services\MonthlyForecastService` — owns the period-window construction and all computation above. Takes a `User` and returns the full data shape (below) as a plain array, so the controller stays thin.

**New:** `App\Http\Controllers\ForecastController::index()` — resolves the user's `BudgetCycle`, calls the service, renders Inertia page `Forecast` with the returned array spread as props.

**Reused, unchanged:** `TrajectoryReportService::payoffInputs()` / `simulatePayoff()`, `BudgetCycle`, `CyclePeriod`.

### Data shape (Inertia props)

```
startingBalance: number
periods: [{ key: string, label: string, isCurrent: boolean }, ...12]
incomeRow: { label: string, amounts: number[12] }
everydayExpensesRow: { label: string, amounts: number[12] }
expenseGroups: [
  { key: 'debt', label: 'Debt', items: [{ id, name, icon, color, amounts: number[12] }], subtotal: number[12] },
  { key: 'set_asides', label: 'Set-asides', items: [{ id, name, icon, color, amounts: number[12] }], subtotal: number[12] },
  { key: 'bills', label: 'Bills', subgroups: [{ groupName: string, items: [{ id, name, icon, color, amounts: number[12] }], subtotal: number[12] }] },
]
netLeftover: number[12]
runningBalance: number[12]
```

## Frontend

`resources/js/pages/Forecast.vue`: a horizontally-scrollable table, row-label column `sticky left-0` so it stays visible while scrolling through the 12 month columns.

- Header row: `period.label` per column; the `isCurrent` column gets a background tint.
- Income row (bold/tinted, like the sheet's Income header).
- Expense groups in order: Debt, Set-asides, Bills (bills rendered as nested subgroups by category group, each with its own subtotal row) — each item row shows icon + name (same visual pattern as `/money`'s bill rows).
- Everyday expenses row (no sub-items).
- Net leftover row — bold, red text when negative for that period.
- Running balance row — bold, most visually prominent row on the page, red when negative for that period.

Reuses existing design tokens only (`hab-card-bg`, `hab-hairline`, `Bricolage Grotesque` headings, `--hab-muted`/`--hab-ink` colors) — no new design system introduced.

## Testing

- `tests/Feature/ForecastTest.php`:
  - Guests redirected to login.
  - 12 periods returned, first period's `isCurrent` is true, labels match `CyclePeriod::label()` output.
  - A recurring bill appears with its flat amount in every period.
  - A debt's amount goes to ₱0 starting at its `perDebtMonths` cutoff (construct a debt that pays off in, e.g., period 3, assert periods 0–2 show `per_month` and periods 3–11 show 0).
  - Income/everyday-expenses rows average exactly 3 prior completed cycles' actuals when 3+ exist; average fewer when fewer exist; ₱0 when none exist.
  - Current period's income/everyday-expenses amounts equal live actuals for the in-progress cycle, not the average.
  - `netLeftover` reconciles: `income − everydayExpenses − debt − setAsides − bills` per period.
  - `runningBalance[0] == startingBalance + netLeftover[0]`; `runningBalance[i] == runningBalance[i-1] + netLeftover[i]` for i > 0; `startingBalance` equals the sum of the user's account balances.
- `tests/Unit/MonthlyForecastServiceTest.php` (if the controller test above doesn't already give direct enough coverage of period-window construction across all three `cycle_type` values — fixed-days, monthly-custom-start-day, and the no-`Setting` calendar-month fallback).

After implementation: `vendor/bin/pint --dirty --format agent`, then the targeted test filter, then manual verification in the browser (open `/forecast`, confirm the 12 columns render, current column is highlighted, a known debt zeroes out at the right column, running balance matches hand math for at least one period).
