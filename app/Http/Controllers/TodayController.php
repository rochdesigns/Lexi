<?php

namespace App\Http\Controllers;

use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HealthMetric;
use App\Models\Task;
use App\Models\TaskSection;
use App\Models\VisionBoardItem;
use App\Services\Money\Projection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TodayController extends Controller
{
    public function __invoke(Request $request, Projection $projection): Response
    {
        $user = $request->user();
        $today = Carbon::today();

        $todayStart = $today->copy()->startOfDay();
        $todayEnd = $today->copy()->endOfDay();
        $todayEvents = collect(Event::expandRecurring($todayStart, $todayEnd, $user->id))
            ->sortBy('time')
            ->values();

        $firstSection = TaskSection::where('user_id', $user->id)
            ->orderBy('position')
            ->first();

        $todayTasks = $firstSection
            ? Task::where('section_id', $firstSection->id)
                ->orderBy('done')
                ->orderBy('id')
                ->get(['id', 'label', 'section_id', 'done'])
            : collect();

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

        $visionBoardItems = VisionBoardItem::where('user_id', $user->id)
            ->orderBy('position')
            ->get()
            ->map(fn (VisionBoardItem $item): array => [
                'id' => $item->id,
                'imageUrl' => $item->temporaryImageUrl(),
                'caption' => $item->caption,
            ]);

        $firstName = explode(' ', $user->name)[0];

        $debts = $user->debts()->with(['cardDetail', 'paymentPlans.debt', 'payments'])->get();
        $bills = $user->bills()->get();
        $sources = $user->incomeSources()->get();
        $contributions = $projection->goalContributions($user->goals()->with('account')->get(), $debts, $bills, $today);
        $moneySummary = $sources->isEmpty() && $bills->isEmpty() ? null : [
            'safeToSpend' => $projection->overview($debts, $bills, $sources, $today, $contributions['buffer'], $user->setting?->recovery_months, $contributions['sinking'])['safeToSpend'],
            'dueSoonCount' => collect($projection->upcoming($debts, $bills, $sources, $today))->where('kind', '!=', 'income')->count(),
        ];

        return Inertia::render('Today', [
            'user' => [
                'name' => $user->name,
                'firstName' => $firstName,
                'theme' => $user->setting->theme ?? 'ocean',
            ],
            'todayEvents' => $todayEvents,
            'todayTasks' => $todayTasks,
            'habits' => $habits,
            'healthMetrics' => $healthMetrics,
            'visionBoardItems' => $visionBoardItems,
            'moneySummary' => $moneySummary,
        ]);
    }
}
