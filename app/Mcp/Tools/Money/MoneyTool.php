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
