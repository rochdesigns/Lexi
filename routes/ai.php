<?php

use App\Http\Controllers\McpDiscoveryController;
use App\Http\Middleware\RestrictMcpRedirectHosts;
use App\Mcp\Servers\MoneyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

Route::middleware([RestrictMcpRedirectHosts::class, 'throttle:mcp-register'])->group(function (): void {
    Mcp::oauthRoutes();
});

// After oauthRoutes() so these replace its discovery documents, which only list mcp:use.
Route::get('/.well-known/oauth-protected-resource', [McpDiscoveryController::class, 'protectedResource'])->name('mcp.oauth.protected-resource');
Route::get('/.well-known/oauth-protected-resource/{path}', [McpDiscoveryController::class, 'protectedResource'])->where('path', '.*')->name('mcp.oauth.protected-resource.nested');
Route::get('/.well-known/oauth-authorization-server', [McpDiscoveryController::class, 'authorizationServer'])->name('mcp.oauth.authorization-server');
Route::get('/.well-known/oauth-authorization-server/{path}', [McpDiscoveryController::class, 'authorizationServer'])->where('path', '.*')->name('mcp.oauth.authorization-server.nested');

Mcp::web('/mcp/money', MoneyServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), 'verified', 'throttle:mcp']);
