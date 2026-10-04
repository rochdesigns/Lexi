<?php

namespace App\Mcp\Tools\Money;

use App\Services\Money\Projection;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListGoals extends MoneyTool
{
    protected string $name = 'list_goals';

    protected string $description = 'List the user\'s emergency buffer and savings goals (sinking funds) with target, amount saved, monthly contribution and progress. Use this for questions about savings and how close the user is to a goal.';

    public function handle(Request $request, Projection $projection): ResponseFactory
    {
        $today = Carbon::today();
        $user = $this->userFor($request);
        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
        $bills = $user->bills()->orderBy('name')->get();
        $goals = $user->goals()->with('account')->orderBy('name')->get();
        $obligations = $projection->monthlyObligations($debts, $bills, $today);

        $rows = $goals->map(fn ($goal): array => [
            ...Arr::only(
                $projection->goalProgress($goal, $projection->goalTarget($goal, $obligations), $today),
                ['saved', 'target', 'remaining', 'percent', 'monthsToGo', 'finishMonth', 'suggestedContribution'],
            ),
            'id' => $goal->id,
            'kind' => $goal->kind->value,
            'name' => $goal->name,
            'monthlyContribution' => $goal->monthly_contribution,
            'accountName' => $goal->account?->name,
            'bufferMonths' => $goal->buffer_months,
            'targetAmount' => $goal->target_amount,
            'targetDate' => $goal->target_date?->toDateString(),
        ])->values()->all();

        return $this->respond(count($rows).' goal(s).', ['goals' => $rows]);
    }
}
