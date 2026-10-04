<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Exact-match guard for dynamic OAuth client registration.
 *
 * The vendor controller accepts a redirect URI that merely starts with an
 * allowed prefix, so `http://localhost:80@evil.example/cb` slips through. This
 * runs first and compares the parsed scheme and host exactly, rejecting
 * userinfo. `config/mcp.php` stays the single source of truth.
 */
class RestrictMcpRedirectHosts
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $uris = $request->input('redirect_uris');

        if (! $request->isMethod('POST') || ! is_array($uris)) {
            return $next($request);
        }

        foreach ($uris as $uri) {
            if (! is_string($uri) || ! $this->isPermitted($uri)) {
                return $this->reject();
            }
        }

        return $next($request);
    }

    protected function isPermitted(string $uri): bool
    {
        $parts = parse_url($uri);

        if ($parts === false || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        $host = strtolower($parts['host'] ?? '');

        if (! in_array($scheme, ['http', 'https'], true) || $host === '') {
            return false;
        }

        return in_array([$scheme, $host], $this->allowedHosts(), true);
    }

    /**
     * @return list<array{string, string}>
     */
    protected function allowedHosts(): array
    {
        /** @var array<int, string> $domains */
        $domains = config('mcp.redirect_domains', []);

        $allowed = [];

        foreach ($domains as $domain) {
            $parts = parse_url($domain);
            $scheme = strtolower($parts['scheme'] ?? '');
            $host = strtolower($parts['host'] ?? '');

            if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
                continue;
            }

            if ($scheme === 'http' && ! in_array($host, ['localhost', '127.0.0.1'], true)) {
                continue;
            }

            $allowed[] = [$scheme, $host];
        }

        return $allowed;
    }

    protected function reject(): JsonResponse
    {
        return response()->json([
            'error' => 'invalid_redirect_uri',
            'error_description' => 'The redirect URI is not a permitted redirect domain.',
        ], 400);
    }
}
