<?php

use App\Models\User;
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

test('the throttle is per user, not per address', function (): void {
    Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

    foreach (range(1, 60) as $_) {
        $this->postJson('/mcp/money', rpc('tools/list'))->assertOk();
    }

    $this->postJson('/mcp/money', rpc('tools/list'))->assertStatus(429);

    Passport::actingAs(User::factory()->create(), ['mcp:use'], 'api');

    $this->postJson('/mcp/money', rpc('tools/list'))->assertOk();
});

test('access tokens expire in about an hour and refresh tokens in thirty days', function (): void {
    $now = new DateTimeImmutable;
    $lifetime = fn (DateInterval $interval): int => $now->add($interval)->getTimestamp() - $now->getTimestamp();

    expect($lifetime(Passport::tokensExpireIn()))->toBeBetween(3500, 3600)
        ->and($lifetime(Passport::refreshTokensExpireIn()))->toBeBetween(30 * 86400 - 100, 30 * 86400 + 100);
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
