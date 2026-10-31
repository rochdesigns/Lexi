<?php

namespace App\Mcp\Tools\Money;

use App\Http\Requests\Money\RecoveryRequest;
use App\Services\Money\Projection;
use App\Services\Money\SpendingLimits;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class GetMoneyOverview extends MoneyTool
{
    protected string $name = 'get_money_overview';

    protected string $description = 'The user\'s money at a glance: monthly income, expenses, set-asides, debt payments and safe-to-spend (total and per day), cash available, upcoming bills and pay days for the next 14 days, projected shortfalls over the next 30 days, carried credit-card balance alerts, and this month\'s spending limits per category with what has been spent against each (left is negative when over). Start here for questions about cash flow, what is safe to spend, or whether the user will run short.';

    public function handle(Request $request, Projection $projection, SpendingLimits $spendingLimits): ResponseFactory
    {
        $today = Carbon::today();
        $user = $this->userFor($request);
        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments', 'balanceSnapshots'])->get();
        $bills = $user->bills()->orderBy('name')->get();
        $sources = $user->incomeSources()->with('receipts')->orderBy('name')->get();
        $accounts = $user->accounts()->orderBy('name')->get();
        $recoveryMonths = $user->setting?->recovery_months;
        $contributions = $projection->goalContributions($user->goals()->with('account')->get(), $debts, $bills, $today);

        $numbers = $projection->overview($debts, $bills, $sources, $today, $contributions['buffer'], $recoveryMonths, $contributions['sinking'], $projection->spentThisMonth($user, $today));
        $carryover = $projection->recoveryPlan($debts, RecoveryRequest::MONTH_OPTIONS[0], $today)['carryover'];

        return $this->respond(
            'Safe to spend this month: '.$numbers['safeToSpend'].' centavos ('.$numbers['perDay'].' per day).',
            [
                'numbers' => $numbers,
                'carryAlerts' => $projection->carriedBalanceAlerts($debts, $today),
                'recovery' => $carryover === 0 ? null : [
                    'carryover' => $carryover,
                    'months' => $recoveryMonths,
                    'extraPerMonth' => $recoveryMonths === null ? 0 : $projection->recoveryPlan($debts, $recoveryMonths, $today)['extra'],
                ],
                'cashAvailable' => $projection->cashAvailable($accounts),
                'shortfalls' => $projection->shortfalls($debts, $bills, $sources, $accounts, $today),
                'upcoming' => $projection->upcoming($debts, $bills, $sources, $today),
                'spendingLimits' => array_values(array_map(
                    fn (array $row): array => [
                        'category' => $row['categoryName'] ?? $row['category'],
                        'limit' => $row['limit'],
                        'spent' => $row['spent'],
                        'left' => $row['left'],
                        'pace' => $row['pace'],
                        'status' => $row['status'],
                    ],
                    array_filter($spendingLimits->forMonth($user, $today), fn (array $row): bool => $row['limit'] !== null),
                )),
            ],
        );
    }
}
