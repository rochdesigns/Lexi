<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'title', 'date', 'time', 'tag', 'color',
        'recurrence_type', 'recurrence_interval', 'recurrence_days', 'recurrence_ends_on',
    ];

    protected $casts = [
        'recurrence_days' => 'array',
        'date' => 'date',
        'recurrence_ends_on' => 'date',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getBgAttribute(): string
    {
        return match ($this->color) {
            '#2f7d5b' => '#dff3e6',
            '#3f7fb0' => '#dcebf5',
            '#a85fc0' => '#efe0f5',
            '#d98a2b' => '#f8ecd6',
            '#c0512c' => '#fbe2d6',
            default => '#eee9dd',
        };
    }

    public static function expandRecurring(Carbon $rangeStart, Carbon $rangeEnd, int $userId): array
    {
        $events = static::where('user_id', $userId)
            ->where(function ($q) use ($rangeStart, $rangeEnd): void {
                $q->whereNull('recurrence_type')
                    ->whereDate('date', '>=', $rangeStart->toDateString())
                    ->whereDate('date', '<=', $rangeEnd->toDateString())
                    ->orWhere(function ($q2) use ($rangeStart, $rangeEnd): void {
                        $q2->whereNotNull('recurrence_type')
                            ->whereDate('date', '<=', $rangeEnd->toDateString())
                            ->where(function ($q3) use ($rangeStart): void {
                                $q3->whereNull('recurrence_ends_on')
                                    ->orWhereDate('recurrence_ends_on', '>=', $rangeStart->toDateString());
                            });
                    });
            })
            ->get();

        $occurrences = [];

        foreach ($events as $event) {
            if ($event->recurrence_type === null) {
                $occurrences[] = static::toOccurrence($event, $event->date->toDateString());
            } else {
                $occurrences = array_merge($occurrences, static::expandEvent($event, $rangeStart, $rangeEnd));
            }
        }

        usort($occurrences, fn ($a, $b) => strcmp($a['date'].$a['time'], $b['date'].$b['time']));

        return $occurrences;
    }

    private static function expandEvent(self $event, Carbon $rangeStart, Carbon $rangeEnd): array
    {
        $occurrences = [];
        $endsOn = $event->recurrence_ends_on;
        $effectiveEnd = $endsOn && $endsOn->lt($rangeEnd) ? $endsOn : $rangeEnd;
        $eventDate = Carbon::parse($event->date->toDateString());

        match ($event->recurrence_type) {
            'daily' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $cursor = $eventDate->copy();
                while ($cursor->lte($effectiveEnd)) {
                    if ($cursor->gte($rangeStart)) {
                        $occurrences[] = static::toOccurrence($event, $cursor->toDateString());
                    }
                    $cursor->addDays($event->recurrence_interval);
                }
            })(),
            'weekly' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $dayMap = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];
                if (! empty($event->recurrence_days)) {
                    $weekStart = $eventDate->copy()->startOfWeek(Carbon::SUNDAY);
                    while ($weekStart->lte($effectiveEnd)) {
                        foreach ($event->recurrence_days as $dow) {
                            $offset = $dayMap[$dow] ?? null;
                            if ($offset === null) {
                                continue;
                            }
                            $occDate = $weekStart->copy()->addDays($offset);
                            if ($occDate->gte($eventDate) && $occDate->gte($rangeStart) && $occDate->lte($effectiveEnd)) {
                                $occurrences[] = static::toOccurrence($event, $occDate->toDateString());
                            }
                        }
                        $weekStart->addWeeks($event->recurrence_interval);
                    }
                } else {
                    $cursor = $eventDate->copy();
                    while ($cursor->lte($effectiveEnd)) {
                        if ($cursor->gte($rangeStart)) {
                            $occurrences[] = static::toOccurrence($event, $cursor->toDateString());
                        }
                        $cursor->addWeeks($event->recurrence_interval);
                    }
                }
            })(),
            'monthly' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $cursor = $eventDate->copy();
                while ($cursor->lte($effectiveEnd)) {
                    if ($cursor->gte($rangeStart)) {
                        $occurrences[] = static::toOccurrence($event, $cursor->toDateString());
                    }
                    $cursor->addMonths($event->recurrence_interval);
                }
            })(),
            'yearly' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $cursor = $eventDate->copy();
                while ($cursor->lte($effectiveEnd)) {
                    if ($cursor->gte($rangeStart)) {
                        $occurrences[] = static::toOccurrence($event, $cursor->toDateString());
                    }
                    $cursor->addYears($event->recurrence_interval);
                }
            })(),
            default => null,
        };

        return $occurrences;
    }

    private static function toOccurrence(self $event, string $date): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'date' => $date,
            'time' => $event->time,
            'tag' => $event->tag,
            'color' => $event->color,
            'bg' => $event->bg,
            'is_recurring' => $event->recurrence_type !== null,
            'recurrence_type' => $event->recurrence_type,
        ];
    }
}
