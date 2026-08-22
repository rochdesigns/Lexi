<?php

namespace App\Services;

use App\Models\User;

class ReadyToAssignService
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly EnvelopeAvailabilityService $availability,
    ) {}

    public function compute(User $user): float
    {
        $budgetCycle = new BudgetCycle($user->setting);
        $current = $budgetCycle->current();

        $onBudgetBalance = (float) $user->accounts()->whereIn('type', ['cash', 'credit'])->sum('balance');

        $totalAssigned = $this->envelopes->assignableEnvelopes($user)
            ->sum(fn ($envelope) => $this->availability->assignedThroughCycle($envelope, $budgetCycle, $current));

        return round($onBudgetBalance - $totalAssigned, 2);
    }
}
