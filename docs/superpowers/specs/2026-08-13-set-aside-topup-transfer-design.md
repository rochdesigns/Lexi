# Set-aside top-up as a real transfer

## Problem

Topping up a set-aside (`SinkingFundController::topUp`) currently just does `$sinkingFund->increment('saved', $amount)`. It doesn't touch any account balance, doesn't create a `Transaction`, and isn't tagged to an account — so there's no record of where the money actually came from, and it doesn't show up in account transaction history or net worth movement.

Spending *from* a set-aside already works differently and correctly: an `expense`-type `Transaction` with `sinking_fund_id` set decrements the paying account's balance and decrements the fund's `saved` (`TransactionController::store/update/destroy`). Top-ups should follow the same pattern, just in the opposite direction.

## Design

A top-up is a normal account-to-account **transfer**, tagged with which set-aside it's for. Concretely: a `Transaction` with `type: 'transfer'`, `account_id` (from), `to_account_id` (to), and `sinking_fund_id` set.

This reuses the existing `transactions.sinking_fund_id` column and `type` enum — no migration needed. It mirrors the existing `expense` + `sinking_fund_id` case, but increments `saved` instead of decrementing it:

| Transaction type | `sinking_fund_id` effect |
|---|---|
| `expense` (existing) | decrements fund `saved` — spending the fund down |
| `transfer` (new) | increments fund `saved` — topping the fund up |

The old `SinkingFundController::topUp` endpoint (amount-only, no account) is removed; it's fully superseded by creating a tagged transfer through `TransactionController`.

## Backend changes

**`TransactionController`** — extend the sinking-fund-effect handling (currently gated to `$type === 'expense'`) to also cover `$type === 'transfer'`:

- `store`: after creating the transaction and applying the transfer's balance effect, if `sinking_fund_id` is present, `$fund->increment('saved', $amount)`.
- `update`: reverse the old effect (decrement `saved` if the transaction *was* a fund-tagged transfer) before applying the new one (increment `saved` if the updated transaction *is* a fund-tagged transfer) — same reverse-then-reapply shape already used for the expense case and for account balances.
- `destroy`: reverse — decrement `saved` when deleting a fund-tagged transfer.

No change to validation: `to_account_id` is already required and must differ from `account_id` for transfers; `sinking_fund_id` is already nullable on every type.

**`SinkingFundController`** — remove `topUp()`.

**`routes/web.php`** — remove `POST /sinking-funds/{sinkingFund}/top-up`.

## Frontend changes

**`resources/js/pages/SetAsides.vue`** — the "Top up" modal expands from a single amount field into a mini transfer form:
- Amount
- From account (select)
- To account (select; must differ from From)
- Date (defaults to today)

`sinking_fund_id` is set implicitly to the fund being topped up (not user-editable). Submits to `TransactionController::store` (already imported on this page as `storeTransaction`, used by the existing "Pay" modal) instead of the removed `topUp` action. The existing `accounts` prop (already passed by `SinkingFundController::index`) supplies both dropdowns.

**`resources/js/components/AppSidebarHeader.vue`** ("Log spending" global modal) — add an optional "Set-aside" dropdown, shown only when `type === 'transfer'`, so a transfer logged from anywhere in the app can also be tagged as a top-up. Populated from a new shared prop, `sinkingFunds`.

**`app/Http/Middleware/HandleInertiaRequests.php`** — add a `sinkingFunds` shared prop (`id`, `name`), following the same pattern as the existing `budgetCategories` and `spendingAccounts` shares, scoped to the authenticated user.

Out of scope: the expense/spend-down side already has its own "Pay" modal on the Set Asides page (`payForm`, tags `expense` + `sinking_fund_id`) — untouched by this change.

## Testing

- `tests/Feature/TransactionTest.php`: add cases for transfer + `sinking_fund_id` —
  - creating increments the fund's `saved`
  - deleting reverses it
  - updating reassigns correctly (amount change, fund change, fund removed from an existing transfer)
- `tests/Feature/SinkingFundTest.php`: remove the three top-up tests (`top-up increments fund saved amount`, `top-up validates amount is positive`, `top-up returns 403 for another user's fund`) — they hit the endpoint being deleted.
- Run `php artisan test --compact --filter=Transaction` and `--filter=SinkingFund` after.
