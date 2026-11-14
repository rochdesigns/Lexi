<?php

namespace App\Models;

use Carbon\Carbon;
use Database\Factories\EventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

/**
 * @property int $id
 * @property int $user_id
 * @property string $title
 * @property Carbon $date
 * @property string $time
 * @property string|null $end_time
 * @property bool $is_all_day
 * @property Carbon|null $end_date
 * @property string $tag
 * @property string $color
 * @property string|null $source
 * @property string|null $recurrence_type
 * @property int $recurrence_interval
 * @property list<string>|null $recurrence_days
 * @property Carbon|null $recurrence_ends_on
 * @property-read string $bg
 *
 * @phpstan-type EventOccurrence array{
 *     id: int,
 *     title: string,
 *     date: string,
 *     time: string,
 *     end_time: string|null,
 *     is_all_day: bool,
 *     start_date: string,
 *     end_date: string|null,
 *     tag: string,
 *     color: string,
 *     bg: string,
 *     is_recurring: bool,
 *     recurrence_type: string|null,
 *     recurrence_interval: int,
 *     recurrence_days: list<string>|null,
 *     recurrence_ends_on: string|null,
 * }
 */
class Event extends Model
{
    /** @use HasFactory<EventFactory> */
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'user_id', 'title', 'date', 'time', 'end_time', 'is_all_day', 'end_date', 'tag', 'color', 'source',
        'recurrence_type', 'recurrence_interval', 'recurrence_days', 'recurrence_ends_on',
    ];

    protected $casts = [
        'recurrence_days' => 'array',
        'date' => 'date',
        'end_date' => 'date',
        'is_all_day' => 'boolean',
        'recurrence_ends_on' => 'date',
    ];

    /**
     * @return BelongsTo<User, $this>
     */
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

    /**
     * @return list<EventOccurrence>
     */
    public static function expandRecurring(Carbon $rangeStart, Carbon $rangeEnd, int $userId): array
    {
        $events = static::where('user_id', $userId)
            ->where(function ($q) use ($rangeStart, $rangeEnd): void {
                $q->whereNull('recurrence_type')
                    ->whereDate('date', '<=', $rangeEnd->toDateString())
                    ->whereDate(DB::raw('COALESCE(end_date, date)'), '>=', $rangeStart->toDateString())
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
                $last = $event->end_date ?? $event->date;

                $day = Carbon::parse($event->date->toDateString());
                $lastDay = Carbon::parse($last->toDateString());

                while ($day->lte($lastDay)) {
                    if ($day->gte($rangeStart->copy()->startOfDay()) && $day->lte($rangeEnd)) {
                        $occurrences[] = self::toOccurrence($event, $day->toDateString());
                    }

                    $day->addDay();
                }
            } else {
                $occurrences = array_merge($occurrences, self::expandEvent($event, $rangeStart, $rangeEnd));
            }
        }

        usort($occurrences, fn ($a, $b) => strcmp($a['date'].($a['is_all_day'] ? '0' : '1').$a['time'], $b['date'].($b['is_all_day'] ? '0' : '1').$b['time']));

        return $occurrences;
    }

    /**
     * @return list<EventOccurrence>
     */
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
                        $occurrences[] = self::toOccurrence($event, $cursor->toDateString());
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
                                $occurrences[] = self::toOccurrence($event, $occDate->toDateString());
                            }
                        }
                        $weekStart->addWeeks($event->recurrence_interval);
                    }
                } else {
                    $cursor = $eventDate->copy();
                    while ($cursor->lte($effectiveEnd)) {
                        if ($cursor->gte($rangeStart)) {
                            $occurrences[] = self::toOccurrence($event, $cursor->toDateString());
                        }
                        $cursor->addWeeks($event->recurrence_interval);
                    }
                }
            })(),
            'monthly' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $cursor = $eventDate->copy();
                while ($cursor->lte($effectiveEnd)) {
                    if ($cursor->gte($rangeStart)) {
                        $occurrences[] = self::toOccurrence($event, $cursor->toDateString());
                    }
                    $cursor->addMonths($event->recurrence_interval);
                }
            })(),
            'yearly' => (function () use ($event, $eventDate, $rangeStart, $effectiveEnd, &$occurrences): void {
                $cursor = $eventDate->copy();
                while ($cursor->lte($effectiveEnd)) {
                    if ($cursor->gte($rangeStart)) {
                        $occurrences[] = self::toOccurrence($event, $cursor->toDateString());
                    }
                    $cursor->addYears($event->recurrence_interval);
                }
            })(),
            default => null,
        };

        return $occurrences;
    }

    /**
     * @return EventOccurrence
     */
    private static function toOccurrence(self $event, string $date): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'date' => $date,
            'time' => $event->time,
            'end_time' => $event->end_time,
            'is_all_day' => $event->is_all_day,
            'start_date' => $event->date->toDateString(),
            'end_date' => $event->end_date?->toDateString(),
            'tag' => $event->tag,
            'color' => $event->color,
            'bg' => $event->bg,
            'is_recurring' => $event->recurrence_type !== null,
            'recurrence_type' => $event->recurrence_type,
            'recurrence_interval' => $event->recurrence_interval,
            'recurrence_days' => $event->recurrence_days,
            'recurrence_ends_on' => $event->recurrence_ends_on?->toDateString(),
        ];
    }
}
