<?php

namespace App\Support;

use Carbon\Carbon;

final class CyclePeriod
{
    public function __construct(
        public readonly Carbon $start,
        public readonly Carbon $end,
    ) {}

    public function isCalendarMonth(): bool
    {
        return $this->start->day === 1
            && $this->end->isSameDay($this->start->copy()->endOfMonth());
    }

    public function label(): string
    {
        if ($this->isCalendarMonth()) {
            return $this->start->format('F Y');
        }

        if ($this->start->year === $this->end->year) {
            return $this->start->format('M j').' – '.$this->end->format('M j, Y');
        }

        return $this->start->format('M j, Y').' – '.$this->end->format('M j, Y');
    }

    public function containsToday(): bool
    {
        $today = Carbon::now()->startOfDay();

        return $today->between($this->start->copy()->startOfDay(), $this->end->copy()->startOfDay());
    }

    /** @return array{start: string, end: string, label: string} */
    public function toArray(): array
    {
        return [
            'start' => $this->start->toDateString(),
            'end' => $this->end->toDateString(),
            'label' => $this->label(),
        ];
    }
}
