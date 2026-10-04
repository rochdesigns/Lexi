<?php

use App\Mcp\Servers\MoneyServer;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Passport\Http\Middleware\CheckToken;

Mcp::oauthRoutes();

Mcp::web('/mcp/money', MoneyServer::class)
    ->middleware(['auth:api', CheckToken::using('mcp:use'), 'verified', 'throttle:mcp']);
