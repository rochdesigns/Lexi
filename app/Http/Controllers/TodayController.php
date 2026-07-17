<?php

namespace App\Http\Controllers;

use App\Models\BudgetMonth;
use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HealthMetric;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $user = $request->user();
        $today = Carbon::today();

        $todayEvents = Event::where('user_id', $user->id)
            ->whereDate('date', $today)
            ->orderBy('time')
            ->get(['id', 'title', 'time', 'tag', 'color']);

        $todayTasks = Task::where('user_id', $user->id)
            ->where('group', 'today')
            ->orderBy('done')
            ->orderBy('id')
            ->get(['id', 'label', 'group', 'done']);

        $budgetMonth = BudgetMonth::where('user_id', $user->id)
            ->where('year', $today->year)
            ->where('month', $today->month)
            ->first();

        $moneySummary = $budgetMonth ? [
            'safeToSpend' => (float) ($budgetMonth->budget - $budgetMonth->base_spent),
            'budget' => (float) $budgetMonth->budget,
            'spent' => (float) $budgetMonth->base_spent,
        ] : ['safeToSpend' => 0, 'budget' => 0, 'spent' => 0];

        $habits = Habit::where('user_id', $user->id)->get();

        $allDoneLogs = HabitLog::whereIn('habit_id', $habits->pluck('id'))
            ->where('done', true)
            ->orderBy('date', 'desc')
            ->get(['habit_id', 'date'])
            ->groupBy('habit_id');

        $habits = $habits->map(function (Habit $habit) use ($today, $allDoneLogs): array {
            $logs = $allDoneLogs->get($habit->id, collect());

            // pct: done days in last 7
            $weekAgo = $today->copy()->subDays(6);
            $doneDays = $logs->filter(fn ($l) => $l->date >= $weekAgo->toDateString() && $l->date <= $today->toDateString())->count();
            $pct = (int) round($doneDays / 7 * 100);

            // streak: consecutive done days ending today
            $doneDates = $logs->pluck('date')->map(fn ($d) => (string) $d)->flip();
            $streak = 0;
            $check = $today->copy();
            while ($doneDates->has($check->toDateString())) {
                $streak++;
                $check->subDay();
            }

            return [
                'id' => $habit->id,
                'name' => $habit->name,
                'icon' => $habit->icon,
                'color' => $habit->color,
                'pct' => $pct,
                'streak' => $streak,
            ];
        });

        $healthMetrics = HealthMetric::where('user_id', $user->id)
            ->whereIn('name', ['Steps', 'Sleep'])
            ->get(['name', 'value', 'sub', 'pct', 'color', 'icon']);

        $firstName = explode(' ', $user->name)[0];

        return Inertia::render('Today', [
            'user' => [
                'name' => $user->name,
                'firstName' => $firstName,
                'theme' => $user->setting?->theme ?? 'ocean',
            ],
            'todayEvents' => $todayEvents,
            'todayTasks' => $todayTasks,
            'moneySummary' => $moneySummary,
            'habits' => $habits,
            'healthMetrics' => $healthMetrics,
        ]);
    }
}
