<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Money\ListAccounts;
use App\Mcp\Tools\Money\ListBills;
use App\Mcp\Tools\Money\ListDebts;
use App\Mcp\Tools\Money\ListGoals;
use App\Mcp\Tools\Money\ListIncomeSources;
use Laravel\Mcp\Server;

class MoneyServer extends Server
{
    /**
     * Every tool this server may expose. Read-only: a tool that writes does not belong here.
     *
     * @var list<class-string<Server\Tool>>
     */
    public const TOOLS = [
        ListAccounts::class,
        ListBills::class,
        ListIncomeSources::class,
        ListGoals::class,
        ListDebts::class,
    ];

    protected string $name = 'Lexi Money';

    protected string $version = '1.0.0';

    protected string $instructions = 'Read-only access to the signed-in user\'s Lexi Money data. Amounts are integer centavos in PHP. Start with get_money_overview for questions about cash flow or what is safe to spend; use the list_* tools for detail.';

    /** @var array<int, class-string<Server\Tool>> */
    protected array $tools = self::TOOLS;
}
