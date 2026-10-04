<?php

use App\Http\Middleware\RestrictMcpRedirectHosts;
use App\Mcp\Servers\MoneyServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

Route::middleware([RestrictMcpRedirectHosts::class, 'throttle:mcp-register'])->group(function (): void {
    Mcp::oauthRoutes();
});

Mcp::web('/mcp/money', MoneyServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), 'verified', 'throttle:mcp']);
