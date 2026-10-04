# Money MCP Server (Read-Only) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let any Lexi user connect Claude to their own account over OAuth and read their Money data through six read-only MCP tools.

**Architecture:** A `laravel/mcp` web server at `/mcp/money`, protected by Passport's `auth:api` guard (single `mcp:use` scope), `verified`, and a per-user `throttle:mcp` limiter. Tools load data only through `$request->user()` relations and reuse the existing `Projection` service, so isolation follows the same pattern the controllers already use. No tool writes.

**Tech Stack:** PHP 8.4, Laravel 13, `laravel/mcp` ^0.8 (installed as v0.8.2 today, dev-only via Boost), `laravel/passport` (new), Pest 4, Larastan level 7, Pint.

**Spec:** `docs/superpowers/specs/2026-10-04-money-mcp-readonly-design.md`

## Global Constraints

- Money module only; **read-only**. No tool may create, update or delete anything.
- Auth is Passport OAuth with the single `mcp:use` scope (Laravel MCP supports no custom scopes). Route middleware is exactly `auth:api`, scope check for `mcp:use`, `verified`, `throttle:mcp`.
- Tools never accept a user id or any ownership argument. Every query starts from `$request->user()`.
- `App\Mcp` must never reference `App\Models\Money\*` directly (arch-tested). Do not import or type-hint money models in tool code; rely on inferred closure types.
- Tools return an explicit allow-list of fields, never whole models. Never expose `notes` (free text), raw statement imports, or anything under Settings.
- All money values in the app are **integer centavos**. Every tool response states `currency: PHP` and `amountsIn: centavos`.
- Lists are capped and flag truncation (payments: 50 most recent per debt).
- Do not change controllers. Do not add dependencies beyond `laravel/passport` and moving `laravel/mcp` into `require`.
- Code style: PHP 8 constructor promotion, explicit return types, PHPDoc array shapes, curly braces always. Run `vendor/bin/pint --dirty --format agent` before every commit.
- Tests are Pest feature tests in `tests/Feature/Mcp/`, using factories. Run only the affected tests: `php artisan test --compact --filter=...`.
- Commit trailer on every commit: `Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>`.

## File Structure

| File | Responsibility |
|---|---|
| `composer.json` / `composer.lock` | Add `laravel/passport`; add `laravel/mcp` to `require` |
| `config/auth.php` | Add the `api` guard (`passport` driver) |
| `app/Models/User.php` | Implement Passport's `OAuthenticatable`, use `HasApiTokens` |
| `app/Providers/AppServiceProvider.php` | Passport authorization view; `mcp` rate limiter |
| `resources/views/mcp/authorize.blade.php` | Consent screen (published from `mcp-views`, then edited) |
| `routes/ai.php` | `Mcp::oauthRoutes()` + the guarded `Mcp::web('/mcp/money', ...)` |
| `app/Mcp/Servers/MoneyServer.php` | Server definition; `TOOLS` constant is the allow-list |
| `app/Mcp/Tools/Money/MoneyTool.php` | Abstract base: resolve user, build structured response |
| `app/Mcp/Tools/Money/ListAccounts.php` | `list_accounts` |
| `app/Mcp/Tools/Money/ListBills.php` | `list_bills` |
| `app/Mcp/Tools/Money/ListIncomeSources.php` | `list_income_sources` |
| `app/Mcp/Tools/Money/ListGoals.php` | `list_goals` |
| `app/Mcp/Tools/Money/ListDebts.php` | `list_debts` |
| `app/Mcp/Tools/Money/GetMoneyOverview.php` | `get_money_overview` |
| `app/Services/ProductionConfigGuard.php` | Refuse production boot without Passport keys |
| `tests/Pest.php` | `seedMarkedMoney()` helper shared by Mcp tests |
| `tests/Feature/Mcp/*.php` | Auth, per-tool, isolation, read-only and arch tests |

---

### Task 1: Passport, the server skeleton, and the auth boundary

**Files:**
- Modify: `composer.json`, `composer.lock`, `config/auth.php`, `app/Models/User.php`, `app/Providers/AppServiceProvider.php`
- Create: `routes/ai.php`, `app/Mcp/Servers/MoneyServer.php`, `app/Mcp/Tools/Money/MoneyTool.php`, `resources/views/mcp/authorize.blade.php`
- Test: `tests/Feature/Mcp/McpAuthTest.php`

**Interfaces:**
- Produces: `App\Mcp\Servers\MoneyServer` with `public const TOOLS` (list of tool class-strings, empty for now); `App\Mcp\Tools\Money\MoneyTool` with `protected function userFor(Request $request): User` and `protected function respond(string $summary, array $data): ResponseFactory`; route `POST /mcp/money`; limiter `mcp`.

> Passport 13's docs are not indexed by `search-docs` and the package is not installed yet. After Step 1, read `vendor/laravel/passport/README.md` and `vendor/laravel/passport/src/` to confirm the names used below (`OAuthenticatable`, `HasApiTokens`, `Passport::authorizationView`, `Passport::actingAs`, `CheckToken::using`). If a name differs, use the installed package's name and note it in the commit message.

- [ ] **Step 1: Install the packages**

```bash
composer require laravel/passport laravel/mcp --no-interaction
php artisan migrate --no-interaction
php artisan passport:keys --no-interaction
git status --short
```
Expected: `composer.json` now lists both packages under `require`; Passport's migrations ran; `storage/oauth-private.key` and `oauth-public.key` exist and are **not** shown by `git status` (they must be gitignored; if they appear, add `/storage/*.key` to `.gitignore`).

- [ ] **Step 2: Write the failing auth tests**

Create `tests/Feature/Mcp/McpAuthTest.php`:

```php
<?php

use App\Models\User;
use Laravel\Passport\Client;
use Laravel\Passport\ClientRepository;
use Laravel\Passport\Passport;

/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function rpc(string $method, array $params = []): array
{
    return ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => (object) $params];
}

test('an unauthenticated request is rejected', function (): void {
    $this->postJson('/mcp/money', rpc('tools/list'))->assertUnauthorized();
});

test('a token with the mcp scope can list tools', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

    $this->postJson('/mcp/money', rpc('tools/list'))->assertOk();
});

test('a token without the mcp scope is rejected', function (): void {
    Passport::actingAs(User::factory()->create(), [], 'api');

    $this->postJson('/mcp/money', rpc('tools/list'))->assertForbidden();
});

test('an unverified user is rejected', function (): void {
    Passport::actingAs(User::factory()->unverified()->create(), ['mcp:use'], 'api');

    $this->postJson('/mcp/money', rpc('tools/list'))->assertForbidden();
});

test('the oauth discovery documents are served', function (): void {
    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertOk()
        ->assertJsonPath('scopes_supported', ['mcp:use']);

    $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
});

test('a client can register itself', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ])->assertSuccessful();
});

test('the consent screen says what the connection can see', function (): void {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Claude',
        ['https://claude.ai/api/mcp/auth_callback'],
        confidential: false,
    );

    $this->actingAs(User::factory()->create())
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ]))
        ->assertOk()
        ->assertSee('Your Money data')
        ->assertSee('read-only');
});

test('requests past the limit are throttled', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

    foreach (range(1, 60) as $_) {
        $this->postJson('/mcp/money', rpc('tools/list'))->assertOk();
    }

    $this->postJson('/mcp/money', rpc('tools/list'))->assertStatus(429);
});
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --compact --filter=McpAuthTest`
Expected: FAIL (404 for `/mcp/money`, unknown `api` guard, missing classes).

- [ ] **Step 4: Add the `api` guard**

In `config/auth.php`, inside `'guards'`, after the `web` guard:

```php
        'api' => [
            'driver' => 'passport',
            'provider' => 'users',
        ],
```

- [ ] **Step 5: Make `User` an OAuth-authenticatable**

In `app/Models/User.php`: add `use Laravel\Passport\Contracts\OAuthenticatable;` and `use Laravel\Passport\HasApiTokens;`, change the class line and trait list to:

```php
class User extends Authenticatable implements MustVerifyEmail, OAuthenticatable, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;
```

- [ ] **Step 6: Define the limiter and the consent view**

In `app/Providers/AppServiceProvider.php` add imports `Illuminate\Cache\RateLimiting\Limit`, `Illuminate\Http\Request`, `Illuminate\Support\Facades\RateLimiter`, `Laravel\Passport\Passport`, call `$this->configureMcp();` from `boot()`, and add:

```php
    /**
     * Wire up the MCP server's OAuth consent screen and rate limit.
     *
     * The limit is per user, so one noisy connection cannot starve another.
     */
    protected function configureMcp(): void
    {
        Passport::authorizationView(fn (array $parameters) => view('mcp.authorize', $parameters));

        RateLimiter::for('mcp', fn (Request $request): Limit => Limit::perMinute(60)->by(
            (string) ($request->user()?->getAuthIdentifier() ?? $request->ip()),
        ));
    }
```

Publish the consent view, then edit it:

```bash
php artisan vendor:publish --tag=mcp-views --no-interaction
```

Edit `resources/views/mcp/authorize.blade.php` so the visible copy states what is granted. Keep the published form fields and buttons untouched; change only the heading and body text to: heading "Connect Claude to Lexi", body "This lets Claude read **Your Money data** (debts, accounts, bills, income and goals). It is read-only: Claude cannot add, change or delete anything." Style it with the same palette and font as `resources/views/errors/` (inline CSS, no Vite).

- [ ] **Step 7: Create the server, base tool and routes**

`app/Mcp/Servers/MoneyServer.php`:

```php
<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class MoneyServer extends Server
{
    /**
     * Every tool this server may expose. Read-only: a tool that writes does not belong here.
     *
     * @var list<class-string<Server\Tool>>
     */
    public const TOOLS = [];

    protected string $name = 'Lexi Money';

    protected string $version = '1.0.0';

    protected string $instructions = 'Read-only access to the signed-in user\'s Lexi Money data. Amounts are integer centavos in PHP. Start with get_money_overview for questions about cash flow or what is safe to spend; use the list_* tools for detail.';

    /** @var array<int, class-string<Server\Tool>> */
    protected array $tools = self::TOOLS;
}
```

`app/Mcp/Tools/Money/MoneyTool.php`:

```php
<?php

namespace App\Mcp\Tools\Money;

use App\Models\User;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use RuntimeException;

abstract class MoneyTool extends Tool
{
    /**
     * Resolve the authenticated user. Tools never accept a user id: this is the only way in.
     */
    protected function userFor(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new RuntimeException('The Money tools require an authenticated user.');
        }

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function respond(string $summary, array $data): ResponseFactory
    {
        return Response::structured([
            'summary' => $summary,
            'currency' => 'PHP',
            'amountsIn' => 'centavos (100 centavos = 1 PHP)',
            ...$data,
        ]);
    }
}
```

`routes/ai.php`:

```php
<?php

use App\Mcp\Servers\MoneyServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

Mcp::oauthRoutes();

Mcp::web('/mcp/money', MoneyServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), 'verified', 'throttle:mcp']);
```

- [ ] **Step 8: Run the tests to verify they pass**

Run: `php artisan test --compact --filter=McpAuthTest`
Expected: PASS. If `tools/list` without a prior `initialize` is rejected by the server, send an `initialize` request first in the two authenticated tests (method `initialize`, params `protocolVersion`, `capabilities`, `clientInfo`) and carry the `MCP-Session-Id` header from its response.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add composer.json composer.lock config/auth.php app/Models/User.php app/Providers/AppServiceProvider.php routes/ai.php app/Mcp resources/views/mcp tests/Feature/Mcp/McpAuthTest.php .gitignore
git commit -m "$(cat <<'EOF'
feat: add the Money MCP server skeleton behind Passport OAuth

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 2: Shared test data, `list_accounts`, and the isolation test

**Files:**
- Modify: `tests/Pest.php`, `app/Mcp/Servers/MoneyServer.php`
- Create: `app/Mcp/Tools/Money/ListAccounts.php`
- Test: `tests/Feature/Mcp/ListAccountsTest.php`, `tests/Feature/Mcp/MoneyIsolationTest.php`

**Interfaces:**
- Consumes: `MoneyTool::userFor()`, `MoneyTool::respond()`, `MoneyServer::TOOLS` (Task 1).
- Produces: `seedMarkedMoney(User $user, string $marker): void` in `tests/Pest.php` (creates one of each money record, every name containing `$marker`); `ListAccounts` (`list_accounts`); the data-driven isolation test over `MoneyServer::TOOLS`.

- [ ] **Step 1: Add the shared seeding helper**

In `tests/Pest.php`, add imports (`App\Models\Money\Account`, `Bill`, `Debt`, `DebtPayment`, `Goal`, `IncomeSource`, `PaymentPlan`, `App\Models\User`) and replace the `something()` placeholder function with:

```php
/**
 * One of every money record for the user, each named with the marker, so a
 * test can tell whose data a response contains.
 */
function seedMarkedMoney(User $user, string $marker): void
{
    Account::factory()->for($user)->create(['name' => "{$marker} account"]);
    Bill::factory()->for($user)->create(['name' => "{$marker} bill", 'due_day' => now()->addDays(2)->day]);
    IncomeSource::factory()->for($user)->create(['name' => "{$marker} income"]);
    Goal::factory()->for($user)->create(['name' => "{$marker} goal"]);

    $debt = Debt::factory()->for($user)->create(['name' => "{$marker} debt", 'lender' => "{$marker} lender"]);
    PaymentPlan::factory()->for($debt)->create(['description' => "{$marker} plan"]);
    DebtPayment::factory()->for($debt)->create();
}
```

- [ ] **Step 2: Write the failing tests**

`tests/Feature/Mcp/ListAccountsTest.php`:

```php
<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListAccounts;
use App\Models\Money\Account;
use App\Models\User;

test('it lists the user\'s accounts with balances', function (): void {
    $user = User::factory()->create();
    Account::factory()->for($user)->create(['name' => 'BDO Savings', 'balance' => 250_000]);
    Account::factory()->for($user)->notSpendable()->create(['name' => 'Rainy Day', 'balance' => 900_000]);

    MoneyServer::actingAs($user)->tool(ListAccounts::class)
        ->assertOk()
        ->assertSee(['BDO Savings', 'Rainy Day'])
        ->assertStructuredContent(fn ($json) => $json
            ->where('currency', 'PHP')
            ->where('cashAvailable', 250_000)
            ->has('accounts', 2)
            ->has('accounts.0', fn ($account) => $account
                ->where('name', 'BDO Savings')
                ->where('balance', 250_000)
                ->where('isSpendable', true)
                ->etc())
            ->etc());
});

test('a user with no accounts gets an empty list, not an error', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListAccounts::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('accounts', [])
            ->where('cashAvailable', 0)
            ->etc());
});
```

`tests/Feature/Mcp/MoneyIsolationTest.php`:

```php
<?php

use App\Mcp\Servers\MoneyServer;
use App\Models\User;

test('no tool returns another user\'s data', function (string $tool): void {
    $mine = User::factory()->create();
    $theirs = User::factory()->create();
    seedMarkedMoney($mine, 'AAA-MARK');
    seedMarkedMoney($theirs, 'BBB-MARK');

    MoneyServer::actingAs($mine)->tool($tool)
        ->assertOk()
        ->assertDontSee('BBB-MARK');
})->with(MoneyServer::TOOLS);
```

- [ ] **Step 3: Run to verify failure**

Run: `php artisan test --compact --filter="ListAccountsTest|MoneyIsolationTest"`
Expected: FAIL (`ListAccounts` class not found).

- [ ] **Step 4: Implement the tool and register it**

`app/Mcp/Tools/Money/ListAccounts.php`:

```php
<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Projection;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListAccounts extends MoneyTool
{
    protected string $name = 'list_accounts';

    protected string $description = 'List the user\'s money accounts (bank, cash, e-wallet) with balances. Also returns cashAvailable: the total of spendable accounts only, which excludes savings kept aside. Use this for questions about how much money the user has on hand.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $accounts = $this->userFor($request)->accounts()->orderBy('name')->get();

        $rows = $accounts->map(fn ($account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'balance' => $account->balance,
            'isSpendable' => $account->is_spendable,
        ])->values()->all();

        return $this->respond(
            count($rows).' account(s) as of '.Carbon::today()->toDateString().'.',
            ['accounts' => $rows, 'cashAvailable' => $projection->cashAvailable($accounts)],
        );
    }
}
```

In `MoneyServer`, set `public const TOOLS = [ListAccounts::class];` with `use App\Mcp\Tools\Money\ListAccounts;`.

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --compact --filter="ListAccountsTest|MoneyIsolationTest"`
Expected: PASS. If method injection of `Projection` into `handle()` is not resolved by this package version, take it through the tool's constructor instead and keep the rest unchanged.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Pest.php app/Mcp tests/Feature/Mcp
git commit -m "$(cat <<'EOF'
feat: add the list_accounts MCP tool and the cross-user isolation test

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 3: `list_bills`, `list_income_sources`, `list_goals`

**Files:**
- Modify: `app/Mcp/Servers/MoneyServer.php`
- Create: `app/Mcp/Tools/Money/ListBills.php`, `ListIncomeSources.php`, `ListGoals.php`
- Test: `tests/Feature/Mcp/ListBillsTest.php`, `ListIncomeSourcesTest.php`, `ListGoalsTest.php`

**Interfaces:**
- Consumes: `MoneyTool`, `seedMarkedMoney` (Tasks 1–2). `Projection::nextBillDate(Bill, CarbonInterface): CarbonImmutable`, `Projection::monthlyObligations(Collection $debts, Collection $bills, CarbonInterface $today): int`, `Projection::goalTarget(Goal, int $obligations): int`, `Projection::goalProgress(Goal, int $target, CarbonInterface $today): array`.
- Produces: tools `list_bills`, `list_income_sources`, `list_goals`.

- [ ] **Step 1: Write the failing tests**

`tests/Feature/Mcp/ListBillsTest.php`:

```php
<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListBills;
use App\Models\Money\Bill;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-15')));

test('it lists bills with their next due date', function (): void {
    $user = User::factory()->create();
    Bill::factory()->for($user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25, 'autopay' => true]);
    Bill::factory()->for($user)->annual(3)->create(['name' => 'Insurance', 'amount' => 600_000, 'due_day' => 10]);

    MoneyServer::actingAs($user)->tool(ListBills::class)
        ->assertOk()
        ->assertSee(['Rent', 'Insurance'])
        ->assertStructuredContent(fn ($json) => $json
            ->has('bills', 2)
            ->has('bills.1', fn ($bill) => $bill
                ->where('name', 'Rent')
                ->where('amount', 150_000)
                ->where('frequency', 'monthly')
                ->where('dueDay', 25)
                ->where('autopay', true)
                ->where('nextDueDate', '2026-10-25')
                ->etc())
            ->etc());
});

test('a user with no bills gets an empty list', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListBills::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('bills', [])->etc());
});
```

`tests/Feature/Mcp/ListIncomeSourcesTest.php`:

```php
<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListIncomeSources;
use App\Models\Money\IncomeSource;
use App\Models\Money\Transaction;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-15')));

test('it lists income sources with pay days and recent receipts', function (): void {
    $user = User::factory()->create();
    $salary = IncomeSource::factory()->for($user)->semiMonthly(15, 30)->create(['name' => 'Salary', 'amount' => 2_000_000]);
    Transaction::factory()->for($user)->income()->create(['income_source_id' => $salary->id, 'amount' => 2_000_000, 'date' => '2026-10-13']);
    Transaction::factory()->for($user)->income()->create(['income_source_id' => $salary->id, 'amount' => 2_000_000, 'date' => '2026-08-01']);

    MoneyServer::actingAs($user)->tool(ListIncomeSources::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('incomeSources', 1)
            ->has('incomeSources.0', fn ($source) => $source
                ->where('name', 'Salary')
                ->where('amount', 2_000_000)
                ->where('schedule', 'semi_monthly')
                ->where('payDays', [15, 30])
                ->has('recentReceipts', 1)
                ->has('recentReceipts.0', fn ($receipt) => $receipt->where('date', '2026-10-13')->where('amount', 2_000_000))
                ->etc())
            ->etc());
});

test('a user with no income sources gets an empty list', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListIncomeSources::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('incomeSources', [])->etc());
});
```

`tests/Feature/Mcp/ListGoalsTest.php`:

```php
<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListGoals;
use App\Models\Money\Goal;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(fn () => $this->travelTo(Carbon::parse('2026-10-15')));

test('it lists the buffer and the sinking funds with progress', function (): void {
    $user = User::factory()->create();
    Goal::factory()->for($user)->buffer(3)->create(['saved' => 100_000]);
    Goal::factory()->for($user)->create(['name' => 'New laptop', 'target_amount' => 5_000_000, 'saved' => 1_000_000, 'monthly_contribution' => 200_000]);

    MoneyServer::actingAs($user)->tool(ListGoals::class)
        ->assertOk()
        ->assertSee(['Emergency buffer', 'New laptop'])
        ->assertStructuredContent(fn ($json) => $json
            ->has('goals', 2)
            ->where('goals.0.kind', 'buffer')
            ->where('goals.1.name', 'New laptop')
            ->where('goals.1.targetAmount', 5_000_000)
            ->where('goals.1.saved', 1_000_000)
            ->etc());
});

test('a user with no goals gets an empty list', function (): void {
    MoneyServer::actingAs(User::factory()->create())->tool(ListGoals::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('goals', [])->etc());
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter="ListBillsTest|ListIncomeSourcesTest|ListGoalsTest"`
Expected: FAIL (classes not found).

- [ ] **Step 3: Implement `ListBills`**

```php
<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Projection;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListBills extends MoneyTool
{
    protected string $name = 'list_bills';

    protected string $description = 'List the user\'s recurring bills (monthly or annual) with amount, due day, autopay flag and the next due date. Use this for questions about what bills are coming up or what the user owes each month.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $today = Carbon::today();
        $bills = $this->userFor($request)->bills()->orderBy('name')->get();

        $rows = $bills->map(fn ($bill): array => [
            'id' => $bill->id,
            'name' => $bill->name,
            'amount' => $bill->amount,
            'frequency' => $bill->frequency->value,
            'dueDay' => $bill->due_day,
            'dueMonth' => $bill->due_month,
            'autopay' => $bill->autopay,
            'nextDueDate' => $projection->nextBillDate($bill, $today)->toDateString(),
        ])->values()->all();

        return $this->respond(count($rows).' bill(s).', ['bills' => $rows]);
    }
}
```

- [ ] **Step 4: Implement `ListIncomeSources`**

```php
<?php

namespace App\Mcp\Tools\Money;

use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListIncomeSources extends MoneyTool
{
    protected string $name = 'list_income_sources';

    protected string $description = 'List the user\'s income sources (salary, side income) with amount per pay day, schedule, pay days of the month, and anything received in the last 7 days. Use this for questions about when and how much the user gets paid.';

    public function handle(Request $request): ResponseFactory
    {
        $sources = $this->userFor($request)->incomeSources()->with('receipts')->orderBy('name')->get();

        $rows = $sources->map(fn ($source): array => [
            'id' => $source->id,
            'name' => $source->name,
            'amount' => $source->amount,
            'schedule' => $source->schedule->value,
            'payDays' => $source->payDays(),
            'recentReceipts' => $source->receipts->map(fn ($receipt): array => [
                'date' => $receipt->date->toDateString(),
                'amount' => $receipt->amount,
            ])->values()->all(),
        ])->values()->all();

        return $this->respond(count($rows).' income source(s).', ['incomeSources' => $rows]);
    }
}
```

- [ ] **Step 5: Implement `ListGoals`**

```php
<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Projection;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListGoals extends MoneyTool
{
    protected string $name = 'list_goals';

    protected string $description = 'List the user\'s emergency buffer and savings goals (sinking funds) with target, amount saved, monthly contribution and progress. Use this for questions about savings and how close the user is to a goal.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $today = Carbon::today();
        $user = $this->userFor($request);
        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
        $bills = $user->bills()->orderBy('name')->get();
        $goals = $user->goals()->with('account')->orderBy('name')->get();
        $obligations = $projection->monthlyObligations($debts, $bills, $today);

        $rows = $goals->map(fn ($goal): array => [
            'id' => $goal->id,
            'kind' => $goal->kind->value,
            'name' => $goal->name,
            'monthlyContribution' => $goal->monthly_contribution,
            'saved' => $goal->saved,
            'accountName' => $goal->account?->name,
            'bufferMonths' => $goal->buffer_months,
            'targetAmount' => $goal->target_amount,
            'targetDate' => $goal->target_date?->toDateString(),
            ...$projection->goalProgress($goal, $projection->goalTarget($goal, $obligations), $today),
        ])->values()->all();

        return $this->respond(count($rows).' goal(s).', ['goals' => $rows]);
    }
}
```

Note: the goals test expects the buffer first. `orderBy('name')` sorts "Emergency buffer" before "New laptop" — keep that ordering.

- [ ] **Step 6: Register the tools**

In `MoneyServer::TOOLS` add `ListBills::class`, `ListIncomeSources::class`, `ListGoals::class` (with imports).

- [ ] **Step 7: Run to verify they pass, including isolation**

Run: `php artisan test --compact --filter="ListBillsTest|ListIncomeSourcesTest|ListGoalsTest|MoneyIsolationTest"`
Expected: PASS. If a `where()` on a nested path fails because `goalProgress` adds keys that collide with ours, rename nothing: adjust the expectation to the actual key and keep our fields authoritative by spreading `goalProgress` first.

- [ ] **Step 8: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp
git commit -m "$(cat <<'EOF'
feat: add the bills, income sources and goals MCP tools

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 4: `list_debts`

**Files:**
- Modify: `app/Mcp/Servers/MoneyServer.php`
- Create: `app/Mcp/Tools/Money/ListDebts.php`
- Test: `tests/Feature/Mcp/ListDebtsTest.php`

**Interfaces:**
- Consumes: `MoneyTool`; `Projection` methods used by `DebtController::present`: `totalRemaining`, `dueThisMonth`, `paidInMonth`, `nextDueDate`, `nextStatement`, `projectedNextStatement`, `minimumDue`, `utilization`, `monthsLeft`, `endMonth`, `remainingBalance`, `totalMonthlyPayment`.
- Produces: tool `list_debts`. Deliberately omits `notes`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\ListDebts;
use App\Models\Money\CardDetail;
use App\Models\Money\Debt;
use App\Models\Money\DebtPayment;
use App\Models\Money\PaymentPlan;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

test('it lists debts with plans and recent payments', function (): void {
    $loan = Debt::factory()->for($this->user)->create(['name' => 'Bank loan', 'lender' => 'BPI', 'due_day' => 20, 'notes' => 'SECRET-NOTE']);
    $plan = PaymentPlan::factory()->for($loan)->create(['principal' => 3_000_000, 'monthly_amount' => 500_000, 'total_months' => 6, 'start_month' => '2026-10-01']);
    DebtPayment::factory()->for($loan)->create(['payment_plan_id' => $plan->id, 'amount' => 500_000, 'date' => '2026-10-02']);

    MoneyServer::actingAs($this->user)->tool(ListDebts::class)
        ->assertOk()
        ->assertSee(['Bank loan', 'BPI'])
        ->assertDontSee('SECRET-NOTE')
        ->assertStructuredContent(fn ($json) => $json
            ->has('debts', 1)
            ->has('debts.0', fn ($debt) => $debt
                ->where('name', 'Bank loan')
                ->where('type', 'loan')
                ->where('dueDay', 20)
                ->where('paidThisMonth', 500_000)
                ->has('plans', 1)
                ->has('plans.0', fn ($p) => $p->where('monthlyAmount', 500_000)->where('totalMonths', 6)->etc())
                ->has('payments', 1)
                ->where('paymentsTruncated', false)
                ->where('card', null)
                ->etc())
            ->where('monthlyTotal', 500_000)
            ->etc());
});

test('a credit card includes its statement details', function (): void {
    $card = Debt::factory()->for($this->user)->create(['name' => 'Visa', 'type' => 'credit_card', 'interest_type' => 'revolving', 'monthly_interest_rate' => 3.5, 'due_day' => 28]);
    CardDetail::factory()->for($card)->create(['credit_limit' => 10_000_000, 'revolving_balance' => 2_000_000, 'statement_day' => 10]);

    MoneyServer::actingAs($this->user)->tool(ListDebts::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('debts.0.card.creditLimit', 10_000_000)
            ->where('debts.0.card.revolvingBalance', 2_000_000)
            ->where('debts.0.card.statementDay', 10)
            ->etc());
});

test('payments are capped at fifty per debt and flagged', function (): void {
    $loan = Debt::factory()->for($this->user)->create();
    DebtPayment::factory()->for($loan)->count(55)->create();

    MoneyServer::actingAs($this->user)->tool(ListDebts::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->has('debts.0.payments', 50)
            ->where('debts.0.paymentsTruncated', true)
            ->etc());
});

test('a user with no debts gets an empty list', function (): void {
    MoneyServer::actingAs($this->user)->tool(ListDebts::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('debts', [])->where('monthlyTotal', 0)->etc());
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=ListDebtsTest`
Expected: FAIL (`ListDebts` not found).

- [ ] **Step 3: Implement `ListDebts`**

```php
<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Projection;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListDebts extends MoneyTool
{
    private const PAYMENT_CAP = 50;

    protected string $name = 'list_debts';

    protected string $description = 'List the user\'s debts (loans, credit cards, buy-now-pay-later, informal) with balance, interest, due day, what is due and paid this month, payment plans, credit card statement details, and the 50 most recent payments per debt. Use this for questions about what the user owes and how payoff is going.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $today = Carbon::today();
        $debts = $this->userFor($request)->debts()
            ->with(['cardDetail', 'paymentPlans.debt', 'payments', 'transactions'])
            ->orderBy('name')
            ->get();

        $rows = $debts->map(function ($debt) use ($projection, $today): array {
            $card = $debt->cardDetail;
            $payments = $debt->payments
                ->sortByDesc(fn ($payment): string => $payment->date->toDateString().str_pad((string) $payment->id, 10, '0', STR_PAD_LEFT))
                ->values();

            return [
                'id' => $debt->id,
                'name' => $debt->name,
                'type' => $debt->type->value,
                'lender' => $debt->lender,
                'interestType' => $debt->interest_type->value,
                'monthlyInterestRate' => $debt->monthly_interest_rate,
                'dueDay' => $debt->due_day,
                'status' => $debt->status->value,
                'outstandingBalance' => $debt->outstanding_balance,
                'targetPayoffDate' => $debt->target_payoff_date?->toDateString(),
                'totalRemaining' => $projection->totalRemaining($debt, $today),
                'dueThisMonth' => $projection->dueThisMonth($debt, $today),
                'paidThisMonth' => $projection->paidInMonth($debt, $today),
                'nextDueDate' => $projection->nextDueDate($debt, $today)?->toDateString(),
                'card' => $card === null ? null : [
                    'creditLimit' => $card->credit_limit,
                    'statementDay' => $card->statement_day,
                    'dueDaysAfterStatement' => $card->due_days_after_statement,
                    'minPaymentPercent' => $card->min_payment_percent,
                    'minPaymentFloor' => $card->min_payment_floor,
                    'revolvingBalance' => $card->revolving_balance,
                    'closesOn' => $projection->nextStatement($debt, $today)['closes_on']->toDateString(),
                    'projectedStatement' => $projection->projectedNextStatement($debt, $today),
                    'minimumDue' => $projection->minimumDue($debt, $today),
                    'utilization' => $projection->utilization($debt, $today),
                ],
                'plans' => $debt->paymentPlans->map(fn ($plan): array => [
                    'id' => $plan->id,
                    'description' => $plan->description,
                    'principal' => $plan->principal,
                    'monthlyAmount' => $plan->monthly_amount,
                    'totalMonths' => $plan->total_months,
                    'startMonth' => $plan->start_month->format('Y-m'),
                    'endMonth' => $projection->endMonth($plan)->format('Y-m'),
                    'monthsLeft' => $projection->monthsLeft($plan, $today),
                    'remainingBalance' => $projection->remainingBalance($plan, $today),
                    'inMinimum' => $plan->in_minimum,
                    'status' => $plan->status->value,
                ])->values()->all(),
                'payments' => $payments->take(self::PAYMENT_CAP)->map(fn ($payment): array => [
                    'date' => $payment->date->toDateString(),
                    'amount' => $payment->amount,
                    'isExtra' => $payment->is_extra,
                ])->values()->all(),
                'paymentsTruncated' => $payments->count() > self::PAYMENT_CAP,
            ];
        })->values()->all();

        return $this->respond(count($rows).' debt(s).', [
            'debts' => $rows,
            'monthlyTotal' => $projection->totalMonthlyPayment($debts, $today),
        ]);
    }
}
```

- [ ] **Step 4: Register and run**

Add `ListDebts::class` to `MoneyServer::TOOLS`.

Run: `php artisan test --compact --filter="ListDebtsTest|MoneyIsolationTest"`
Expected: PASS. If a test fails on a card-detail value, compare against what `DebtController::present` returns for the same fixture and fix the tool, not the controller.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp
git commit -m "$(cat <<'EOF'
feat: add the list_debts MCP tool

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 5: `get_money_overview`

**Files:**
- Modify: `app/Mcp/Servers/MoneyServer.php`
- Create: `app/Mcp/Tools/Money/GetMoneyOverview.php`
- Test: `tests/Feature/Mcp/GetMoneyOverviewTest.php`

**Interfaces:**
- Consumes: `MoneyTool`; `Projection::overview`, `carriedBalanceAlerts`, `cashAvailable`, `shortfalls`, `upcoming`, `goalContributions`, `recoveryPlan`; `RecoveryRequest::MONTH_OPTIONS` (an array of ints; first element is used for the carry-over probe, exactly as `OverviewController::recovery` does).
- Produces: tool `get_money_overview` returning keys `numbers`, `carryAlerts`, `recovery`, `cashAvailable`, `shortfalls`, `upcoming`.

- [ ] **Step 1: Write the failing test**

```php
<?php

use App\Mcp\Servers\MoneyServer;
use App\Mcp\Tools\Money\GetMoneyOverview;
use App\Models\Money\Bill;
use App\Models\Money\Debt;
use App\Models\Money\IncomeSource;
use App\Models\Money\PaymentPlan;
use App\Models\User;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->travelTo(Carbon::parse('2026-10-15'));
    $this->user = User::factory()->create();
});

function seedOverviewMonth(User $user): void
{
    IncomeSource::factory()->for($user)->create(['amount' => 3_000_000, 'pay_day_1' => 30]);
    IncomeSource::factory()->for($user)->semiMonthly(15, 30)->create(['amount' => 1_000_000]);
    Bill::factory()->for($user)->create(['name' => 'Rent', 'amount' => 150_000, 'due_day' => 25]);
    Bill::factory()->for($user)->create(['name' => 'Internet', 'amount' => 250_000, 'due_day' => 5]);
    Bill::factory()->for($user)->annual(3)->create(['name' => 'Insurance', 'amount' => 600_000]);
    Bill::factory()->for($user)->annual(10)->create(['name' => 'Domain', 'amount' => 100_000, 'due_day' => 28]);
    $loan = Debt::factory()->for($user)->create(['name' => 'Bank loan', 'due_day' => 20]);
    PaymentPlan::factory()->for($loan)->create(['principal' => 3_000_000, 'monthly_amount' => 500_000, 'total_months' => 6, 'start_month' => '2026-10-01']);
}

test('the headline numbers match the Overview page for the same data', function (): void {
    seedOverviewMonth($this->user);

    $expected = null;
    $this->actingAs($this->user)->get('/money')->assertInertia(function ($page) use (&$expected): void {
        $expected = $page->toArray()['props'];
    });

    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('numbers', $expected['numbers'])
            ->where('cashAvailable', $expected['cashAvailable'])
            ->where('shortfalls', $expected['shortfalls'])
            ->where('upcoming', $expected['upcoming'])
            ->where('carryAlerts', $expected['carryAlerts'])
            ->etc());
});

test('the safe-to-spend figure matches the hand calculation', function (): void {
    seedOverviewMonth($this->user);

    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('numbers.safeToSpend', 4_041_666)
            ->where('numbers.perDay', 237_745)
            ->etc());
});

test('a brand-new user gets zeroed numbers, not an error', function (): void {
    MoneyServer::actingAs($this->user)->tool(GetMoneyOverview::class)
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('numbers.income', 0)
            ->where('numbers.safeToSpend', 0)
            ->where('upcoming', [])
            ->where('recovery', null)
            ->etc());
});
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=GetMoneyOverviewTest`
Expected: FAIL (`GetMoneyOverview` not found).

- [ ] **Step 3: Implement the tool**

```php
<?php

namespace App\Mcp\Tools\Money;

use App\Http\Requests\Money\RecoveryRequest;
use App\Services\Money\Projection;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMoneyOverview extends MoneyTool
{
    protected string $name = 'get_money_overview';

    protected string $description = 'The user\'s money at a glance: monthly income, expenses, set-asides, debt payments and safe-to-spend (total and per day), cash available, upcoming bills and pay days for the next 14 days, projected shortfalls over the next 30 days, and carried credit-card balance alerts. Start here for questions about cash flow, what is safe to spend, or whether the user will run short.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $today = Carbon::today();
        $user = $this->userFor($request);
        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments', 'balanceSnapshots'])->get();
        $bills = $user->bills()->orderBy('name')->get();
        $sources = $user->incomeSources()->with('receipts')->orderBy('name')->get();
        $accounts = $user->accounts()->orderBy('name')->get();
        $recoveryMonths = $user->setting?->recovery_months;
        $contributions = $projection->goalContributions($user->goals()->with('account')->get(), $debts, $bills, $today);

        $numbers = $projection->overview($debts, $bills, $sources, $today, $contributions['buffer'], $recoveryMonths, $contributions['sinking']);
        $carryover = $projection->recoveryPlan($debts, RecoveryRequest::MONTH_OPTIONS[0], $today)['carryover'];

        return $this->respond(
            'Safe to spend this month: '.$numbers['safeToSpend'].' centavos ('.$numbers['perDay'].' per day).',
            [
                'numbers' => $numbers,
                'carryAlerts' => $projection->carriedBalanceAlerts($debts, $today),
                'recovery' => $carryover === 0 ? null : [
                    'carryover' => $carryover,
                    'months' => $recoveryMonths,
                    'extraPerMonth' => $recoveryMonths === null ? 0 : $projection->recoveryPlan($debts, $recoveryMonths, $today)['extra'],
                ],
                'cashAvailable' => $projection->cashAvailable($accounts),
                'shortfalls' => $projection->shortfalls($debts, $bills, $sources, $accounts, $today),
                'upcoming' => $projection->upcoming($debts, $bills, $sources, $today),
            ],
        );
    }
}
```

- [ ] **Step 4: Register and run**

Add `GetMoneyOverview::class` to `MoneyServer::TOOLS`.

Run: `php artisan test --compact --filter="GetMoneyOverviewTest|MoneyIsolationTest"`
Expected: PASS. The parity test is the guard against drifting from `OverviewController`: if it fails, change the tool to match the controller.

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Mcp tests/Feature/Mcp
git commit -m "$(cat <<'EOF'
feat: add the get_money_overview MCP tool

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 6: Read-only guarantee and architecture tests

**Files:**
- Test: `tests/Feature/Mcp/ReadOnlyGuaranteeTest.php`, `tests/Feature/Mcp/McpArchTest.php`

**Interfaces:**
- Consumes: `MoneyServer::TOOLS` and all six tools (Tasks 2–5).

- [ ] **Step 1: Write the tests**

`tests/Feature/Mcp/ReadOnlyGuaranteeTest.php`:

```php
<?php

use App\Mcp\Servers\MoneyServer;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

const ALLOWED_MONEY_TOOLS = [
    'get_money_overview',
    'list_accounts',
    'list_bills',
    'list_debts',
    'list_goals',
    'list_income_sources',
];

test('the server exposes exactly the reviewed read-only tools', function (): void {
    $names = collect(MoneyServer::TOOLS)->map(fn (string $tool): string => (new $tool)->name())->sort()->values()->all();

    expect($names)->toBe(ALLOWED_MONEY_TOOLS);
});

test('every tool is annotated read-only', function (string $tool): void {
    expect((new ReflectionClass($tool))->getAttributes(IsReadOnly::class))->not->toBeEmpty();
    expect((new $tool)->toArray()['annotations'])->toMatchArray(['readOnlyHint' => true]);
})->with(MoneyServer::TOOLS);

test('no tool takes a user or ownership argument', function (string $tool): void {
    $properties = array_keys((array) ((new $tool)->toArray()['inputSchema']['properties'] ?? []));

    expect($properties)->each->not->toMatch('/user|owner|account_id|tenant/i');
})->with(MoneyServer::TOOLS);
```

`tests/Feature/Mcp/McpArchTest.php`:

```php
<?php

arch('MCP code reads money only through the user relations')
    ->expect('App\Mcp')
    ->not->toUse('App\Models\Money');

arch('MCP code does not bypass Eloquent scoping with raw queries')
    ->expect('App\Mcp')
    ->not->toUse(['Illuminate\Support\Facades\DB', 'Illuminate\Database\Query\Builder']);

arch('MCP tools do not write')
    ->expect('App\Mcp\Tools')
    ->not->toHaveMethods(['store', 'update', 'delete', 'create', 'save']);
```

- [ ] **Step 2: Run**

Run: `php artisan test --compact --filter="ReadOnlyGuaranteeTest|McpArchTest"`
Expected: PASS. If the arch test reports an `App\Models\Money` reference, remove the import or type-hint from the tool and rely on inference; do not weaken the test. If `toHaveMethods` is not available in this Pest version, replace that arch test with a `->not->toUse('Illuminate\Database\Eloquent\Model')` assertion and a comment-free grep check for `->create(`/`->update(`/`->delete(` in `app/Mcp`.

- [ ] **Step 3: Commit**

```bash
vendor/bin/pint --dirty --format agent
git add tests/Feature/Mcp
git commit -m "$(cat <<'EOF'
test: pin the Money MCP server to its reviewed read-only tool set

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 7: Production guard for Passport keys

**Files:**
- Modify: `app/Services/ProductionConfigGuard.php`, `tests/Unit/ProductionConfigGuardTest.php`

**Interfaces:**
- Produces: `ProductionConfigGuard::__construct(Repository $config, ?string $keyDirectory = null)`; `assert()` now also reports a missing Passport private/public key. The key counts as present if `passport.private_key` / `passport.public_key` config is non-blank **or** the file exists in `$keyDirectory ?? storage_path()`.

- [ ] **Step 1: Write the failing tests**

In `tests/Unit/ProductionConfigGuardTest.php`: add `'passport' => ['private_key' => 'a-private-key', 'public_key' => 'a-public-key']` to the default config array in `guardFor()`, extend its signature to `guardFor(array $overrides = [], ?string $keyDirectory = null)` passing `$keyDirectory` as the second constructor argument, and append:

```php
test('missing passport keys are rejected', function () {
    $empty = sys_get_temp_dir().'/lexi-no-keys-'.uniqid();
    mkdir($empty);

    expect(fn () => guardFor(['passport' => ['private_key' => null, 'public_key' => null]], $empty)->assert())
        ->toThrow(RuntimeException::class, 'Passport');

    rmdir($empty);
});

test('passport keys supplied as key files pass', function () {
    $dir = sys_get_temp_dir().'/lexi-keys-'.uniqid();
    mkdir($dir);
    touch($dir.'/oauth-private.key');
    touch($dir.'/oauth-public.key');

    guardFor(['passport' => ['private_key' => null, 'public_key' => null]], $dir)->assert();

    unlink($dir.'/oauth-private.key');
    unlink($dir.'/oauth-public.key');
    rmdir($dir);
})->throwsNoExceptions();
```

- [ ] **Step 2: Run to verify failure**

Run: `php artisan test --compact --filter=ProductionConfigGuardTest`
Expected: the two new tests FAIL (no exception thrown / unknown constructor argument).

- [ ] **Step 3: Implement**

In `ProductionConfigGuard`: change the constructor to `public function __construct(private Repository $config, private ?string $keyDirectory = null) {}`, extend the failures array in `assert()` with `...$this->oauthKeyFailures()`, and add:

```php
    /**
     * Get the reasons the MCP server could not sign or verify OAuth tokens.
     *
     * Passport reads its keys from the environment or from files in storage.
     * Without either, every connection attempt fails at runtime, so refuse to
     * boot instead.
     *
     * @return array<int, string>
     */
    private function oauthKeyFailures(): array
    {
        $directory = $this->keyDirectory ?? storage_path();
        $failures = [];

        foreach (['private' => 'oauth-private.key', 'public' => 'oauth-public.key'] as $kind => $file) {
            $suppliedByEnv = filled($this->config->get("passport.{$kind}_key"));

            if (! $suppliedByEnv && ! file_exists($directory.DIRECTORY_SEPARATOR.$file)) {
                $failures[] = 'The Passport '.$kind.' key is missing. Set PASSPORT_'.strtoupper($kind).'_KEY or run `php artisan passport:keys`, otherwise the MCP connector cannot authenticate anyone.';
            }
        }

        return $failures;
    }
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --compact --filter=ProductionConfigGuardTest`
Expected: PASS (all existing guard tests still pass thanks to the updated default config).

- [ ] **Step 5: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Services/ProductionConfigGuard.php tests/Unit/ProductionConfigGuardTest.php
git commit -m "$(cat <<'EOF'
feat: refuse to boot in production without Passport keys

Co-Authored-By: Claude Sonnet 5.5 <noreply@anthropic.com>
EOF
)"
```

---

### Task 8: Whole-feature verification and deploy hand-off

**Files:** none new.

- [ ] **Step 1: Run the full affected suites, static analysis and formatter**

```bash
php artisan test --compact tests/Feature/Mcp tests/Unit/ProductionConfigGuardTest.php
php artisan test --compact
vendor/bin/phpstan analyse --memory-limit=2G
vendor/bin/pint --format agent
```
Expected: all green; PHPStan level 7 clean; Pint reports nothing to fix. Fix any failure at its cause; do not loosen tests or baselines.

- [ ] **Step 2: Confirm the route table**

Run: `php artisan route:list --path=mcp --except-vendor` and `php artisan route:list --path=oauth`
Expected: `POST mcp/money` with middleware `auth:api`, the scope check, `verified`, `throttle:mcp`; `GET`/`DELETE mcp/money` return 405; OAuth discovery and `oauth/register` present.

- [ ] **Step 3: Report the deploy checklist to the user (do not run it)**

State these as outstanding, owner-run items. None can be done from the repo:
1. `APP_URL` must be `https://lexi-app.me` first (OAuth discovery and redirect URLs depend on it).
2. Set `PASSPORT_PRIVATE_KEY` and `PASSPORT_PUBLIC_KEY` on Forge (or run `php artisan passport:keys` once on the server) **before** deploying this release. `ProductionConfigGuard` now fails the boot without them, so the old release keeps serving if they are missing.
3. Run the migrations on deploy (Passport's tables).
4. Manual smoke test against the deployed URL: add the connector in claude.ai, complete the consent screen, confirm tools list, ask a Money question and compare with the Overview page, confirm a second test account sees only its own data, then revoke the connection and confirm the token stops working.

---

## Self-Review

**Spec coverage**
- Auth flow, route middleware, scope limit, `throttle:mcp`, `verified` → Task 1.
- Deploy keys + production guard → Task 7, checklist in Task 8.
- Six tools, field allow-lists, truncation, empty state, units → Tasks 2–5; `notes` excluded in Task 4 (tested).
- Isolation: no user argument (Task 6), arch test (Task 6), per-tool two-user test (Task 2, extended automatically as tools register).
- Correctness: overview parity with `OverviewController` (Task 5), populated and empty cases per tool, truncation test (Task 4).
- Read-only guarantee and allow-list (Task 6).
- Manual smoke test (Task 8).

**Known deviations from the spec**
- The spec's "approval issues a token" test is not automated: Passport's authorization-code flow needs a session-bound `auth_token` and a PKCE verifier, which is brittle to fake. Task 1 tests the consent screen renders and Passport owns the token endpoint; issuance is covered by the manual smoke test in Task 8.
- `get_money_overview` returns a trimmed `recovery` block (carry-over, chosen months, extra per month) instead of the Overview page's option list, since the option list exists only to drive that page's picker.

**Placeholder scan:** none left. Steps that depend on Passport's installed API (Task 1) say exactly what to read and what to do if a name differs.

**Type consistency:** `MoneyTool::userFor()` / `respond()` are used with the same signatures in Tasks 2–5; `MoneyServer::TOOLS` is the single source for the isolation, read-only and name allow-list tests; `seedMarkedMoney()` is defined in Task 2 and used in Task 2's isolation test only.
