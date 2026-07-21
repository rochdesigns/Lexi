# Debt Snowball Payoff Plan

**Date:** 2026-07-22
**Status:** Approved

## Overview

Add a snowball payoff plan to the existing Debt page. Users enter a monthly debt budget; a frontend simulation shows each debt in payoff order (lowest balance first) with projected payoff dates and — when an interest rate is set — total interest paid.

No new routes or pages. Everything is added to the existing Debt page and Debt model.

---

## Data Model

### Migration

Add a nullable `interest_rate` column to the `debts` table:

```php
$table->decimal('interest_rate', 5, 2)->nullable(); // e.g. 19.99 for 19.99% APR
```

Nullable so existing debts remain valid without a rate, and the payoff plan still works — it just omits interest figures.

### Model (`App\Models\Debt`)

- Add `interest_rate` to `$fillable`
- Add `'interest_rate' => 'decimal:2'` to `$casts`

### Controller (`DebtController`)

- Add `interest_rate` to the index map: `'interest_rate' => $d->interest_rate ? (float) $d->interest_rate : null`
- Add validation rule: `'interest_rate' => ['nullable', 'numeric', 'min:0', 'max:100']`

### Vue type (`Debt.vue`)

Add to the `Debt` type:
```ts
interest_rate: number | null;
```

---

## Snowball Algorithm

Implemented as a Vue `computed` property in `Debt.vue`. Reactive to `props.debts` and a user-editable `monthlyBudget` ref.

### Inputs

| Input | Source |
|---|---|
| `debts` | `props.debts` filtered to `balance > 0` |
| `monthlyBudget` | Editable ref, default = sum of all `per_month` values |

### Guard

If `monthlyBudget < sum of all minimums`, return `null` (no plan rendered, warning shown instead).

### Simulation loop

```
workingDebts = debts sorted by balance ascending (snowball order)
month = 0
maxMonths = 600

while workingDebts has active debts AND month < maxMonths:
  month++

  for each active debt:
    if debt.interest_rate:
      debt.balance += debt.balance × (debt.interest_rate / 100 / 12)
      debt.totalInterest += debt.balance × (debt.interest_rate / 100 / 12)

  snowballTarget = first active debt (lowest balance — already sorted)
  leftover = monthlyBudget

  for each active debt:
    payment = min(debt.balance, debt.per_month)
    debt.balance -= payment
    leftover -= payment

    if debt.balance <= 0:
      record payoff at current month

  if snowballTarget still active AND leftover > 0:
    snowballTarget.balance = max(0, snowballTarget.balance - leftover)
    if snowballTarget.balance <= 0:
      record payoff at current month
```

### Output per debt

```ts
type PayoffResult = {
  debtId: number;
  payoffMonth: number;          // months from now
  payoffDate: string;           // formatted "Mon YYYY"
  totalInterest: number | null; // null if no interest_rate set
};
```

---

## UI

### Placement

A "Payoff Plan" section below the debt cards grid. Hidden when `debts.length === 0`.

### Budget input

```
Monthly debt budget  [ ₱ 12,000 ]
```

Pre-filled with `sum(debt.per_month)`. Editable. Shows a soft warning beneath it if the entered value is below the minimums sum: *"Increase your budget to at least ₱X to cover all minimum payments."*

### Plan list

Vertical ranked list in snowball order (rank 1 = paid off first). Each row:

| Element | Detail |
|---|---|
| Rank pill | "1", "2", "3" … |
| Icon tile + name | Debt icon and name |
| Current balance | Formatted currency |
| Payoff date | "Jan 2028" + "· N months" |
| Total interest | Only shown if `interest_rate` is set |
| Progress bar | Percentage of original balance paid off (existing `debtPct` logic) |
| "Snowball target" badge | Applied to rank 1 (the debt receiving extra payment this month) |

---

## Add/Edit Form Changes

Add an optional "Annual interest rate %" field after the "Per month" field in both the add and edit debt modals. Hint text: "Optional — used to calculate total interest paid."

---

## Error / Edge Cases

| Scenario | Behaviour |
|---|---|
| Budget < sum of minimums | Warning shown; plan hidden |
| Debt with `balance = 0` | Excluded from simulation |
| No interest rate set | Plan runs; interest column omitted |
| All debts paid | Section shows "All debts paid off" state |
| 600-month cap hit | Last debt shows "600+ months" |

---

## Testing

- Unit: snowball computed property logic (vitest or extracted utility function)
- Feature: `interest_rate` field persists via store/update endpoints
- Feature: validation rejects `interest_rate` outside 0–100
- Existing debt tests must remain passing
