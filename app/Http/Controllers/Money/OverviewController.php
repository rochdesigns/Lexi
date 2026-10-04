<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\IncomeSource;
use App\Services\Money\Projection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class OverviewController extends Controller
{
    public function __construct(private Projection $projection) {}

    public function __invoke(Request $request): Response
    {
        $today = Carbon::today();
        $user = $request->user();
        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
        $bills = $user->bills()->orderBy('name')->get();
        $sources = $user->incomeSources()->orderBy('name')->get();
        $accounts = $user->accounts()->orderBy('name')->get();

        return Inertia::render('Money/Overview', [
            'numbers' => $this->projection->overview($debts, $bills, $sources, $today),
            'cashAvailable' => $this->projection->cashAvailable($accounts),
            'shortfalls' => $this->projection->shortfalls($debts, $bills, $sources, $accounts, $today),
            'upcoming' => $this->projection->upcoming($debts, $bills, $sources, $today),
            'accounts' => $accounts->map(fn (Account $account): array => [
                'id' => $account->id,
                'name' => $account->name,
                'type' => $account->type->value,
                'balance' => $account->balance,
                'isSpendable' => $account->is_spendable,
            ])->values(),
            'incomeSources' => $sources->map(fn (IncomeSource $source): array => [
                'id' => $source->id,
                'name' => $source->name,
                'amount' => $source->amount,
                'schedule' => $source->schedule->value,
                'payDay1' => $source->pay_day_1,
                'payDay2' => $source->pay_day_2,
            ])->values(),
            'bills' => $bills->map(fn (Bill $bill): array => [
                'id' => $bill->id,
                'name' => $bill->name,
                'amount' => $bill->amount,
                'frequency' => $bill->frequency->value,
                'dueDay' => $bill->due_day,
                'dueMonth' => $bill->due_month,
                'autopay' => $bill->autopay,
            ])->values(),
        ]);
    }
}
