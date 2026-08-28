# Money Section — Debt-Focus Design

**Date:** 2026-08-27
**Status:** Draft
**Origin:** `docs/superpowers/plans/2026-08-27-money-section-debt-focus-handoff.md`

## Framing

This is a from-scratch design for what the money section should be if the app went public and stayed free, with debt clearing as its core differentiator — not a critique or extension of the money pages that exist in the codebase today. Per the handoff doc, reconciling this shape with the current implementation is explicitly deferred until the shape itself is settled; that reconciliation is a separate future step, not part of this document.

**Constraints:** the app is free (no revenue to absorb per-connection aggregator costs). Manual entry only for now — no Plaid/SimpleFIN/bank sync. The money section's job is helping someone get out of debt, not general finance tracking.

## Core entities

### Account

An account is either an **asset** or a **liability** — `is_liability: bool` is the flag that flips the balance sign convention everywhere balances are summed or displayed:

- Asset account (checking, savings, cash): balance is what you have. Income increases it, expense decreases it.
- Liability account (credit card): balance is what you owe. A purchase increases it, a payment decreases it. `is_liability` is the single source of truth for this — every place that sums balances, applies a transaction's effect, or displays a number branches on this one flag, not on a specific account-type string.

Fields: `id, name, institution, type (checking|savings|credit|cash|investment), is_liability, balance`. `type` is descriptive (for icons/grouping); `is_liability` is the behavioral flag, so a future account type never silently needs its own new branch somewhere in balance logic.

### Transaction

Three kinds: `income`, `expense`, `transfer`.

A **transfer** is always one record with both legs (`from_account_id`, `to_account_id`, `amount`) — never two independent rows. Applying a transfer decrements the `from` account and credits the `to` account according to *that account's own* sign convention (`is_liability` flip applies to either side). This is the mechanism a credit-card payment uses: `from_account_id` = the checking account, `to_account_id` = the card, and because the card `is_liability`, crediting it means *decreasing* what's owed. There is no special-cased "debt payment" transaction type — a debt payment is just a transfer where the destination happens to be a liability account linked to a `Debt`.

### Debt

A separate entity from `Account`, not just a flagged account, because it carries payoff-specific fields an account has no reason to have:

```
Debt
  balance          -- mirrors the linked liability account's balance when linked; otherwise its own field for a debt with no matching account (e.g. a personal loan tracked as a number, not a bank connection)
  monthly_rate     -- nullable; a monthly rate, not an APR (see naming note below)
  minimum_payment
  debt_type        -- 'revolving' | 'installment'
  due_day          -- nullable; day of month the payment is due
  linked_account_id -- nullable FK to Account; set for revolving (a card still being spent on), null for installment (fixed loans)
```

**Naming note:** this field is `monthly_rate`, not `apr`, and `due_day` is included from the start — both correct decisions surfaced while building the standalone `DebtFocus` payoff engine (see [2026-08-27-payoff-plan-frontend-design.md](2026-08-27-payoff-plan-frontend-design.md)): the engine needs a rate it can apply directly each month, and storing it as an APR meant an error-prone `/12` conversion at read time. This design adopts that shape rather than reintroducing the APR framing.

`debt_type` is explicit, not inferred from whether an account is linked — a card that's been cut up and is only being paid down is `installment`-like in behavior (balance only ever goes down) even though it still has a linked account; the field says which behavior applies, not just which data happens to be present.

For `revolving` debts, **spend and payment are tracked as separate figures**, not netted into one balance delta: each period surfaces "paid $X, spent $Y this period → net $Z toward payoff" — because the point isn't just the balance, it's whether the user's active use of the card is undermining their own payoff plan. `installment` debts have no "spent" figure; the balance only moves via scheduled/extra payments. This falls out of the `Transaction` model above for free: "spent" is the sum of `expense` transactions on the debt's `linked_account_id` this period, "paid" is the sum of `transfer` transactions crediting it — no separate tracking field needed.

### Debt settings

One row per user, holding the payoff-wide choices that apply across all debts:

```
DebtSettings
  strategy        -- 'avalanche' | 'snowball', default 'avalanche'
  extra_monthly   -- decimal, default 0; the user's chosen extra payment beyond minimums
```

## Payoff engine

One engine, not per-debt logic scattered across pages. Given the full set of a user's active debts, a chosen **strategy** (`avalanche` — highest monthly rate first, or `snowball` — lowest balance first), and a monthly debt budget, it produces:

- an order in which debts get paid off
- for each debt: projected payoff date, months remaining, total interest (when a rate is known)
- which debt is currently the "priority" target for any extra payment this period

**Projections are always computed live against actual current balances and actual payments made** — never a fixed amortization schedule locked in at plan creation. Every time the user opens the money section, the numbers reflect where they actually are, not where a schedule from three months ago predicted they'd be. This is the mechanism that makes re-baselining (see below) free: there's no separate "catch-up" state to maintain, because the projection was never counting on anything except the current balance in the first place.

Strategy is a single user-level choice (not per-debt) — it determines the *order* debts are attacked in, applied consistently across the whole payoff plan.

## Budgeting: debt-first, zero-based

Every dollar of income gets assigned somewhere, in this order, once per budgeting period:

1. **Debt minimums** — auto-created, non-negotiable envelopes, one per active debt, pulled live from the `Debt` entity (its current `minimum_payment`, not a stored copy). The user cannot budget less than this for a debt; it isn't a suggestion. **This tier has no table of its own** — it's a query over active `Debt` records at render time, not a materialized row. A debt that gets paid off simply stops appearing next period; there's nothing to clean up, consistent with the payoff engine's live-recompute philosophy.
2. **Fixed bills** — recurring, known-amount obligations:
   ```
   Bill                          BillPeriod
     name                          bill_id
     expected_amount               year, month
     due_day                       actual_amount   -- can differ from expected (a variable bill like electricity)
                                    paid_at
   ```
3. **User-defined spending envelopes** — everything discretionary, user sets the amounts:
   ```
   Envelope                      EnvelopePeriod
     name                          envelope_id
     icon                          year, month
     default_target                target          -- can override the template amount for one period
                                    sweep_offered_at -- set when the end-of-period nudge is shown
                                    sweep_accepted   -- null until answered; true/false once the user responds
   ```
   Both `Bill` and `Envelope` split into a recurring template plus a per-period row — a period's target or expected amount can differ from the template without changing history, and `EnvelopePeriod` gives the underspend sweep (below) a place to record whether it was offered and answered.

Any income left unassigned after all three is **suggested, not auto-applied**, as an extra payment toward whichever debt the payoff engine currently names as priority. The user taps to confirm before it moves anywhere.

### Underspend sweep

At period end, envelopes that came in under budget have money sitting unused. Rather than silently sweeping it toward debt automatically, the app surfaces a confirm-tap nudge: *"You freed $47 in Groceries — send it to [priority debt]?"* Declining just lets it roll over in its own envelope as normal leftover buffer.

This was a deliberate tradeoff, not an oversight: automatic sweeping would accelerate payoff faster on average, but it also removes the cushion that leftover budget money normally provides — the choice here favors the user's felt sense of control over their own buffer, at some cost to average payoff speed.

## Missed and partial payments

When a payment is missed or partial, the payoff projection simply recomputes off the new actual balance next time it's viewed — no shortfall gets rolled forward into a bigger "catch-up" target for the next period, and no guilt-driven "you're behind" framing. This falls directly out of the payoff engine always computing live off current state (see above); there's no separate re-baselining step to build, only the discipline of never storing a fixed target that could go stale.

There is, however, a real severity distinction the UI should make:

- **Missing the actual minimum payment** has real-world consequences — late fees, credit score impact — and should be flagged as the serious case.
- **Missing only the app's suggested extra payment** (the leftover-income suggestion, or a declined underspend-sweep nudge) has no real-world penalty; it just slows the payoff plan slightly. This should read as low-key, informational — never framed with the same urgency as a missed minimum.

Detecting a missed minimum needs no dedicated table either: it's a live comparison between `Debt.minimum_payment` and the sum of `transfer` transactions crediting that debt's `linked_account_id` during the period. A debt with no `linked_account_id` (an installment loan with no bank connection) can't be checked this way automatically — the user's own manual balance update is the only signal available, so this severity distinction only applies to debts with a linked account.

## Open questions (not designed here)

These were flagged during the brainstorm and remain genuinely undecided — picking them up is the natural next step once this shape itself is confirmed:

- **Statement balance vs. current balance** for revolving debts — which one drives the minimum-payment envelope and the payoff projection, given they can differ meaningfully around a statement cut date.
- **Net worth / broader reporting** — whether and how asset totals, liability totals, and net worth get surfaced given the debt-focus framing (this section is explicitly not general finance tracking).
- **Manual entry UX** — quick-entry patterns for logging transactions were raised as a retention concern (manual entry only survives if entry is fast) but no specific flow was designed.
- **Reconciliation with the existing implementation** — deliberately still deferred, per the handoff doc. Once this design is confirmed, that becomes its own pass: comparing this shape against what's actually built and deciding what's additive, what's a rewrite, and what's a straight replacement.

## Suggested next step

Continue the brainstorm on the open questions above, or — once the shape here feels settled — treat this document as the input to the reconciliation pass and to `superpowers:writing-plans` for whichever piece gets implemented first.
