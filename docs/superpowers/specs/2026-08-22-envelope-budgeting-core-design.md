# Envelope budgeting core model

## Problem

Habitat's budgeting today has no "assign every dollar" concept. `BudgetCategory.limit_amount` is a single flat cap that applies identically to every cycle forever — there's no way to assign a different amount for a specific cycle, no rollover of unspent/overspent amounts between cycles, and no "Ready to Assign" figure (money you have but haven't committed anywhere yet). Debt, RecurringBill, and SinkingFund each have their own per-cycle commitment (`per_month`, `amount`, `per_month`) but none of the four participate in a shared assignment system, so there's no single place that answers "is every dollar of my money accounted for?" — which is the core of how YNAB budgets and what the new `/cashflow` page (a later sub-project) needs to be built on.

This is sub-project 1 of a larger money-architecture rework (see the four follow-on sub-projects in "Out of scope" below). It covers the backend model only — no page changes. It's additive: no existing column is removed or repurposed, so Money, Forecast, Set-asides, Debt, and Bills keep working unmodified until they're each reconciled to this model in their own later sub-project.

## Core concept: the Envelope

Every dollar of income gets assigned to an "envelope" — something the user is choosing to spend or save money on. Habitat already has four things that fit this role, and each keeps its own specialized model and logic untouched:

- `BudgetCategory` — everyday spending categories
- `Debt` — payoff simulation, interest rate, minimum payment
- `RecurringBill` — fixed recurring payments
- `SinkingFund` — savings goals with optional due dates

Each implements a new `Envelopable` interface so the assignment system can treat all four uniformly without merging their schemas:

```php
interface Envelopable
{
    public function envelopeName(): string;
    public function envelopeIcon(): string;
    public function envelopeColor(): string;
    public function defaultAssignedAmount(): float; // limit_amount / per_month / amount / per_month
}
```

## Data model

### New table: `envelope_assignments`

One row per envelope per cycle, created only when a user explicitly sets an amount for that cycle:

| column | type | notes |
|---|---|---|
| `id` | | |
| `user_id` | FK | |
| `envelope_type` | string | morph type: `budget_category`, `debt`, `recurring_bill`, `sinking_fund` |
| `envelope_id` | FK (polymorphic) | |
| `cycle_start` | date | the `CyclePeriod->start` this assignment belongs to |
| `assigned` | decimal(10,2) | |
| `created_at` / `updated_at` | | |

Unique index on (`user_id`, `envelope_type`, `envelope_id`, `cycle_start`).

When a cycle has no explicit row for an envelope yet, its assigned amount for that cycle defaults to `defaultAssignedAmount()` — the envelope's existing field. Users only create a row when they deviate from the default for a specific cycle (e.g., assigning less to "Groceries" this month, or a bill that's temporarily higher).

### Activity attribution

Which transactions count as an envelope's spending this cycle, in priority order (reuses existing FKs, no new columns on `transactions`):

1. `debt_id` set → attributes to that `Debt` envelope
2. else `sinking_fund_id` set → attributes to that `SinkingFund` envelope
3. else `budget_category_id` set, and that category is linked from a `RecurringBill.budget_category_id` → attributes to that `RecurringBill` envelope
4. else `budget_category_id` set → attributes to that `BudgetCategory` envelope

A `BudgetCategory` linked from a `Debt` or `RecurringBill` (existing `budget_category_id` FK on those two models) is not a separate assignable envelope — same exclusion `MonthlyForecastService::nonBillAndDebtCategoryLimitTotal()` already applies. `EnvelopeService` (new) exposes the list of "assignable" envelopes for a user (all four types, minus categories claimed by a debt/bill), used everywhere envelopes need to be enumerated.

### Available & rollover

For envelope `E` at cycle `N`:

```
available(E, N) = available(E, N-1) + assigned(E, N) - activity(E, N)
```

Carried exactly, positive or negative — no floor, no special-casing. An overspent envelope just shows a negative `available` until the user assigns more to cover it, same as YNAB.

`available(E, N-1)` for the first cycle after cutover is defined as the seeded value from migration (see Rollout) rather than computed recursively past that point.

### Ready to Assign

```
readyToAssign(as of today) = sum(on-budget account balances) - sum(all envelope_assignments.assigned, all envelopes, all cycles up to and including current)
```

On-budget accounts: `type IN ('cash', 'credit')`. `investment` accounts are excluded — tracked for net worth elsewhere, not spendable budget money.

This is a live, continuously-computed figure, not reset per cycle. Because it's balance-based, overspending in any envelope reduces the on-budget account balance immediately without a matching increase in lifetime-assigned, so Ready to Assign drops in real time the moment the overspend transaction is recorded — no separate "carry the overspend to next cycle" logic is needed; it falls directly out of the formula.

## New service classes

- `EnvelopeService` — enumerates a user's assignable envelopes (the four types, minus claimed categories).
- `EnvelopeAvailabilityService` — computes `available(E, N)` for a given envelope and cycle, walking forward from the seeded cutover row (or the earliest assignment row if before cutover doesn't apply).
- `ReadyToAssignService` — computes the live Ready to Assign figure.

These are the only new backend surface. No controllers or routes yet — this sub-project has no UI.

## Rollout

Additive migration only:

1. Create `envelope_assignments` table.
2. Seed one row per existing envelope (every `BudgetCategory` not claimed by a debt/bill, every `Debt`, every `RecurringBill`, every `SinkingFund`) for the *current* cycle at deploy time, using `defaultAssignedAmount()`.
3. Rollover starts fresh from that seeded cycle — no retroactive computation for cycles before deployment. `available(E, cutover_cycle) = assigned(E, cutover_cycle) - activity(E, cutover_cycle)`, with no prior rollover-in.

No existing column (`limit_amount`, `per_month`, `amount`) is removed. They remain each envelope's default template, editable from their existing Settings/edit forms exactly as today.

## Out of scope (later sub-projects, each its own spec)

2. **Cashflow page** — the YNAB-style budget UI built on this model (assign/edit per-cycle amounts, view Available, view Ready to Assign).
3. **Money page** — reconciled to source its dashboard figures from this model.
4. **Forecast page** — reconciled to project this model forward instead of its current separate projection logic.
5. **Set-asides / Debt pages** — folded into this model as specialized views, or left as-is if they end up being filtered views of it.

## Testing

- `tests/Feature/EnvelopeAssignmentTest.php` (new): default-amount fallback when no row exists for a cycle; explicit row overrides the default; uniqueness constraint per (user, envelope, cycle).
- `tests/Unit/EnvelopeAvailabilityServiceTest.php` (new): rollover carries positive and negative across multiple cycles; first cycle after cutover has no rollover-in.
- `tests/Unit/ReadyToAssignServiceTest.php` (new): on-budget sum excludes `investment` accounts; overspending in one envelope reduces the figure immediately; assigning more to an envelope reduces the figure immediately.
- `tests/Feature/EnvelopeServiceTest.php` (new): a `BudgetCategory` linked from a `Debt` or `RecurringBill` is excluded from the assignable-envelopes list.
- Run `php artisan test --compact --filter=Envelope` and `--filter=ReadyToAssign` after.
