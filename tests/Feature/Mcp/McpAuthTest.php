<?php

use App\Models\User;
use Illuminate\Support\Facades\Route;
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

/**
 * A throwaway RSA key pair for Passport's guard and authorization server.
 *
 * The real keys are gitignored, so a fresh checkout or a CI runner has none,
 * and every request that reaches the guard would fail with "Invalid key
 * supplied". Generating a pair here keeps these tests independent of the
 * machine they run on.
 *
 * @return array{private: string, public: string}
 */
function passportTestKeys(): array
{
    static $keys = null;

    if ($keys === null) {
        $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);

        if ($resource === false || ! openssl_pkey_export($resource, $private) || ! is_array($details = openssl_pkey_get_details($resource))) {
            throw new RuntimeException('Could not generate a test RSA key pair.');
        }

        $keys = ['private' => $private, 'public' => $details['key']];
    }

    return $keys;
}

beforeEach(function (): void {
    $keys = passportTestKeys();

    config(['passport.private_key' => $keys['private'], 'passport.public_key' => $keys['public']]);
});

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
        ->assertJsonPath('scopes_supported', ['mcp:use', 'mcp:write']);

    $this->getJson('/.well-known/oauth-protected-resource')->assertOk()->assertJsonPath('scopes_supported', ['mcp:use', 'mcp:write']);
    $this->getJson('/.well-known/oauth-protected-resource/mcp/money')->assertOk()->assertJsonPath('resource', url('/mcp/money'));
});

test('a client can register itself', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ])->assertSuccessful();
});

test('registration is refused for a redirect domain that is not allowed', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://evil.example/cb'],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

test('a loopback redirect is allowed to register', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Inspector',
        'redirect_uris' => ['http://localhost:6274/callback'],
    ])->assertSuccessful();
});

test('the consent screen says what the connection can see', function (string $redirectUri, string $host): void {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient(
        'Claude',
        [$redirectUri],
        confidential: false,
    );

    $this->actingAs(User::factory()->create())
        ->get('/oauth/authorize?'.http_build_query([
            'client_id' => $client->getKey(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => 'mcp:use',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'state' => 'xyz',
        ]))
        ->assertOk()
        ->assertSee('Your Money data')
        ->assertSee('read-only')
        ->assertSee('Claude will be sent back to <strong>'.$host.'</strong>', false);
})->with([
    'claude.ai' => ['https://claude.ai/api/mcp/auth_callback', 'claude.ai'],
    'loopback' => ['http://localhost:6274/callback', 'localhost'],
]);

test('the consent screen only offers recording when the write scope is requested', function (): void {
    $client = app(ClientRepository::class)->createAuthorizationCodeGrantClient('Claude', ['https://claude.ai/api/mcp/auth_callback'], confidential: false);
    $authorize = fn (string $scope) => $this->actingAs(User::factory()->create())->get('/oauth/authorize?'.http_build_query([
        'client_id' => $client->getKey(),
        'redirect_uri' => 'https://claude.ai/api/mcp/auth_callback',
        'response_type' => 'code',
        'scope' => $scope,
        'code_challenge' => str_repeat('a', 43),
        'code_challenge_method' => 'S256',
        'state' => 'xyz',
    ]));

    $authorize('mcp:use mcp:write')->assertOk()->assertSee('record')->assertDontSee('read-only');
    $authorize('mcp:use')->assertOk()->assertSee('read-only');
});

test('requests past the limit are throttled', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

    foreach (range(1, 60) as $_) {
        $this->postJson('/mcp/money', rpc('tools/list'))->assertOk();
    }

    $this->postJson('/mcp/money', rpc('tools/list'))->assertStatus(429);
});

test('the throttle is per user, not per address', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

    foreach (range(1, 60) as $_) {
        $this->postJson('/mcp/money', rpc('tools/list'))->assertOk();
    }

    $this->postJson('/mcp/money', rpc('tools/list'))->assertStatus(429);

    Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

    $this->postJson('/mcp/money', rpc('tools/list'))->assertOk();
});

test('access tokens expire in exactly an hour and refresh tokens in exactly thirty days', function (): void {
    $access = Passport::tokensExpireIn();
    $refresh = Passport::refreshTokensExpireIn();

    expect([$access->y, $access->m, $access->d, $access->h, $access->i, $access->s])->toBe([0, 0, 0, 1, 0, 0])
        ->and([$refresh->y, $refresh->m, $refresh->d, $refresh->h, $refresh->i, $refresh->s])->toBe([0, 0, 30, 0, 0, 0]);
});

test('the eleventh registration from one address within a minute is throttled', function (): void {
    $register = fn () => $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
    ]);

    foreach (range(1, 10) as $_) {
        $register()->assertSuccessful();
    }

    $register()->assertStatus(429);
});

test('discovery documents stay available after registrations are throttled', function (): void {
    foreach (range(1, 11) as $_) {
        $this->postJson('/oauth/register', [
            'client_name' => 'Claude',
            'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback'],
        ]);
    }

    foreach (range(1, 15) as $_) {
        $this->getJson('/.well-known/oauth-authorization-server')->assertOk();
        $this->getJson('/.well-known/oauth-protected-resource')->assertOk();
    }
});

test('the device code grant is not offered', function (): void {
    expect(Route::has('passport.device.code'))->toBeFalse();

    $this->postJson('/oauth/device/code', ['client_id' => 'x', 'scope' => 'mcp:use'])->assertNotFound();

    $this->getJson('/.well-known/oauth-authorization-server')
        ->assertJsonPath('grant_types_supported', ['authorization_code', 'refresh_token']);
});

test('registration is refused for lookalike and userinfo redirect hosts', function (string $uri): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [$uri],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
})->with([
    'localhost userinfo' => 'http://localhost:80@evil.example/cb',
    'claude.ai userinfo' => 'https://claude.ai@evil.example/cb',
    'claude.ai subdomain of evil' => 'https://claude.ai.evil.example/cb',
    'claude.ai prefix' => 'https://claude.aievil.com/cb',
    'localhost subdomain of evil' => 'http://localhost.evil.example/cb',
    'plain http elsewhere' => 'http://evil.example/cb',
    'plain http claude' => 'http://claude.ai/cb',
]);

test('registration succeeds for every permitted redirect', function (string $uri): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [$uri],
    ])->assertSuccessful();
})->with([
    'https://claude.ai/api/mcp/auth_callback',
    'https://claude.com/api/mcp/auth_callback',
    'http://localhost:6274/callback',
    'http://127.0.0.1:6274/callback',
]);

test('one bad redirect rejects the whole registration', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => ['https://claude.ai/api/mcp/auth_callback', 'http://localhost:80@evil.example/cb'],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});

test('a non-string redirect entry fails closed', function (): void {
    $this->postJson('/oauth/register', [
        'client_name' => 'Claude',
        'redirect_uris' => [['https://claude.ai/cb']],
    ])
        ->assertStatus(400)
        ->assertJsonPath('error', 'invalid_redirect_uri');
});
