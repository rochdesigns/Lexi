<?php

namespace App\Services;

use App\Contracts\Envelopable;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\RecurringBill;
use App\Models\SinkingFund;
use App\Models\User;
use Illuminate\Support\Collection;
use InvalidArgumentException;

class EnvelopeService
{
    /** @return Collection<int, Envelopable> */
    public function assignableEnvelopes(User $user): Collection
    {
        $claimedCategoryIds = $user->debts()->whereNotNull('budget_category_id')->pluck('budget_category_id')
            ->merge($user->recurringBills()->whereNotNull('budget_category_id')->pluck('budget_category_id'))
            ->unique();

        return collect()
            ->merge($user->budgetCategories()->whereNotIn('id', $claimedCategoryIds)->get())
            ->merge($user->debts()->get())
            ->merge($user->recurringBills()->get())
            ->merge($user->sinkingFunds()->get());
    }

    public function envelopeType(Envelopable $envelope): string
    {
        return match ($envelope::class) {
            BudgetCategory::class => 'budget_category',
            Debt::class => 'debt',
            RecurringBill::class => 'recurring_bill',
            SinkingFund::class => 'sinking_fund',
            default => throw new InvalidArgumentException('Unsupported envelope type: '.$envelope::class),
        };
    }
}
