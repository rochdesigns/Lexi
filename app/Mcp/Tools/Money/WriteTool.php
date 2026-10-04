<?php

namespace App\Mcp\Tools\Money;

use Illuminate\Http\Request as HttpRequest;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;

/**
 * A tool that records something. It exists for a connection only when that
 * connection's token carries the write scope, and refuses to run without it
 * even if a client calls it by name.
 */
abstract class WriteTool extends MoneyTool
{
    public const SCOPE = 'mcp:write';

    public function shouldRegister(HttpRequest $request): bool
    {
        return $request->user()?->tokenCan(self::SCOPE) === true;
    }

    protected function refuseWithoutWriteScope(Request $request): ?Response
    {
        if ($request->user()?->tokenCan(self::SCOPE) === true) {
            return null;
        }

        return Response::error('This connection was authorized read-only. Reconnect and allow Lexi to record entries to use this tool.');
    }
}
