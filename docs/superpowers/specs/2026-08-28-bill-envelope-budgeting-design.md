# Bill/Envelope Budgeting Design

**Date:** 2026-08-28
**Status:** Draft
**Origin:** `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` ("Budgeting: debt-first, zero-based" section), via `docs/superpowers/plans/2026-08-27-money-section-debt-focus-handoff.md`

## Framing

The debt-focus design spec (2026-08-27) already settled the *shape* of budgeting: every dollar of income gets assigned once per period, in order — debt minimums (non-negotiable, derived live from `Debt`), then fixed bills, then user-defined spending envelopes — with any leftover suggested (not auto-applied) toward the payoff engine's priority debt, and an underspend sweep offered as a confirm-tap nudge rather than applied automatically. This document turns that shape into an implementable design: the period lifecycle, the schema, the controller/routes, and the UI, following the conventions already established by the `/ledger` and `/payoff-plan` build-out (`App\Models\DebtFocus\*`, a `*Service` owning the one place a cross-cutting rule applies, ownership-scoped validation, inline `useForm` CRUD rows on the Vue pages).

This is additive to the existing `DebtFocus` schema (`Account`, `Transaction`, `Debt`, `DebtSettings`) — nothing here changes those tables except one new nullable column on `Transaction`.

## Decisions carried forward from the debt-focus spec (not re-litigated here)

- Budgeting period order: debt minimums → fixed bills → spending envelopes → leftover suggested toward priority debt.
- Debt minimums have no table of their own — a live query over active `Debt` rows.
- Underspend sweep is a confirm-tap nudge, never silent/automatic.
- `Bill`/`Envelope` split into a recurring template plus a per-period row, so a period's amount/target can differ from the template without rewriting history.

## New decisions made in this pass

- **Budgeting period = calendar month.** No per-user configurable cycle start day; matches the window already used by the `/payoff-plan` spend-vs-payment feature.
- **Period rows are created lazily**, on first view of a month with no rows yet — no scheduler, consistent with the payoff engine's "nothing precomputed" philosophy.
- **Envelope actuals are derived from tagged transactions**, not tracked separately — `Transaction` gains a nullable `envelope_id`; an envelope's "spent so far" is the live sum of its tagged transactions in the period.
- **Bills are marked paid manually** — no link to a `Transaction`. Bills are often paid outside this app's own ledger (e.g. bank autopay), so forcing a transaction link would misrepresent how people actually pay them.
- **Period income is the live sum of `income`-type transactions** in the period, across all asset accounts — not a separately-entered "expected income" figure, to avoid a second source of truth that can drift from the ledger.
- **New standalone `/budget` page**, sidebar entry under Money, same pattern as `/ledger` and `/payoff-plan` being separate single-purpose pages rather than tabs on an existing one.
- **Sweep nudges are detected when the *next* month's period is first created** — not via a background job. Creating a new month's rows also checks the prior month for underspent, not-yet-offered envelopes and surfaces them then.
- **The user picks the source account** for a sweep or extra-payment transfer at confirm time — the app cannot know which account actually holds the freed cash.
- **If the payoff engine's priority debt has no `linked_account_id`,** sweep/extra-payment suggestions fall back to the next debt in strategy order that does have one. If none are linked, no suggestion is shown.

## Schema

```
Bill                              BillPeriod
  id, user_id                       id, bill_id
  name                               year, month
  expected_amount                   expected_amount  -- snapshot at creation, template edits don't rewrite history
  due_day                           actual_amount     -- nullable, filled in when marked paid
  active (bool, default true)       paid_at           -- nullable

Envelope                          EnvelopePeriod
  id, user_id                       id, envelope_id
  name                               year, month
  icon                              target            -- snapshot at creation
  default_target                    sweep_offered_at  -- nullable
  active (bool, default true)       sweep_accepted    -- nullable until answered
```

`active` (new versus the original spec sketch) is needed so a discontinued bill/envelope stops getting a period row created next month — mirroring how a paid-off debt simply stops appearing in the minimums tier — without deleting its history.

`Transaction` gains one nullable `envelope_id` (FK, `nullOnDelete` — same pattern as the account transfer legs), meaningful only for `expense`-type rows.

## Period lifecycle: `BudgetService`

`App\Services\DebtFocus\BudgetService` owns period creation and sweep detection, the way `LedgerService` owns transaction effects:

- `periodFor(User $user, int $year, int $month): array{bills: BillPeriod[], envelopes: EnvelopePeriod[]}` — for each active `Bill`/`Envelope` belonging to the user, finds or lazily creates that month's period row, snapshotting `expected_amount`/`default_target` into the new row at creation time. Inactive templates are skipped.
- Called whenever `/budget` is viewed for a given month. First view of a month creates that month's rows; there is no separate provisioning step.
- **Sweep detection** piggybacks on this same call: when rows are being created for a month that didn't have any yet (i.e. a genuinely new month is being opened for the first time), the service first checks the *prior* month's `EnvelopePeriod` rows for any where `target > actual spend` and `sweep_offered_at` is still null. Those get `sweep_offered_at` stamped now and are returned alongside the current period so the page can render the nudge. Re-visiting the same month later does not re-run detection, so an already-offered-but-unanswered sweep is not offered a second time.
- Debt minimums are **not** part of this service — per the existing spec, they're a live query over active `Debt` rows, done directly in the controller.

## Controller & routes

`App\Http\Controllers\BudgetController`, following `LedgerController`/`PayoffPlanController` conventions — every foreign id (`bill_id`, `envelope_id`, `from_account_id`) validated with the same ownership-scoped `Rule::exists('table', 'id')->where('user_id', $userId)` pattern already used by `storeTransaction`/`linkDebt`.

```
GET    /budget                                  index          -- ?year=&month=, defaults to current month
POST   /budget/bills                            storeBill
PATCH  /budget/bills/{bill}                     updateBill
DELETE /budget/bills/{bill}                     destroyBill
POST   /budget/bills/{bill}/pay                 markBillPaid       -- body: actual_amount, for the period being viewed
POST   /budget/envelopes                        storeEnvelope
PATCH  /budget/envelopes/{envelope}              updateEnvelope
DELETE /budget/envelopes/{envelope}              destroyEnvelope
POST   /budget/sweep/{envelopePeriod}/confirm   confirmSweep       -- body: from_account_id
POST   /budget/sweep/{envelopePeriod}/decline   declineSweep
POST   /budget/extra-payment/confirm            confirmExtraPayment  -- body: from_account_id
```

`index` calls `BudgetService::periodFor()`, queries active `Debt`s for the minimums tier, computes the period's leftover figure (sum of `income` transactions minus minimums minus bills-expected minus envelope-targets), and resolves the **priority-linked debt**: walks the payoff engine's debt order and returns the first entry with a non-null `linked_account_id`. This resolver is shared by `confirmSweep`, `confirmExtraPayment`, and the `index` computation that decides whether to show the leftover-suggestion card at all (hidden entirely if no debt in the order is linked).

`confirmSweep`/`confirmExtraPayment` both call `LedgerService::recordTransfer($fromAccountId, $priorityDebt->linked_account_id, $amount)`, then set `sweep_accepted = true` (sweep) or mark the leftover assigned (extra payment). `declineSweep` sets `sweep_accepted = false` only — no transaction, no other state change; the declined amount isn't tracked anywhere separately, it's just informational.

`markBillPaid` validates `actual_amount` with the same bounds as `Transaction.amount` (`min:0.01`, `max:99999999.99`) and sets `paid_at` to now.

## Frontend

New page `resources/js/pages/Budget.vue`, sidebar entry under Money, with a month selector (default current month; navigating to a not-yet-visited month is what triggers lazy creation and sweep detection for it). Layout, top to bottom, matching the assignment order:

1. **Debt minimums** — read-only, pulled from active `Debt`s (name, `minimum_payment`), linking out to `/payoff-plan`. No edit affordance here; this section mirrors, it isn't a source of truth.
2. **Bills** — each row: name, expected vs. actual amount, due day, an inline "Mark paid" action (opens an `actual_amount` input, same inline-form pattern as `Ledger.vue`) hitting `markBillPaid`. A "Manage bills" affordance opens template CRUD (create/edit/deactivate), same inline-row `useForm` pattern as Ledger's "Add an account."
3. **Envelopes** — each row: name/icon, target, a spent-vs-target progress bar (spent computed server-side as the live sum of tagged transactions, passed as a prop), "Manage envelopes" for template CRUD.
4. **Sweep nudges** (only when any exist for the period being viewed) — one card per underspent envelope: *"You freed $47 in Groceries — send it to [Priority Debt]?"*, with a from-account picker and Confirm/Decline.
5. **Leftover-income suggestion** (only when a linked priority debt exists and leftover > 0): *"You have $X left — send it to [Priority Debt]?"*, with the same account picker.

**`Ledger.vue` change:** the "Log a transaction" form gains an optional envelope picker, shown only when `type === 'expense'`. `LedgerController::storeTransaction` accepts an optional `envelope_id`, validated the same way as the account ids.

## Edge cases

- Deleting a `Bill`/`Envelope` template cascade-deletes its `BillPeriod`/`EnvelopePeriod` rows — these are pure budget bookkeeping tied to the template, not independent transaction history (unlike the account-transaction `nullOnDelete` pattern).
- Deleting an `Envelope` nulls `envelope_id` on any tagged `Transaction` — the transaction itself is real ledger history and must survive.
- All new foreign ids validated with the ownership-scoped `Rule::exists` pattern already established in `LedgerController`/`PayoffPlanController`.
- Period/month date handling uses the already-fixed local-date convention (`toLocaleDateString('en-CA')` on the frontend; server-side period boundaries as plain `year`/`month` integers, never UTC-derived) — this app already hit a UTC-default date bug once (commit `d28df20`) and this feature shouldn't reintroduce it.
- `markBillPaid` and sweep/extra-payment confirms only ever act on the period currently being viewed — no editing a past period's `paid_at` or sweep decision after the fact, consistent with the payoff engine never rewriting history.

## Testing

New `tests/Feature/BudgetControllerTest.php` covering: template CRUD with ownership scoping; lazy period creation on first view, including that `expected_amount`/`target` are snapshotted (a later template edit doesn't retroactively change an already-created period row); sweep detection firing exactly once on next-month view and not re-firing on repeat visits; the priority-debt-fallback resolver (linked vs. unlinked debts, and the none-linked case hiding the suggestion entirely); `markBillPaid` validation bounds; and `confirmSweep`/`confirmExtraPayment` producing the expected `LedgerService::recordTransfer` call. Plus a small addition to the existing `LedgerControllerTest` for the new optional `envelope_id` on `storeTransaction`.

## Open questions (not designed here)

Carried forward from the debt-focus spec, still unaffected by this pass:

- **Statement balance vs. current balance** for revolving debts.
- **Net worth / broader reporting.**
- **Reconciliation with the existing `/money`+`/debt` implementation** — still deliberately deferred; `DebtFocus` stays separate until it reaches functional parity.

## Suggested next step

Once this spec is approved, hand off to `superpowers:writing-plans` for an implementation plan.
