# Payoff Plan — Frontend Design

**Date:** 2026-08-27
**Status:** Implemented
**Origin:** `docs/superpowers/plans/2026-08-27-debtfocus-payoff-engine.md` (backend), `docs/superpowers/specs/2026-08-27-money-section-debt-focus-design.md` (broader vision)

## Framing

The backend `DebtFocus` subsystem (`App\Models\DebtFocus\Debt`, `App\Services\DebtFocus\PayoffEngine`) was built standalone and explicitly excluded any frontend or routes — "a separate future plan." This document is that plan: a new page, called **Payoff Plan**, that lets a user enter debts, choose a payoff strategy, and see a live projection.

The existing `/debt` page (`Debt.vue`, `DebtController`, `App\Models\Debt`) is a separate, mature system wired into accounts, transactions, and envelope budgeting. Payoff Plan does not touch, read, or reuse any of it. The two pages coexist as independent nav items; a user who wants both currently enters their debts twice. Reconciling or merging the two systems is out of scope here, per the same deferral the backend plan already established.

## Navigation & routing

A new "Payoff Plan" item is added to the sidebar's Money section (`resources/js/components/AppSidebar.vue`), between "Forecast" and "Debt" — icon `trending_down`, href `/payoff-plan`.

New `PayoffPlanController` with these routes (grouped with the other authenticated money routes in `routes/web.php`):

```
GET    /payoff-plan                    PayoffPlanController@index
POST   /payoff-plan/debts              PayoffPlanController@storeDebt
PATCH  /payoff-plan/debts/{debt}       PayoffPlanController@updateDebt
DELETE /payoff-plan/debts/{debt}       PayoffPlanController@destroyDebt
PATCH  /payoff-plan/settings           PayoffPlanController@updateSettings
```

`{debt}` route-model-binds to `App\Models\DebtFocus\Debt`; every mutating action verifies `$debt->user_id === $request->user()->id` (404/403 otherwise), matching `DebtController`'s existing `abort_if` convention.

## Data model: `debt_focus_settings`

A new table holds the persisted strategy and extra-payment choice, kept under the same `debt_focus_` prefix as the rest of the subsystem — not a column on `users`, since this is domain data specific to the payoff plan, not an account-level preference.

**Field naming note:** the `debt_focus_debts` table's rate column is `monthly_rate`, not `apr` — during implementation this was renamed from `apr` (with a matching migration) because the engine was treating it as an already-monthly rate rather than dividing an annual rate by 12. Every `apr` reference below should be read as `monthly_rate`. A `due_day` column (nullable, 1–31) was also added to `debt_focus_debts` to record which day of the month a debt is due, surfaced in the debt list and add/edit form.

```
debt_focus_settings
  id
  user_id         -- unique FK to users, cascade delete
  strategy        -- string, 'avalanche' | 'snowball', default 'avalanche'
  extra_monthly   -- decimal(10,2), default 0
  timestamps
```

One row per user. `PayoffPlanController@index` uses `firstOrCreate(['user_id' => $user->id])` so a first-time visitor gets sensible defaults without a separate onboarding step.

`App\Models\DebtFocus\Settings` — a small Eloquent model, `fillable: ['user_id', 'strategy', 'extra_monthly']`, cast `extra_monthly` to `decimal:2`.

## Controller behavior

`index()`:
1. Load the user's `DebtFocus\Debt` records.
2. `firstOrCreate` their `DebtFocus\Settings` row.
3. Run `PayoffEngine::simulate($debts, $settings->strategy, (float) $settings->extra_monthly)`.
4. Compute a display-friendly debt-free date (`today->addMonthsNoOverflow($result['months'])->format('M Y')`) when `onTrack` is true — same helper `MoneyController` already uses for the analogous figure.
5. Order the debts for display by payoff priority under the current strategy: for `avalanche`, highest monthly rate first; for `snowball`, lowest starting balance first (mirrors `PayoffEngine`'s own targeting order, computed in the controller rather than duplicated in the frontend).
6. Render `Inertia::render('PayoffPlan', [...])`. Following this codebase's existing split (see `DebtController` vs `MoneyController`): per-debt item fields are snake_case, mirroring their DB columns plus two merged-in simulation fields — `payoff_month`, `total_interest` (renamed from the engine's `payoffMonth`/`totalInterest` when building each item array). Page-level aggregate props are camelCase, matching `MoneyController`'s `debtFreeMonths`/`debtFreeDate`/`debtOnTrack` convention: `debts` (list), `settings` (`strategy`, `extra_monthly` — settings is itself a DB-backed record, so its fields stay snake_case), `monthsRemaining`, `onTrack`, `debtFreeDate`.

`storeDebt()` / `updateDebt()`: validate `name` (required, string, max 100), `balance` (required, numeric, min 0), `monthly_rate` (nullable, numeric, min 0, max 100), `due_day` (nullable, integer, min 1, max 31), `minimum_payment` (required, numeric, min 0), `debt_type` (required, in `revolving,installment`). Redirect back — no separate JSON response, matching the existing Inertia form-post convention used by `DebtController`.

`destroyDebt()`: delete, redirect back.

`updateSettings()`: validate `strategy` (required, in `avalanche,snowball`), `extra_monthly` (required, numeric, min 0). Update-or-create the settings row, redirect back.

Every mutation triggers a full-page Inertia redirect back to `index()`, which re-runs the simulation fresh — there is no client-side re-simulation and no cached projection to invalidate, consistent with the backend's "always live" design.

## Frontend page: `PayoffPlan.vue`

Follows the visual conventions already established in `Money.vue` (rounded `22px` cards, `--hab-*` CSS variables, `Bricolage_Grotesque` headings, `TooltipProvider` wrapper) rather than introducing new patterns.

**1. Header** — "Payoff Plan" title, matching `Money.vue`'s header block.

**2. Headline card** — same gradient-background treatment as `Money.vue`'s "Left for daily expenses" card:
- No debts: "Add a debt to see your payoff plan."
- `onTrack === true`: "Debt-free by **[date]**" with months-remaining as a subline.
- `onTrack === false`: "Not on track — current payments don't cover accruing interest," styled like `Money.vue`'s existing off-track debt message (`#c0512c`).

**3. Strategy & extra payment card** — two controls, each submitting on change via `router.patch('/payoff-plan/settings', ...)` with `preserveScroll: true`:
- A two-option segmented control (Avalanche / Snowball), each with a one-line explainer ("Highest interest rate first" / "Lowest balance first") beneath it.
- A labeled currency input for extra monthly payment, debounced on blur rather than on every keystroke.

**4. Debt list card** — ordered per the controller's priority sort. Each row: name, debt-type badge, balance, monthly rate (or "—" when null), minimum payment, and on the right, payoff month/date and total interest (or "—" when monthly rate is null, since `PayoffEngine` only tracks interest when monthly rate is set). Clicking a row switches it into the same inline edit affordance described next; a small trailing delete icon (confirm via `window.confirm`, matching no existing pattern needing anything heavier since this is low-stakes user-owned data entry).

**5. Inline add/edit row** — at the bottom of the list, an "Add a debt" trigger row that expands into an inline form (name, balance, monthly rate, minimum payment, debt-type select, due day), mirroring `Money.vue`'s `openPayRow`/`cancelPayRow`/`submitPayRow` pattern: one `ref<number | null>` tracking which row (or `'new'`) is open, one `useForm()` instance, Confirm/Cancel buttons, inline field errors below the form. Editing an existing row reuses the identical form shape, pre-filled.

**Empty state:** zero debts shows only the headline card's empty message and the add-debt row — no list, no strategy card (nothing to project yet).

## Types

`resources/js/pages/PayoffPlan.vue` defines local prop types (matching the existing per-page pattern, e.g. `Money.vue`'s inline `UpcomingBill`/`CategoryOverBudget` types — no shared global type needed for a single-page feature):

```ts
type PayoffDebt = {
    id: number;
    name: string;
    balance: number;
    monthly_rate: number | null;
    due_day: number | null;
    minimum_payment: number;
    debt_type: 'revolving' | 'installment';
    payoff_month: number | null;
    total_interest: number | null;
};

type PayoffSettings = { strategy: 'avalanche' | 'snowball'; extra_monthly: number };

type PayoffPlanProps = {
    debts: PayoffDebt[];
    settings: PayoffSettings;
    monthsRemaining: number | null;
    onTrack: boolean;
    debtFreeDate: string | null;
};
```

## Testing

`tests/Feature/DebtFocus/PayoffPlanControllerTest.php` (new, alongside the existing `tests/Feature/DebtFocus/` suite):
- Renders with the correct Inertia component and props shape for a user with no debts (empty state props).
- Renders with debts, asserting the response includes each debt's `payoff_month`/`total_interest` from a real `PayoffEngine::simulate()` run (not mocked — the engine is fast and deterministic, no need to stub it).
- `firstOrCreate`s default settings for a first-time visitor.
- Store/update/destroy debt: persists correctly, redirects back, rejects another user's debt with 403/404.
- Update settings: persists strategy and extra_monthly, validation rejects an invalid strategy string.
- Debt list ordering matches strategy (avalanche → monthly rate desc, snowball → balance asc) for a multi-debt fixture.

No Dusk/browser test — this project's existing money pages (`Money.vue`, `Debt.vue`) have no browser-test coverage either; feature tests on the controller plus manual verification in Herd is the established bar.

## Explicitly out of scope

- Revolving-debt spend-vs-payment net-progress tracking (the `debt_type` field is captured and displayed as a badge, but no behavior branches on it yet — that's a separate future plan per the original design spec).
- Any reconciliation with the existing `/debt` page, `App\Models\Debt`, accounts, or transactions.
- Debt-first zero-based budgeting, the underspend-sweep nudge, and missed-payment severity — all still future plans per `2026-08-27-money-section-debt-focus-design.md`.
- Charts/timeline visualizations of the payoff trajectory — the headline stat + ordered list is the whole visualization for this iteration.
