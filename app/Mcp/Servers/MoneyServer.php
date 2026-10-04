<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\Money\GetMoneyOverview;
use App\Mcp\Tools\Money\ListAccounts;
use App\Mcp\Tools\Money\ListBills;
use App\Mcp\Tools\Money\ListDebts;
use App\Mcp\Tools\Money\ListGoals;
use App\Mcp\Tools\Money\ListIncomeSources;
use App\Mcp\Tools\Money\Write\RecordDebtPayment;
use App\Mcp\Tools\Money\Write\RecordTransaction;
use Laravel\Mcp\Server;

class MoneyServer extends Server
{
    /**
     * The read-only tools, offered to every connection. A tool that writes does not belong here: it goes in WRITE_TOOLS.
     *
     * @var list<class-string<Server\Tool>>
     */
    public const TOOLS = [
        GetMoneyOverview::class,
        ListAccounts::class,
        ListBills::class,
        ListIncomeSources::class,
        ListGoals::class,
        ListDebts::class,
    ];

    /**
     * The tools that record entries. Offered only to a token with the mcp:write scope.
     *
     * @var list<class-string<Server\Tool>>
     */
    public const WRITE_TOOLS = [
        RecordTransaction::class,
        RecordDebtPayment::class,
    ];

    protected string $name = 'Lexi Money';

    protected string $version = '1.0.0';

    protected string $instructions = 'Access to the signed-in user\'s Lexi Money data. Reading is always available; the record_* tools only exist when the user allowed recording, and they add entries but never edit or delete. Amounts are integer centavos in PHP. Start with get_money_overview for questions about cash flow or what is safe to spend; use the list_* tools for detail.';

    /** @var array<int, class-string<Server\Tool>> */
    protected array $tools = [...self::TOOLS, ...self::WRITE_TOOLS];
}
