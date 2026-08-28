# Money Section Rebuild — Debt-Focus Brainstorm Handoff

> **Status (updated 2026-08-28):** No longer just a brainstorm — the shape below has a written spec with full schema, and the first slice of backend is built and merged to `main`. This doc is still a continuity doc, not an implementation plan: for anything not yet built, resume the design conversation or go straight to `superpowers:writing-plans`; don't execute prose against it directly. See "Progress since this handoff" below for what's real vs. still just decided-in-principle.

## Context

The user asked: if the money section of this app were built for public release, how should it work? They explicitly asked to disregard the existing money pages/code/memory for this conversation — this is a from-scratch design exploration, not a critique or continuation of what's already implemented (see `docs/superpowers/specs/2026-07-19-money-section-phase1-design.md`, `2026-08-01-credit-card-debt-account-integration-design.md`, `2026-07-22-debt-snowball-payoff-plan-design.md`, and the envelope budgeting work for what actually exists today).

Constraints established during the conversation:
- The app will be **free** — no revenue to absorb per-user costs like Plaid.
- The **money section's focus is debt clearing**, not general finance tracking.
- User is **okay with manual entry only for now** (no bank aggregator).

## Decisions reached (in order)

1. **No bank aggregator for now.** Plaid-style aggregators charge per connection and don't work for a free app with no revenue. If live sync is revisited later, SimpleFIN (flat low fee) or GoCardless/Nordigen (EU, free tier) were floated as cheaper alternatives to Plaid. For now: manual entry, with CSV import as a plausible later add-on.

2. **Credit cards are liability accounts.** `is_liability` flips the balance sign convention (purchases increase what's owed). A **payment to a card is a transfer**, not two independent transactions — one linked pair (debit source account, credit card account) — so reports don't double-count it as both an expense and income.

3. **Debt is a first-class domain model**, not just a flagged account. Fields: balance, APR, minimum payment, strategy priority. A payoff engine supports **avalanche** (highest APR first) and **snowball** (lowest balance first) projections. Projections **recompute live off actual balance/payments**, not a fixed amortization schedule set at plan creation.

4. **Revolving vs. installment debt.** Cards still being actively spent on are a distinct "revolving" debt subtype from fixed loans (student loans, car loans), since their balance moves both up (spend) and down (payment) instead of just declining. Spend and payment transactions are tracked separately so the UI can show something like "you paid $200 but spent $150, net progress $50" — visibility into whether active use is undermining the payoff plan.

5. **Budgeting is coupled to the debt engine, not generic.** Zero-based/envelope budgeting per period:
   - Debt minimums are auto-created as non-negotiable envelopes, pulled live from the `Debt` entity (source of truth).
   - Fixed bills funded next.
   - Remaining income allocated to user-defined spending envelopes.
   - Any income left unallocated is suggested (not auto-applied) as an extra payment toward the priority debt.

6. **Underspend sweep is a confirm-tap nudge, not silent auto-sweep.** At period-end, freed/underspent envelope money is offered up ("You freed $47 — send it to [Card X]?") rather than moved automatically, to preserve the user's sense of control over their buffer. This was an explicit tradeoff call: auto-sweep is more motivating but removes the cushion role leftover budget normally plays.

7. **Missed/partial payments trigger re-baselining, not guilt-driven catch-up.** User explicitly confirmed this. When a payment is missed or partial, the payoff projection quietly recalculates off the new actual balance rather than rolling the shortfall into next period's target. Missing the **real minimum** (real-world late fee / credit score risk) is treated as a distinct, more serious case from missing the app's **suggested extra** payment (which only slows payoff — no real-world penalty).

## Progress since this handoff (as of 2026-08-28)

The shape above got a full design pass and the first backend slice is built:

- **Spec written:** `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` turns decisions #1-7 above into prose plus a complete schema — `Account`, `Transaction`, `Debt`, `DebtSettings`, `Bill`/`BillPeriod`, `Envelope`/`EnvelopePeriod`. That resolves the "actual schema" item that used to be open here.
- **A standalone payoff engine and page already existed before this schema pass** (`App\Models\DebtFocus\Debt`/`Settings`, `App\Services\DebtFocus\PayoffEngine`, the `/payoff-plan` page — see `docs/superpowers/specs/2026-08-27-payoff-plan-frontend-design.md`) and predates decision #2's `Account`/`is_liability` model — today's `Debt` has no `linked_account_id`, it's a bare balance/rate/minimum record with no transaction history behind it.
- **The ledger half of the schema is now built too:** `App\Models\DebtFocus\Account` (asset/liability, `is_liability` flips the sign convention) and `App\Models\DebtFocus\Transaction` (income/expense/transfer, a transfer is one row with both legs), plus `App\Services\DebtFocus\LedgerService` (`recordIncome`/`recordExpense`/`recordTransfer`) — the single place that applies the sign-convention rule from decision #2, so a transfer into a liability account correctly decreases what's owed. Commits `41881bb..8e6b212` on `main`. No controller or route calls this yet — it's backend-only, same scoping the payoff engine used.
- **Not yet built:** `Debt.linked_account_id` (the field connecting today's standalone `Debt` to the new `Account`/`Transaction` ledger), `DebtSettings` as a shared strategy setting reused by budgeting (today's `Settings` model is payoff-plan-specific), and all of `Bill`/`Envelope`/budgeting.
- **Small UX fixes landed on the standalone `/payoff-plan` page** (unrelated to the ledger work above — cosmetic/data-entry fixes on the pre-existing `PayoffEngine`/`DebtFocus\Debt` page): the per-debt interest figure was relabeled "cumulative interest" (it was reading as a monthly amount and caused confusion), and a "Calculate" helper next to the minimum-payment field on revolving debts now suggests `interest + 1% of balance` so users aren't left entering a minimum below what covers accruing interest — this surfaced from a real case (Maya CC's stored minimum payment was $521.09/mo against ~$856/mo of accruing interest, causing several months of negative amortization in the simulation before other debts freed up cash). No schema change; both fixes live in `resources/js/pages/PayoffPlan.vue`.

## Open / not yet discussed

- **Wiring `Debt` to `Account`.** The spec calls for `Debt.linked_account_id` (nullable — set for revolving debts with a real linked account, null for installment loans with none) so decision #4's spend-vs-payment tracking falls out of the `Transaction` model for free. This is the natural next implementation step now that both halves (`Debt` and `Account`/`Transaction`) exist independently but aren't connected.
- How statement balance vs. current balance should factor into the revolving-debt UI (flagged as a follow-up, not yet answered).
- Net worth / reporting surfaces.
- How this reconciles with (or replaces) the existing money implementation already in the codebase — the user deliberately kept this conversation separate from that context, so reconciliation is an open question for next time. This is now a three-way reconciliation, not two: the pre-existing `/money`+`/debt` implementation, the standalone `/payoff-plan` (`DebtFocus\Debt`/`Settings`), and this new `DebtFocus` ledger — all still separate systems.
- UI/entry-flow specifics for manual transaction entry (quick-entry patterns were mentioned as a retention concern but not designed) — more concrete now that `LedgerService` exists as the thing a quick-entry UI would eventually call.

## Suggested next step

Wire `Debt.linked_account_id` next — it's a small, well-scoped addition to an already-designed schema (not a new brainstorm), so it can likely go straight to `superpowers:writing-plans` rather than another design pass. After that, Bill/Envelope budgeting is the next schema piece to implement. The other open items (statement balance, net worth/reporting, manual entry UX, three-way reconciliation) still need a design conversation before they're plannable.

The `/payoff-plan` UX fixes above are done and don't change this priority — they were opportunistic fixes to the existing page, not part of the ledger-wiring track. One follow-up worth a pass while in that page: audit other existing debts' `minimum_payment` values against the same interest-coverage check that caught Maya CC (revolving debts where the stored minimum doesn't cover `balance × monthly_rate` will silently show negative amortization in the simulation without any warning in the UI).
