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
