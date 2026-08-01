# Credit Card — Debt & Account Integration

**Date:** 2026-08-01
**Status:** Approved

## Problem

Credit cards (e.g., HSBC CC, Maya) appear in two places:
- **Accounts page** as `type: credit` account records
- **Debt page** as separate debt records

They are not linked. Paying on the Debt page does not touch the credit account. Spending on the credit card does not update the debt balance. The user must manually keep both in sync.

## Goal

A single source of truth per credit card. Spending on the card automatically updates the outstanding balance on both pages. Paying on the Debt page decrements both the source bank account and the credit card account. The monthly minimum payment auto-computes from the outstanding balance.

---

## Mental Model

**Credit card account balance = outstanding balance (what you owe right now).**
- ₱0 = nothing owed
- ₱15,000 = ₱15,000 owed to the bank

This is a liability, not an asset. Credit accounts are excluded from `accountTotal` on the Accounts page and their balances flow through the linked debt record into `debtTotal`. Net worth calculation is unaffected.

---

## Data Model Changes

### Migration 1 — `debts` table

```
debts.account_id          — nullable FK → accounts.id
                             When set, this debt is "linked" to a credit card account.
                             The account balance is the canonical balance for this debt.

debts.minimum_payment_rate — nullable decimal(5,2)
                             Percentage of outstanding balance due each month.
                             Example: 3.00 = 3%.
                             Only meaningful when account_id is set.
```

No changes to the `accounts` table schema.

### Existing field repurposing (no schema change)

- `debts.original` — repurposed as **credit limit** for CC-linked debts (for context, not payoff math)
- `debts.per_month` — becomes **computed** (read-only) when `account_id` + `minimum_payment_rate` are set: `balance × rate / 100`. Stored in DB after each balance update.

---

## Sync Rules

The account balance is always the canonical value. The debt balance mirrors it.

| Event | Account balance | Debt balance | Debt per_month |
|---|---|---|---|
| CC expense logged | += amount | sync from account | recompute |
| Debt payment recorded | CC account -= payment, source account -= payment | sync from account | recompute |
| CC account balance edited directly | (user sets value) | sync from account | recompute |

Sync is triggered server-side in all three paths. The frontend never computes these — it reads updated values from the server response.

---

## Three Core Flows

### Flow A — Spending on the card

Transaction entry UI: from the Accounts page, clicking a credit card account opens its transaction history. An "Add expense" button in that panel allows logging a spend against the CC. (If this panel doesn't yet have an add-expense action, it will be added as part of this feature.)

1. Expense transaction created with the credit card as `account_id`
2. CC account `balance` += transaction amount
3. Linked debt `balance` updated to match CC account balance
4. Linked debt `per_month` recomputed: `balance × minimum_payment_rate / 100`

### Flow B — Paying on the Debt page ("Record payment")

1. User picks source account (e.g., BPI Savings) and enters payment amount
2. Expense transaction recorded on the source account
3. Source account `balance` -= payment
4. CC account `balance` -= payment
5. Debt balance syncs from CC account; `per_month` recomputes

**Validation:** Payment cannot exceed the source account balance (existing rule). Paying more than the CC outstanding balance is allowed (bank owes you a credit), but warn the user if they're about to overpay — balance would go negative.

### Flow C — Manual balance correction

When a user edits a credit account's balance directly (e.g., after a bank statement):
1. Account balance is updated to the entered value
2. Linked debt balance syncs automatically
3. Linked debt `per_month` recomputes

---

## Net Worth (Accounts Page)

```php
// Before
$accountTotal = $user->accounts()->sum('balance');   // included credit accounts

// After
$accountTotal = $user->accounts()
    ->where('type', '!=', 'credit')
    ->sum('balance');

// debtTotal already sums debts (which include linked CC debts) — unchanged
$netWorth = $accountTotal - $debtTotal;
```

The Accounts page Credit group still displays credit cards (for visibility), but their balances are labelled "owed" and excluded from the asset total.

---

## UI Changes

### Debt form

- **New field:** "Linked credit account" — dropdown showing only `type: credit` accounts. Optional. Can be left unset for installment loans.
- **New field:** "Minimum payment rate (%)" — shown only when a credit account is linked. Defaults to `3`.
- **`original` label change:** "Credit limit" (when credit account is linked)
- **`per_month` field:** Read-only and auto-computed when linked + rate is set. Shown as "~₱X/mo (est. minimum)". Editable only for unlinked debts.

### Debt card (list view)

- Linked CC debts show a small "Credit card" chip (distinct from installment loan cards)
- `per_month` displays as "~₱X/mo min" with a tilde to signal it's estimated
- Balance reflects live CC account outstanding balance

### Accounts page (Credit group)

- Credit account balances labelled with "owed" context
- Subtotal row for the Credit group labelled "Total owed" (not "Balance")
- These values are NOT included in the asset total shown at the top

---

## Migration of Existing Data

Existing HSBC CC and Maya records in both `accounts` and `debts` cannot be safely auto-matched by name. After this ships:
1. The user opens each debt on the Debt page and picks the matching credit account from the new "Linked credit account" dropdown
2. Sets the minimum payment rate
3. The debt balance immediately syncs to the account balance

No data is deleted automatically. Unlinking a debt (clearing `account_id`) leaves both records independent again.

---

## Out of Scope

- Auto-importing CC transactions from the bank (manual entry only)
- Statement cycle tracking (due date already exists via `due_day`)
- Rewards/cashback tracking
- Installment loan debts — no changes, they remain fully manual with fixed `per_month`
