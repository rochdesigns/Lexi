<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\CyclePeriod;
use Carbon\Carbon;
use Carbon\CarbonImmutable;

final class BudgetCycle
{
    public function __construct(private readonly ?Setting $setting) {}

    public function current(): CyclePeriod
    {
        return $this->containing(Carbon::now());
    }

    public function containing(Carbon|CarbonImmutable $date): CyclePeriod
    {
        if ($this->setting === null) {
            return $this->calendarMonthContaining($date);
        }

        return match ($this->setting->cycle_type) {
            'monthly' => $this->monthlyContaining($date),
            default => $this->fixedDaysContaining($date),
        };
    }

    public function next(CyclePeriod $period): CyclePeriod
    {
        return $this->containing($period->end->copy()->addDay());
    }

    public function previous(CyclePeriod $period): CyclePeriod
    {
        return $this->containing($period->start->copy()->subDay());
    }

    private function fixedDaysContaining(Carbon|CarbonImmutable $date): CyclePeriod
    {
        $anchor = ($this->setting->budget_cycle_anchor ?? Carbon::parse($this->setting->created_at))->copy()->startOfDay();
        $target = $date->copy()->startOfDay();
        $days = $this->setting->budget_cycle_days;

        $diffDays = (int) round(($target->timestamp - $anchor->timestamp) / 86400);
        $index = (int) floor($diffDays / $days);

        $start = $anchor->copy()->addDays($index * $days);
        $end = $start->copy()->addDays($days - 1);

        return new CyclePeriod($start, $end);
    }

    /**
     * A calendar-month-anchored cycle: it starts on $startDay of each month (clamped to
     * that month's last day when $startDay doesn't exist, e.g. day 31 in a 30-day month)
     * and ends the day before the next month's (independently clamped) start day. Every
     * boundary is derived fresh from the year/month containing $date rather than by
     * rolling forward from a previous cycle's start, so a short-month clamp (e.g. Feb 28
     * for a day-31 anchor) never permanently drags later months down with it.
     */
    private function monthlyContaining(Carbon|CarbonImmutable $date): CyclePeriod
    {
        $startDay = $this->setting->monthly_start_day ?? 1;
        $date = $date->copy()->startOfDay();
        $year = (int) $date->year;
        $month = (int) $date->month;

        $start = $this->monthlyBoundary($year, $month, $startDay);

        if ($date->lt($start)) {
            [$year, $month] = $this->addMonths($year, $month, -1);
            $start = $this->monthlyBoundary($year, $month, $startDay);
        }

        [$nextYear, $nextMonth] = $this->addMonths($year, $month, 1);
        $nextStart = $this->monthlyBoundary($nextYear, $nextMonth, $startDay);

        return new CyclePeriod($start, $nextStart->copy()->subDay());
    }

    private function monthlyBoundary(int $year, int $month, int $startDay): Carbon
    {
        $day = min($startDay, Carbon::create($year, $month, 1)->daysInMonth);

        return Carbon::create($year, $month, $day)->startOfDay();
    }

    /** @return array{int, int} */
    private function addMonths(int $year, int $month, int $delta): array
    {
        $month += $delta;

        while ($month > 12) {
            $month -= 12;
            $year++;
        }

        while ($month < 1) {
            $month += 12;
            $year--;
        }

        return [$year, $month];
    }

    private function calendarMonthContaining(Carbon|CarbonImmutable $date): CyclePeriod
    {
        return new CyclePeriod($date->copy()->startOfMonth(), $date->copy()->endOfMonth());
    }
}
