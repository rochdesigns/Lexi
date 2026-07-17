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
        $weekStart = $today->copy()->startOfWeek(Carbon::SUNDAY);

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

        $habits = Habit::where('user_id', $user->id)->get()->map(function (Habit $habit) use ($weekStart): array {
            $logsThisWeek = HabitLog::where('habit_id', $habit->id)
                ->whereBetween('date', [$weekStart->toDateString(), $weekStart->copy()->addDays(6)->toDateString()])
                ->where('done', true)
                ->count();

            $pct = (int) round(($logsThisWeek / 7) * 100);

            // Streak: consecutive days done ending today (working backwards)
            $streak = 0;
            $checkDate = Carbon::today();
            while (HabitLog::where('habit_id', $habit->id)->whereDate('date', $checkDate)->where('done', true)->exists()) {
                $streak++;
                $checkDate->subDay();
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
