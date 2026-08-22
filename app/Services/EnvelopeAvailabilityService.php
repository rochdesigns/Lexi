<?php

namespace App\Services;

use App\Contracts\Envelopable;
use App\Models\EnvelopeAssignment;
use App\Support\CyclePeriod;
use Carbon\Carbon;

class EnvelopeAvailabilityService
{
    public function __construct(
        private readonly EnvelopeService $envelopes,
        private readonly EnvelopeActivityService $activity,
    ) {}

    public function available(Envelopable $envelope, BudgetCycle $budgetCycle, CyclePeriod $target): float
    {
        $walk = $this->walk($envelope, $budgetCycle, $target);

        return round($walk['assigned'] - $walk['activity'], 2);
    }

    public function assignedThroughCycle(Envelopable $envelope, BudgetCycle $budgetCycle, CyclePeriod $target): float
    {
        return $this->walk($envelope, $budgetCycle, $target)['assigned'];
    }

    /** @return array{assigned: float, activity: float} */
    private function walk(Envelopable $envelope, BudgetCycle $budgetCycle, CyclePeriod $target): array
    {
        $cycle = $this->epoch($envelope, $budgetCycle);

        if ($target->start->lessThan($cycle->start)) {
            return ['assigned' => 0.0, 'activity' => 0.0];
        }

        $assigned = 0.0;
        $activity = 0.0;

        while (true) {
            $assigned += $this->assignedOrDefault($envelope, $cycle);
            $activity += $this->activity->activity($envelope, $cycle);

            if ($cycle->start->isSameDay($target->start)) {
                break;
            }

            $cycle = $budgetCycle->next($cycle);
        }

        return ['assigned' => round($assigned, 2), 'activity' => round($activity, 2)];
    }

    private function epoch(Envelopable $envelope, BudgetCycle $budgetCycle): CyclePeriod
    {
        $earliest = EnvelopeAssignment::query()->whereMorphedTo('envelope', $envelope)->min('cycle_start');

        if ($earliest === null) {
            return $budgetCycle->containing(Carbon::instance($envelope->created_at));
        }

        return $budgetCycle->containing(Carbon::parse($earliest));
    }

    private function assignedOrDefault(Envelopable $envelope, CyclePeriod $cycle): float
    {
        $row = EnvelopeAssignment::query()
            ->whereMorphedTo('envelope', $envelope)
            ->whereDate('cycle_start', $cycle->start->toDateString())
            ->first();

        return $row !== null ? (float) $row->assigned : $envelope->defaultAssignedAmount();
    }
}
