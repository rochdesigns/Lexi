<?php

namespace App\Services;

use App\Contracts\Envelopable;
use App\Models\BudgetCategory;
use App\Models\Debt;
use App\Models\RecurringBill;
use App\Models\SinkingFund;
use App\Support\CyclePeriod;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

class EnvelopeActivityService
{
    public function activity(Envelopable $envelope, CyclePeriod $cycle): float
    {
        return match ($envelope::class) {
            Debt::class => $this->sumRelation($envelope->transactions(), $cycle, 'expense'),
            SinkingFund::class => $this->sumRelation($envelope->transactions(), $cycle, 'transfer'),
            RecurringBill::class => $this->billActivity($envelope, $cycle),
            BudgetCategory::class => $this->categoryActivity($envelope, $cycle),
            default => throw new InvalidArgumentException('Unsupported envelope type: '.$envelope::class),
        };
    }

    private function sumRelation(HasMany $relation, CyclePeriod $cycle, string $type): float
    {
        return round((float) $relation
            ->whereDate('transacted_at', '>=', $cycle->start)
            ->whereDate('transacted_at', '<=', $cycle->end)
            ->where('type', $type)
            ->sum('amount'), 2);
    }

    private function billActivity(RecurringBill $bill, CyclePeriod $cycle): float
    {
        if ($bill->budget_category_id === null) {
            return 0.0;
        }

        return round((float) $bill->user->transactions()
            ->where('budget_category_id', $bill->budget_category_id)
            ->whereNull('debt_id')
            ->where('type', 'expense')
            ->whereDate('transacted_at', '>=', $cycle->start)
            ->whereDate('transacted_at', '<=', $cycle->end)
            ->sum('amount'), 2);
    }

    private function categoryActivity(BudgetCategory $category, CyclePeriod $cycle): float
    {
        return round((float) $category->transactions()
            ->whereNull('debt_id')
            ->where('type', 'expense')
            ->whereDate('transacted_at', '>=', $cycle->start)
            ->whereDate('transacted_at', '<=', $cycle->end)
            ->sum('amount'), 2);
    }
}
