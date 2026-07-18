# Money Section — Phase 1 Design Spec
**Date:** 2026-07-19  
**Sprint:** 4 (Phase 1 of 5 for the Money section)  
**Status:** Approved

---

## Overview

This sprint delivers the **Accounts page** (full CRUD) plus **cycle setting scaffolding** that phase 2 (Budgets) depends on. The Money section has five pages total; this is the foundation sprint.

### Phase order (all Money pages)
| Phase | Page | Dependency |
|---|---|---|
| **1** | **Accounts** | None — builds the foundation |
| 2 | Budgets | Needs accounts + cycle settings |
| 3 | Set-asides | Independent |
| 4 | Debt | Independent |
| 5 | Goals | Independent |

---

## Section 1: Architecture & Routes

### New controller
`app/Http/Controllers/AccountController.php` — `index`, `store`, `update`, `destroy`

### New routes (added to `routes/web.php`)
```
GET    /accounts               accounts.index    → AccountController@index
POST   /accounts               accounts.store    → AccountController@store
PATCH  /accounts/{account}     accounts.update   → AccountController@update
DELETE /accounts/{account}     accounts.destroy  → AccountController@destroy
```

### New page component
`resources/js/pages/Accounts.vue`

### New migrations (2)
1. `add_budget_cycle_to_settings_table` — adds `budget_cycle_days` (int, default 30) + `budget_cycle_anchor` (date, nullable) to the `settings` table
2. `create_budget_cycles_table` — new table replacing `budget_months` for phase 2 (created now, used in phase 2)

### Existing `accounts` table — no schema changes needed
```
id, user_id (FK cascade), name, institution, last4, balance (decimal), type (cash|credit|investment), created_at, updated_at
```

---

## Section 2: Accounts Page UI

### Header row
- Left: `<h1>` "Accounts" (28px Bricolage 700) + subtitle "Everything you own and owe, in one place" (muted)
- Right: **Add account** button (dark style — `#211d17` bg / `#fdfbf5` text, `add` icon)

### Net worth hero (gradient, left column)
- Stat: **Total net worth** = `SUM(balance)` across all user accounts. Credit accounts store negative balances so they naturally subtract.
- Delta chip and sparkline bars: show **₱0 / flat** in phase 1 (no transaction history). Wired up in phase 2.
- Uses the same `heroGradient` CSS var pattern as Today page and other hero cards.

### Cash flow card (340px right column)
- Visible always, zero state until transactions exist.
- Rows: Money in ₱0 (green tile, `south_west` icon) / Money out ₱0 (amber tile, `north_east` icon) / Net saved ₱0 (accent color).
- Label: "Cash flow · {current cycle label}" — cycle label computed from `budget_cycle_anchor` + `budget_cycle_days` (e.g., "Jul 19 – Aug 17").

### Grouped accounts card
Three sections — **Cash**, **Credit**, **Investments** — each with:
- Section label (uppercase, muted) + subtotal (right-aligned)
- Account rows: icon tile (type-derived) + name + "institution •• last4" + balance (negative in red `#c0512c`) + chevron

**Type → icon mapping:**
| Type | Icon |
|---|---|
| cash | `savings` |
| credit | `credit_card` |
| investment | `candlestick_chart` |

Groups without accounts are hidden. If all accounts are empty, show a friendly empty state.

### Add / Edit modal (shadcn Dialog)
Fields:
- **Name** (required, text)
- **Institution** (optional, text)
- **Last 4 digits** (optional, max 4 chars, numeric)
- **Balance** (required, decimal — user enters positive for assets, negative for credit owed)
- **Type** (required — chip selector: Cash / Credit / Investment)

Edit pre-fills the same form. Modal title changes to "Edit account" vs "Add account".

### Edit / Delete controls
Each account row reveals an edit icon (pencil) and delete icon on hover — same `group/account` scoped Tailwind group pattern as task rows in `Tasks.vue`. Delete requires a two-step confirm: clicking the delete icon replaces it with a "Confirm?" button + cancel for 3 seconds, then auto-reverts. This prevents accidental deletion without a heavy modal.

---

## Section 3: Cycle Setting Scaffolding

### Settings table additions
Two new columns on the existing `settings` table:
- `budget_cycle_days` int, default `30`
- `budget_cycle_anchor` date, nullable

### Cycle anchor default
- Set to the user's registration date (`created_at`) when first accessed, if null.
- `CreateNewUser` action seeds `budget_cycle_anchor = today` and `budget_cycle_days = 30` for every new user.

### `budget_cycles` table (created now, used in phase 2)
```
id
user_id          FK → users, cascade delete
start_date       date
end_date         date
budget           decimal(10,2)
created_at
updated_at

UNIQUE (user_id, start_date)
```

### Cycle computation helper
A `cycleForDate(Carbon $date): array` static method on the `Setting` model (or a `BudgetCycleService` class):
- Input: user's `budget_cycle_anchor` + `budget_cycle_days` + a target date
- Output: `['start' => Carbon, 'end' => Carbon, 'index' => int]`
- Formula: `index = floor((date - anchor) / cycle_days)`, then `start = anchor + index * cycle_days`, `end = start + cycle_days - 1`

No Budgets UI in phase 1. The cycle length setting will be exposed in the Budgets page settings in phase 2.

---

## Section 4: Testing

### `tests/Feature/AccountTest.php` (new, Pest v4)
- Store account with valid data
- Store validates required fields (name, balance, type)
- Guest is redirected from accounts index
- Update account fields
- Update returns 403 for another user's account
- Delete account
- Delete returns 403 for another user's account
- Index returns accounts grouped by type
- Net worth is correct sum (negative credit balances subtract)

### `tests/Feature/BudgetCycleTest.php` (new, Pest v4)
- New user registration seeds `budget_cycle_days = 30` and `budget_cycle_anchor`
- `cycleForDate()` returns correct start/end for a date within the first cycle
- `cycleForDate()` returns correct start/end for a date in a later cycle
- `cycleForDate()` works for non-30-day intervals (e.g., 14 days)
- Cycle end = start + cycle_days - 1

---

## Key decisions

- **Negative balances for credit:** credit accounts store owed amount as a negative number — net worth sum naturally subtracts. No special-case logic needed.
- **Cash flow zero state:** shown immediately (not hidden) so the layout is stable from day 1.
- **`budget_months` kept:** the existing migration is left in place; `budget_cycles` is created alongside it. Phase 2 will seed cycles and can drop `budget_months` once fully migrated.
- **No icon field on accounts:** icon is derived from `type` on the frontend — avoids an extra column for a purely presentational concern.
- **Cycle anchor defaults:** nullable in DB, defaulted in app logic on first access — avoids a hard dependency on the user having explicitly set it.
