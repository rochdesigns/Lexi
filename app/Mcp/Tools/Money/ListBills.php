<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Projection;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListBills extends MoneyTool
{
    protected string $name = 'list_bills';

    protected string $description = 'List the user\'s recurring bills (monthly or annual) with amount, due day, autopay flag and the next due date. Use this for questions about what bills are coming up or what the user owes each month.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $today = Carbon::today();
        $bills = $this->userFor($request)->bills()->orderBy('name')->get();

        $rows = $bills->map(fn ($bill): array => [
            'id' => $bill->id,
            'name' => $bill->name,
            'amount' => $bill->amount,
            'frequency' => $bill->frequency->value,
            'dueDay' => $bill->due_day,
            'dueMonth' => $bill->due_month,
            'autopay' => $bill->autopay,
            'nextDueDate' => $projection->nextBillDate($bill, $today)->toDateString(),
        ])->values()->all();

        return $this->respond(count($rows).' bill(s).', ['bills' => $rows]);
    }
}
