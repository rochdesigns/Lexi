<?php

namespace App\Services;

use App\Models\User;
use App\Support\CyclePeriod;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

final class UpcomingBills
{
    /** @return Collection<int, array<string, mixed>> */
    public function within(User $user, CyclePeriod $cycle, int $days = 7): Collection
    {
        $today = Carbon::now()->startOfDay();
        $cycleStart = $cycle->start;
        $cycleEnd = $cycle->end;

        $makeDueLabel = function (CarbonInterface $dueDate) use ($today): array {
            $daysUntil = (int) $today->diffInDays($dueDate, false);
            $label = match (true) {
                $daysUntil < 0 => 'Past due',
                $daysUntil === 0 => 'Due today',
                $daysUntil === 1 => 'Due tomorrow',
                default => "Due in {$daysUntil} days",
            };

            return ['label' => $label, 'daysUntil' => $daysUntil];
        };

        $sinkingFundBills = $user->sinkingFunds()
            ->whereNotNull('due_date')
            ->where('due_date', '>=', $today->toDateString())
            ->get()
            ->map(function ($sf) use ($makeDueLabel) {
                $due = $makeDueLabel(Carbon::parse($sf->due_date)->startOfDay());

                return [
                    'id' => 'sf-'.$sf->id, 'name' => $sf->name, 'icon' => $sf->icon,
                    'color' => $sf->color, 'bg' => $sf->bg, 'amount' => (float) $sf->target,
                    'dueLabel' => $due['label'], 'daysUntil' => $due['daysUntil'], 'type' => 'set_aside',
                ];
            })
            ->filter(fn ($b) => $b['daysUntil'] <= $days);

        $recurringBillItems = $user->recurringBills()
            ->with('budgetCategory')
            ->orderBy('day_of_month')
            ->get()
            ->map(function ($bill) use ($cycleStart, $cycleEnd, $makeDueLabel) {
                $day = $bill->day_of_month;
                $dueDate = null;
                $checkMonth = $cycleStart->copy()->startOfMonth();

                for ($i = 0; $i < 2; $i++) {
                    $candidate = $checkMonth->copy()->setDay(min($day, (int) $checkMonth->copy()->endOfMonth()->day));
                    if ($candidate->between($cycleStart, $cycleEnd)) {
                        $dueDate = $candidate;
                        break;
                    }
                    $checkMonth = $checkMonth->addMonthNoOverflow();
                }

                if (! $dueDate || ($bill->last_paid_at && $bill->last_paid_at->greaterThanOrEqualTo($cycleStart))) {
                    return null;
                }

                $due = $makeDueLabel($dueDate);

                return [
                    'id' => 'rb-'.$bill->id, 'name' => $bill->name, 'icon' => $bill->icon,
                    'color' => $bill->color, 'bg' => $bill->bg, 'amount' => (float) $bill->amount,
                    'dueLabel' => $due['label'], 'daysUntil' => $due['daysUntil'], 'type' => 'recurring',
                    'budget_category_id' => $bill->budget_category_id,
                    'category' => $bill->budgetCategory ? [
                        'id' => $bill->budgetCategory->id,
                        'name' => $bill->budgetCategory->name,
                        'color' => $bill->budgetCategory->color,
                    ] : null,
                ];
            })
            ->filter()
            ->filter(fn ($b) => $b['daysUntil'] <= $days)
            ->values();

        return $sinkingFundBills->concat($recurringBillItems)->sortBy('daysUntil')->values();
    }
}
