# Forecast Page Design

**Date:** 2026-09-07
**Status:** Draft
**Origin:** Brainstorm, 2026-09-07. Builds on `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` and the `/budget` page from `docs/superpowers/specs/2026-08-28-bill-envelope-budgeting-design.md`.

## Framing

`DebtFocus` currently answers three questions across three pages. `/ledger` answers "what happened". `/budget` answers "does *this month* add up". `/payoff-plan` answers "when am I debt-free". Nothing answers **"do I clear each of the next twelve months, and where do I get tight"** — `/budget` cannot, because it only ever looks at one month, and `/payoff-plan` cannot, because it tracks debt balances rather than cash.

This spec adds a fourth page, `/forecast`, that projects a **cash runway**: a twelve-month, month-by-month view of money in, money out, and the running cash balance that results. It is deliberately narrow. It is not a what-if simulator, not a net-worth chart, and not a calendar of due dates — those were considered and set aside in the brainstorm.

Unlike the debt-focus spec, this design is written *with* the existing implementation in view: it consumes `BudgetService`, `PayoffEngine` and the `DebtFocus` models as they stand today, and follows the conventions the other three pages already established.

## Decisions made in this pass

- **Purpose: cash runway.** The page answers "do I clear each month, and when do I get tight". Other candidate framings (what-if sliders, a forward timeline of due dates, a progress-over-time chart) were explicitly rejected for this pass.
- **Expected income is a single flat number** stored on `debt_focus_settings`, typed by the user and editable inline on the page. A recurring-income-sources model, a rolling average, and per-month overrides were all considered and rejected as more machinery than a first pass needs. The cost is acknowledged below under *Known limitations*.
- **Horizon is twelve months at monthly granularity**, starting with the current month. Long enough to catch an annual bill and to see debt minimums free up; short enough to stay believable.
- **Debt outflow comes from `PayoffEngine`, never from `ForecastService`'s own arithmetic.** The engine stays the single source of projection truth, the same rule `POST /payoff-plan/debts/preview` follows, so the two pages cannot drift.
- **The forecast writes nothing.** No `BillPeriod` or `EnvelopePeriod` rows are created for future months.
- **Page shape: hero plus month rail**, matching the countdown-plus-rail vocabulary of `/payoff-plan` and the hero-plus-grid of `/budget`.

## What a month row contains

One row per month, `m = 0..11`, `m = 0` being the current calendar month.

| Field | Source |
| --- | --- |
| `income` | `settings.expected_monthly_income` |
| `bills` | Active bills where `Bill::landsIn($year, $month)`, valued by `BudgetService::expectedAmountFor()` |
| `envelopes` | Sum of `Envelope::default_target` over active envelopes |
| `debt` | `PayoffEngine` — see below |
| `net` | `income - bills - envelopes - debt` |
| `balance` | Previous month's `balance + net` |

The seed balance (the value `m = 0` builds on) is the sum of `balance` over the user's non-liability accounts as it stands **today** — the same figure `BudgetService::cashAvailable()` starts from, before its sealed-money subtraction. Sealed money is not subtracted here, because the forecast counts this month's outstanding bills and minimums as outflow in the `m = 0` row instead.

### Month 0 counts what is left of this month, not the whole of it

The opening balance is today's balance, which already reflects every payment made and every pay cheque received so far this month. Counting the *whole* month's income and bills on top of that would double-count both. So `m = 0` counts only what has yet to happen:

- `income` — expected income not yet received: `max(0, expected_monthly_income - BudgetService::incomeForPeriod())`. Pay that has already landed is in the opening balance; only the rest is still to come.
- `bills` — `BillPeriod` rows for this month with `paid_at === null`, at their `expected_amount`. Paid bills have already left the account.
- `envelopes` — remaining allowance per envelope: `max(0, target - BudgetService::spentForPeriod())`, summed.
- `debt` — this month's projected debt payment less what has already been paid toward debts: `max(0, payments[0] - BudgetService::debtPaymentsForPeriod()->sum())`.

Reading the existing `BillPeriod` and `EnvelopePeriod` rows also means `m = 0` honours amounts the user edited on `/budget`, so the first card and the Budget page agree. Where a period row does not exist for a bill or envelope (the month has not been opened yet), the definition's amount is used and nothing is spent or paid against it.

Months 1–11 have no period rows and must not be given any, so they are computed purely from the `Bill`, `Envelope` and `Debt` definitions at full monthly value.

### Debt outflow

`PayoffEngine::simulate()` gains a `payments` array — the total cash paid across all debts in each simulated month — returned alongside `trajectory` under the same `$withTrajectory` flag. The forecast reads `payments[m]` directly.

`ForecastService` *could* derive the same figure from the existing `perDebt[].payoffMonth` data by summing the minimums of debts not yet cleared and adding `extra_monthly`. It deliberately does not: that reimplements the engine's payment rules (a final month's payment is smaller than the minimum when the balance is smaller; a cleared debt's minimum rolls onto the next target) in a second place, which is exactly the drift the `/payoff-plan/debts/preview` endpoint was built to avoid.

The simulation is run once per page load with the user's saved `strategy`, `extra_monthly` and `priority_debt_id`, mirroring how `PayoffPlanController` already calls it. Where the debt-free month falls inside the horizon, `payments` is shorter than twelve entries; months past its end contribute zero debt outflow.

## The hero

Scanning forward for the first month with a negative running balance:

- **A month goes negative** → the tight month, its shortfall, and how far out it is. *"March gets tight — you're $180 short."*
- **No month goes negative** → the twelfth month's closing balance. *"You clear all twelve months, ending with $2,340."*
- **`expected_monthly_income` is null** → an empty state asking for the one number, prefilled with the average of the last three months' actual recorded income as a suggestion (or blank when there is no history). No rail is rendered until it is answered; every number below depends on it.

A negative running balance that later recovers still counts as tight — the hero reports the *first* breach, not the ending position.

## Schema

One nullable column, no new tables:

```
debt_focus_settings
  + expected_monthly_income  decimal(12,2), nullable, default null
```

Nullable rather than defaulted to zero: "not yet answered" and "I earn nothing" read differently on the page, the same distinction `cashAvailable()` already draws by returning `null` when no asset account exists.

## `ForecastService`

New `App\Services\DebtFocus\ForecastService`, one public method:

```php
/**
 * @return array{
 *     months: array<int, array{year: int, month: int, income: float, bills: float,
 *                              envelopes: float, debt: float, net: float, balance: float|null}>,
 *     openingBalance: float|null,
 *     tightMonth: array{year: int, month: int, shortfall: float}|null,
 *     closingBalance: float|null,
 * }
 */
public function project(User $user, int $months = 12): array
```

It owns the projection rules and nothing else: no persistence, no request handling. `expected_monthly_income` being null is the controller's concern, not the service's — the controller skips `project()` entirely and renders the empty state.

The service depends on `BudgetService` (for `expectedAmountFor()`, `incomeForPeriod()`, `periodBounds()`) and `PayoffEngine` (for `simulate()`), both constructor-injected.

## Controller & routes

```
GET   /forecast          ForecastController@index          forecast.index
PATCH /forecast/income   ForecastController@updateIncome   forecast.income.update
```

`index` renders `Inertia::render('Forecast', ...)` with the projection, the current income figure, and the three-month average used as the empty state's suggestion. `updateIncome` validates `expected_monthly_income` as `required|numeric|min:0`, writes it via `$request->user()->debtFocusSettings()->firstOrCreate([])`, and returns `back()` — the same shape as `PayoffPlanController::updateSettings()`.

A sidebar entry goes in the Money group of `resources/js/components/AppSidebar.vue`, after Budget.

## Frontend

`resources/js/pages/Forecast.vue`, page root carrying `.hab-garden` like its siblings.

- **Hero** — the tight-month or all-clear sentence, with the income figure shown beneath it as an inline editable control (a `useForm` PATCH to `forecast.income.update`, matching the inline-edit pattern already used on `/budget` and `/payoff-plan`).
- **Month rail** — twelve cards, each showing the month, money in, money out, and what is left over, with a negative balance visually flagged. Tapping a card expands it to the four-line breakdown (income, bills, envelopes, debt).
- **Empty state** — when income is unset, the hero is replaced by the prompt-and-suggestion described above; no rail.

Wayfinder generates the route functions; no hardcoded URLs.

## Edge cases

- **No debts.** `simulate()` already returns `months: 0` and an empty `payments`; debt outflow is zero throughout.
- **No asset accounts.** The opening balance has no answer, so `openingBalance`, every `balance` and `closingBalance` are `null` — an unanswered question rather than a zero, the same distinction `cashAvailable()` already draws. The rail still shows each month's `net`, and the hero falls back to the first month with a negative `net` ("March doesn't cover itself — you're $180 short that month"). Month 0 still counts only what is left of the month, which remains meaningful without a balance to apply it to.
- **Debt-free date beyond the horizon.** `payments` covers all twelve months; nothing special is needed.
- **An annual bill anchored outside the window.** `landsIn()` handles this — it simply never appears.
- **A bill marked paid outside the ledger.** Bills are marked paid manually and need no linked transaction, so a bill can be `paid_at` without any tracked account balance having moved. Month 0 drops it from outflow regardless. This is the right default — the common case is that the money did leave — but it means the forecast trusts the paid flag over the ledger.
- **A varying bill with no history.** `expectedAmountFor()` already falls back to the stated `expected_amount`.

## Testing

Feature tests (`tests/Feature/ForecastControllerTest.php`, Pest):

- The page renders with the expected prop shape.
- Null `expected_monthly_income` renders the empty state, with the three-month average as the suggestion.
- A month whose outflow exceeds inflow is identified as the tight month, with the right shortfall.
- The all-clear case reports the twelfth month's closing balance.
- A debt's minimum stops appearing in `debt` after its payoff month.
- A quarterly or annual bill appears only in its anchor months.
- **No `BillPeriod` or `EnvelopePeriod` rows exist for future months after loading the page** — a direct count assertion, guarding the read-only rule.
- Month 0 honours an edited `BillPeriod.expected_amount` over the bill definition.
- Month 0 excludes a bill already marked paid, subtracts income already received from the expected figure, and subtracts envelope spending already recorded — the double-counting guard.
- `updateIncome` persists the figure and rejects a negative one.
- The page is behind `auth` and scoped to the signed-in user's data.

Unit test (`tests/Unit/PayoffEngineTest.php`, extending the existing file): for a simulation that clears, `array_sum($payments)` equals the starting total balance plus the total interest across all debts (every dollar paid goes to principal or interest); `payments` is `null` when `$withTrajectory` is false; and it is empty when no debt has a positive balance.

## Known limitations

A single flat income figure means a known bonus, a lean month, or a mid-year raise cannot be expressed — the runway is only as good as that one number. This is the accepted cost of the cheapest input model. Per-month overrides are an additive change to the same page (a nullable override per forecast month) and are the obvious next step if the flat figure proves too blunt.

The projection also assumes envelope targets hold steady at `default_target` for a year, which will understate months where the user knows they will spend more. The same override mechanism would cover it.

## Open questions (not designed here)

- Whether `/forecast` should eventually absorb what-if controls (extra-payment slider, income scenarios), or whether that belongs on `/payoff-plan` where `extraCurve()` already lives.
- Whether the tight-month hero should offer an action — trim an envelope, defer a bill — or stay purely informational. This pass keeps it informational.

## Suggested next step

Invoke the writing-plans skill to turn this into a phased implementation plan.
