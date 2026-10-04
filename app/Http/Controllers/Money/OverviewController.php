<?php

namespace App\Http\Controllers\Money;

use App\Http\Controllers\Controller;
use App\Http\Requests\Money\RecoveryRequest;
use App\Models\Money\Account;
use App\Models\Money\Bill;
use App\Models\Money\Debt;
use App\Models\Money\IncomeSource;
use App\Services\Money\Projection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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
        $recoveryMonths = $user->setting?->recovery_months;

        return Inertia::render('Money/Overview', [
            'numbers' => $this->projection->overview($debts, $bills, $sources, $today, 0, $recoveryMonths),
            'recovery' => $this->recovery($debts, $recoveryMonths, $today),
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

    /**
     * Recovery mode is offered only while a card carries a balance over.
     *
     * @param  Collection<int, Debt>  $debts
     * @return array{carryover: int, months: int|null, extra: int, options: list<array{months: int, extra: int}>}|null
     */
    private function recovery(Collection $debts, ?int $months, Carbon $today): ?array
    {
        $options = array_map(
            fn (int $option): array => ['months' => $option, 'extra' => $this->projection->recoveryPlan($debts, $option, $today)['extra']],
            RecoveryRequest::MONTH_OPTIONS,
        );
        $carryover = $this->projection->recoveryPlan($debts, RecoveryRequest::MONTH_OPTIONS[0], $today)['carryover'];

        if ($carryover === 0) {
            return null;
        }

        return [
            'carryover' => $carryover,
            'months' => $months,
            'extra' => $months === null ? 0 : $this->projection->recoveryPlan($debts, $months, $today)['extra'],
            'options' => $options,
        ];
    }
}
