# Money MCP server (read-only) — design

2026-10-04

## Goal

Let a Lexi user connect Claude (claude.ai connector, mobile, or Claude Code) to their own Lexi account and ask questions about their Money data. First version is **read-only** and **Money only**. Writes are a later, separate piece of work.

## Decisions

| Question | Decision |
|---|---|
| Scope | Money module only |
| Capability | Read-only; no write tools exist |
| Who connects | Every Lexi user, via OAuth (Laravel Passport) |
| Tool shape | A few coarse snapshot tools; no generic query tool |
| Controllers | Left unchanged; tools reuse `Projection` and the `$user->relation()` loading pattern |

## Dependencies (need approval before install)

- Add `laravel/passport`.
- Move `laravel/mcp` from its current indirect, dev-only presence (via Boost) into `require`.

## 1. Auth and deploy

**Flow**
1. User adds `https://lexi-app.me/mcp/money` as a custom connector.
2. `Mcp::oauthRoutes()` in `routes/ai.php` exposes discovery and client-registration endpoints.
3. User logs in with their normal Lexi session and approves a consent screen (published from `mcp-views`, styled in Lexi's look, stating "Your Money data, read-only").
4. Claude receives a token with the single `mcp:use` scope. Passport's `auth:api` guard resolves it to the user, so `$request->user()` behaves as in controllers.

**Route**
`Mcp::web('/mcp/money', MoneyServer::class)->middleware(['auth:api', 'verified', 'throttle:mcp'])`

- `verified`: unverified accounts cannot connect.
- `throttle:mcp`: per-user limiter, defined alongside the existing `fortify` limiter.

**Known limit.** Laravel MCP supports only the all-or-nothing `mcp:use` scope. Read-only is enforced by the server exposing no write tools, not by the token. Future write tools go on a separate server and route (e.g. `/mcp/money-write`) with their own consent wording so users opt in explicitly.

**Deploy (Forge)**
- Generate Passport keys once (`passport:keys`) or set `PASSPORT_PRIVATE_KEY` / `PASSPORT_PUBLIC_KEY` as env vars. Keys never live in the repo.
- Passport migrations run with the normal deploy.
- `ProductionConfigGuard` refuses to boot in production without Passport keys.
- Depends on `APP_URL` being `https://lexi-app.me` (launch checklist item): OAuth discovery and redirect URLs break the same way the signed email links did.

## 2. Server and tools

`App\Mcp\Servers\MoneyServer`, with tools in `App\Mcp\Tools\Money\`. No prompts or resources. All tools are annotated read-only (exact attribute name confirmed against the docs at build time). No tool accepts a user id or any ownership argument.

| Tool | Returns | Built from |
|---|---|---|
| `get_money_overview` | Headline numbers, upcoming items, shortfalls, carry alerts, recovery progress, cash available | `Projection` (same calls as `OverviewController`) |
| `list_debts` | Balance, rate, due date, minimum, kind, card details, recent payments | `$user->debts()` with relations |
| `list_accounts` | Name, type, balance, spendable flag | `$user->accounts()` |
| `list_bills` | Name, amount, frequency, due day | `$user->bills()` |
| `list_income_sources` | Name, amount, schedule, pay days, recent receipts | `$user->incomeSources()` |
| `list_goals` | Buffer and savings goals with progress | `$user->goals()` |

**Output conventions**
- Structured JSON plus a one-line text summary.
- Dates are ISO 8601. Amounts carry an explicit unit and currency. How `Centavos` stores money is checked at build time so pesos and centavos are never ambiguous.
- Lists are capped (e.g. 50 most recent payments per debt) and flag truncation.
- Tool descriptions say when to use each tool (e.g. start with `get_money_overview` for cash-flow questions).

**Exposure**
Each tool returns an explicit allow-list of fields, never whole models. Excluded: raw statement-import files, free-text notes that may be sensitive, anything under Settings. Each model's columns are reviewed when its tool is written.

**Empty state.** A user with no data gets a valid empty response (empty lists, zeroed overview), not an error.

**Isolation rules**
1. Tools never accept a user id; every query starts from `$request->user()`.
2. `App\Mcp` never references `App\Models\Money\*` directly (enforced by an arch test), so reads can only go through user relations.
3. The isolation guardrail from the launch checklist is not a prerequisite for this work, because tools only use relation-scoped reads. It remains a prerequisite for any write tools.

## 3. Testing

Pest feature tests in `tests/Feature/Mcp/`, using factories.

**Auth**
- Unauthenticated request → 401.
- Valid token with `mcp:use` → `tools/list` succeeds.
- Token without the scope → rejected.
- Unverified user → rejected.
- OAuth discovery and client-registration routes respond; the authorize page renders and approval issues a token.
- `throttle:mcp` returns 429 past the limit.

**Isolation**
- Per tool: two users with distinct, recognisable data; call as user A and assert none of user B's names, ids or amounts appear.
- One data-driven test iterates `MoneyServer`'s tool list so a newly added tool cannot skip the check.
- Arch test: `App\Mcp` does not reference `App\Models\Money\*`.

**Correctness**
- Per tool: one populated case and one empty-account case.
- `get_money_overview` matches the numbers `OverviewController` produces for identical data.
- Large lists are truncated and flagged.

**Read-only guarantee**
- Every registered tool carries the read-only annotation, and the server registers nothing outside an explicit allow-list. A write tool added later fails this test, which is the prompt to review it.

**Production guard**
- `ProductionConfigGuard` refuses to boot without Passport keys.

**Manual smoke test (once, after the `APP_URL` fix).** The real claude.ai connector handshake can only be exercised against the deployed https URL:
1. Add the connector, complete the consent screen, confirm tools list.
2. Ask a Money question and confirm the answer matches the Overview page.
3. Confirm a second test account sees only its own data.
4. Revoke the connection and confirm the token stops working.

## Out of scope

- Write tools (separate server, own spec, requires the isolation guardrail first).
- Tasks, Calendar and other modules.
- Refactoring controllers or extracting shared presenters.
- Custom OAuth scopes.
