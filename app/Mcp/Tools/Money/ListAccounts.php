<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Projection;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListAccounts extends MoneyTool
{
    protected string $name = 'list_accounts';

    protected string $description = 'List the user\'s money accounts (bank, cash, e-wallet) with balances. Also returns cashAvailable: the total of spendable accounts only, which excludes savings kept aside. Use this for questions about how much money the user has on hand.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $accounts = $this->userFor($request)->accounts()->orderBy('name')->get();

        $rows = $accounts->map(fn ($account): array => [
            'id' => $account->id,
            'name' => $account->name,
            'type' => $account->type->value,
            'balance' => $account->balance,
            'isSpendable' => $account->is_spendable,
        ])->values()->all();

        return $this->respond(
            count($rows).' account(s) as of '.Carbon::today()->toDateString().'.',
            ['accounts' => $rows, 'cashAvailable' => $projection->cashAvailable($accounts)],
        );
    }
}
