<?php

namespace App\Mcp\Tools\Money;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListTransactions extends MoneyTool
{
    protected string $name = 'list_transactions';

    protected string $description = 'List the user\'s expenses, income, transfers and balance adjustments for one month, newest first, with their ids, category (customCategoryName when it is one of the user\'s own) and the account or card used. Defaults to the current month. Use this to find an entry\'s id before editing it, or for questions about past spending.';

    public function schema(JsonSchema $schema): array
    {
        return [
            'month' => $schema->string()->description('YYYY-MM. Defaults to the current month.'),
        ];
    }

    public function handle(Request $request): ResponseFactory|Response
    {
        $month = $request->get('month') ?? Carbon::today()->format('Y-m');

        if (! is_string($month) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1) {
            return Response::error('month must be YYYY-MM.');
        }

        $first = Carbon::createFromFormat('Y-m-d', $month.'-01')->startOfDay();

        $rows = $this->userFor($request)->transactions()
            ->counted()
            ->with('customCategory')
            ->where('date', '>=', $first->toDateString())
            ->where('date', '<', $first->copy()->addMonth()->toDateString())
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->get()
            ->map(fn ($transaction): array => $this->transactionRow($transaction))
            ->values()
            ->all();

        return $this->respond(count($rows).' entr'.(count($rows) === 1 ? 'y' : 'ies').' in '.$month.'.', ['month' => $month, 'transactions' => $rows]);
    }
}
