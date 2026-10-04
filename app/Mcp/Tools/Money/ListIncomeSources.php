<?php

namespace App\Mcp\Tools\Money;

use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListIncomeSources extends MoneyTool
{
    protected string $name = 'list_income_sources';

    protected string $description = 'List the user\'s income sources (salary, side income) with amount per pay day, schedule, pay days of the month, and anything received in the last 7 days. Use this for questions about when and how much the user gets paid.';

    public function handle(Request $request): ResponseFactory
    {
        $sources = $this->userFor($request)->incomeSources()->with('receipts')->orderBy('name')->get();

        $rows = $sources->map(fn ($source): array => [
            'id' => $source->id,
            'name' => $source->name,
            'amount' => $source->amount,
            'schedule' => $source->schedule->value,
            'payDays' => $source->payDays(),
            'recentReceipts' => $source->receipts->map(fn ($receipt): array => [
                'date' => $receipt->date->toDateString(),
                'amount' => $receipt->amount,
            ])->values()->all(),
        ])->values()->all();

        return $this->respond(count($rows).' income source(s).', ['incomeSources' => $rows]);
    }
}
