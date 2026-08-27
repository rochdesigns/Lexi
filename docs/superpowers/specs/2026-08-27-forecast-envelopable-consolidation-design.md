# Forecast reconciled to the Envelopable model

## Problem

`MonthlyForecastService` projects 12 cycle periods ahead, but it computes each
expense group with its own bespoke, near-duplicate logic: `debtGroup()`,
`setAsideGroup()`, and `billsGroup()` (`app/Services/MonthlyForecastService.php:153-246`)
each build an identical `{items: [...], subtotal: [...]}` shape by hand,
with the only real difference being the per-period amount formula for that
one type (debt payoff cutoff, sinking-fund due-date renewal, flat bill
amount). This is exactly the debt named in the envelope-budgeting-core spec's
"Out of scope" list (sub-project 4: "Forecast page — reconciled to project
this model forward instead of its current separate projection logic").

Meanwhile `Debt`, `SinkingFund`, and `RecurringBill` already implement
`Envelopable` (present-day, per-cycle assignment) but have no shared way to
answer "what do you cost N periods from now" — that logic lives entirely
inside the forecast service, not on the model, so it can't be unit-tested
per type and can't be reused anywhere else a projection is needed.

This is additive and internal: `MonthlyForecastService::build()`'s return
shape does not change, so `Forecast.vue` and `ForecastTest` need no changes
beyond what already covers today's behavior.

## Core concept: `Forecastable`

A new contract, separate from `Envelopable` (assignment and projection are
different concerns — an envelope's assigned amount for the current cycle
does not have to equal its projected cost 8 cycles from now):

```php
interface Forecastable
{
    /** Amount this item contributes at the given forecast period. */
    public function forecastAmount(CyclePeriod $period, int $periodIndex): float;
}
```

`Debt`, `SinkingFund`, and `RecurringBill` each implement it, moving their
existing per-period math off the service and onto the model:

- **`Debt::forecastAmount()`** — `per_month` until the period at or past its
  payoff-simulation cutoff, then `0.0`. The payoff simulation itself
  (`TrajectoryReportService::simulatePayoff()`) still runs once per forecast
  build (it's a batch calculation across all debts, not a per-debt query) —
  the cutoff index for a given debt is passed in, not recomputed per call.
- **`SinkingFund::forecastAmount()`** — `per_month` while `period->end` is
  before `due_date`; once a period reaches or passes `due_date`,
  `target / 12` (the amortized-over-a-year renewal rate), or `per_month`
  unchanged if `due_date` is null.
- **`RecurringBill::forecastAmount()`** — flat `amount` for every period
  (`$period`/`$periodIndex` unused, but present to satisfy the contract).

`BudgetCategory` does **not** implement `Forecastable`. Variable/everyday
spending isn't a discrete recurring item to project per-category — it stays
a single aggregate line (`nonBillAndDebtCategoryLimitTotal()`, unchanged).

## Service changes

`MonthlyForecastService::build()` keeps its current structure (income row,
everyday row, three expense groups, running balance) but the three private
group-builder methods collapse into one generic helper:

```php
/**
 * @param  Collection<int, Forecastable&Envelopable>  $items
 * @return array{items: list<array<string, mixed>>, subtotal: list<float>}
 */
private function buildGroup(Collection $items, array $periods): array
{
    $subtotal = array_fill(0, self::PERIOD_COUNT, 0.0);
    $result = [];

    foreach ($items as $item) {
        $amounts = [];
        for ($i = 0; $i < self::PERIOD_COUNT; $i++) {
            $amounts[$i] = round($item->forecastAmount($periods[$i], $i), 2);
            $subtotal[$i] += $amounts[$i];
        }

        $result[] = [
            'id' => $item->id, 'name' => $item->envelopeName(),
            'icon' => $item->envelopeIcon(), 'color' => $item->envelopeColor(),
            'amounts' => $amounts,
        ];
    }

    return ['items' => $result, 'subtotal' => array_map(fn ($v) => round($v, 2), $subtotal)];
}
```

- **Debt group**: `debtGroup()` shrinks to computing the payoff simulation
  and attaching each debt's cutoff period index before handing the
  collection to `buildGroup()` — `Debt::forecastAmount()` needs the cutoff,
  so it's set on the model instance (a non-persisted runtime property) right
  before the call, not looked up per-period.
- **Set-asides group**: `setAsideGroup()` becomes `buildGroup($user->sinkingFunds()->orderBy('position')->get(), $periods)` directly — no bespoke logic left, since `due_date`/`target` are already on the model.
- **Bills group**: bills still need their `budgetCategory->group` grouping
  (subgroups by category group name) — that grouping-into-subgroups wrapper
  stays, but each subgroup's items are now built via `buildGroup()` too.
- `nonBillAndDebtCategoryLimitTotal()` and the income-averaging logic are
  unchanged — they don't touch `Envelopable`/`Forecastable` items.

## Rollout

Additive, no migration:

1. Add `Forecastable` contract (`app/Contracts/Forecastable.php`).
2. Implement it on `Debt`, `SinkingFund`, `RecurringBill`.
3. Refactor `MonthlyForecastService` to use `buildGroup()` + per-model
   `forecastAmount()`, removing `debtGroup()`'s, `setAsideGroup()`'s, and
   `billsGroup()`'s duplicated per-period math (the methods stay as thin
   wrappers that assemble the right collection and call `buildGroup()`).
4. No route, controller, or frontend change — `Forecast.vue` consumes the
   same JSON shape as before.

## Out of scope

- Linking envelope *assignments* to forecast amounts (Part 2 of the design
  discussion: forecast always uses the item's own native rate — `per_month`,
  bill `amount` — never what's been manually assigned this cycle, since
  those answer different questions: "did I fund it" vs. "what does it cost
  going forward").
- Money ↔ Forecast cross-navigation (flagged as a UX gap, not part of this
  backend consolidation).
- `BudgetCategory` forecasting beyond the existing flat aggregate.

## Testing

- `tests/Unit/DebtTest.php`, `tests/Unit/SinkingFundTest.php`,
  `tests/Unit/RecurringBillTest.php` (new or extended): one test per model
  covering its `forecastAmount()` — flat rate, and the type-specific
  transition (debt payoff cutoff; sinking-fund due-date renewal).
- `tests/Feature/ForecastTest.php` (existing): reduced to verifying the
  generic assembly — grouping, subtotals, running balance — rather than
  re-asserting each type's per-period formula, since that's now covered at
  the model level.
- Run `php artisan test --compact --filter=Forecast` and
  `--filter=Debt` / `--filter=SinkingFund` / `--filter=RecurringBill` after.
