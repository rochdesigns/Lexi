<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

/**
 * OAuth discovery for the Money MCP server. The same documents laravel/mcp
 * serves, except that they also advertise the mcp:write scope, so a client
 * that wants to record entries knows to ask for it.
 */
class McpDiscoveryController extends Controller
{
    private const SCOPES = ['mcp:use', 'mcp:write'];

    public function protectedResource(string $path = ''): JsonResponse
    {
        return response()->json([
            'resource' => url('/'.$path),
            'authorization_servers' => [config('mcp.authorization_server') ?? url('/')],
            'scopes_supported' => self::SCOPES,
        ]);
    }

    public function authorizationServer(): JsonResponse
    {
        return response()->json([
            'issuer' => config('mcp.authorization_server') ?? url('/'),
            'authorization_endpoint' => route('passport.authorizations.authorize'),
            'token_endpoint' => route('passport.token'),
            'registration_endpoint' => url('oauth/register'),
            'response_types_supported' => ['code'],
            'code_challenge_methods_supported' => ['S256'],
            'scopes_supported' => self::SCOPES,
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
        ]);
    }
}
