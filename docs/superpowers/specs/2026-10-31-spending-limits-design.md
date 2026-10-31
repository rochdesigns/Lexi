# Spending Limits Design

## Why

Lexi tells you what is safe to spend each month but nothing caps how you spend it. A monthly limit per category of living expenses lets you see, before it happens, that groceries or transport is running hot — without changing how safe to spend is worked out. Terms are in `GLOSSARY.md`; the core trade-off is ADR 0001.

## Decisions

- **Living expenses** are all spending that is not a bill payment: expense transactions with no bill, in the month they were made. Card purchases count when made; paying the card is a debt payment and never counts. Transfers, adjustments and statement rows matched to a manual entry (the `counted` scope) are left out. This is exactly what "spent this month" already counts, so the limits' spending adds up to that figure.
- **One optional limit per expense category**: each built-in expense category (`TransactionCategory::expenseValues()`, including Other) and each of the user's custom categories. "Other" means `category = other` with no custom category. A category with no limit still shows its spending.
- **Monthly, repeating, no rollover.** A limit carries on month after month until changed. A change applies from the current month on; earlier months keep the limit they had. Unspent limit does not carry over.
- **Removing a limit** applies from the current month on too; earlier months keep theirs.
- **Watched, not reserved** (ADR 0001): safe to spend is unchanged. Going over only warns — nothing is blocked or has to be covered.
- **Status** of a category with a limit: *over* when spent > limit; *close* when spent ≥ 80% of the limit; otherwise *under*.
- **Pace** = limit × day of month ÷ days in month (whole centavos, rounded down). A category is *ahead of pace* when it is not over and spent > pace.
- **Over safe to spend note**: when the month's limits add up to more than safe to spend, the Plan shows a quiet note with the difference. It never blocks a save.
- **Deleting a custom category** deletes its limits; its spending falls back to Other (existing behaviour) and counts against Other's limit.

## Where it shows

- **Plan** — a new section D "Spending limits": every expense category with spent, its limit, a bar with a pace tick, status in words ("₱1,200 over", "ahead of pace"). Set, change or clear a limit inline. The over-safe-to-spend note sits at the top of the section.
- **Overview** — a compact line listing categories that are close or over, linking to the Plan.
- **Transactions** — in the new-entry card, when the chosen category has a limit and the entry is dated this month: what is left in that category this month, and what will be left after this entry.
- **MCP** — `get_money_overview` includes the month's limits read-only (category, limit, spent, left, pace, status). Setting limits through MCP is out of scope.

## Non-goals

- Per-week limits, rollover, covering overspend, envelopes.
- Viewing or editing limits for past or future months.
- Changing the safe-to-spend formula or anything that uses it.
- Limits on bills or debt payments.
