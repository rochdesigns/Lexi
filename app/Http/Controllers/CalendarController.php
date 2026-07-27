<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CalendarController extends Controller
{
    public function index(Request $request): Response
    {
        $month = $request->query('month', Carbon::now()->format('Y-m'));

        [$year, $mon] = explode('-', $month);
        $firstOfMonth = Carbon::createFromDate((int) $year, (int) $mon, 1)->startOfDay();

        // Grid range: Sunday of first week through Saturday of last week
        $rangeStart = $firstOfMonth->copy()->startOfWeek(Carbon::SUNDAY);
        $rangeEnd = $firstOfMonth->copy()->endOfMonth()->endOfWeek(Carbon::SATURDAY);

        $events = Event::expandRecurring($rangeStart, $rangeEnd, $request->user()->id);

        return Inertia::render('Calendar', [
            'events' => $events,
            'currentMonth' => $month,
        ]);
    }
}
