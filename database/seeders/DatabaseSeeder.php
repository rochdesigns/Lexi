<?php

namespace Database\Seeders;

use App\Models\Event;
use App\Models\Habit;
use App\Models\HabitLog;
use App\Models\HealthMetric;
use App\Models\HealthWeek;
use App\Models\Setting;
use App\Models\Task;
use App\Models\TaskSection;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::create([
            'name' => 'Elle Domingo',
            'email' => 'elle@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        Setting::create(['user_id' => $user->id, 'theme' => 'ocean']);

        // Events — today only
        $today = Carbon::today();
        foreach ([
            ['title' => 'Team standup', 'time' => '11:00 PM', 'tag' => 'Work', 'color' => '#7d4bd0'],
            ['title' => 'Lunch with Mia', 'time' => '12:30 PM', 'tag' => 'Personal', 'color' => '#c0512c'],
            ['title' => 'Yoga class', 'time' => '6:00 PM', 'tag' => 'Health', 'color' => '#2f7d5b'],
        ] as $event) {
            Event::create(array_merge($event, ['user_id' => $user->id, 'date' => $today->toDateString()]));
        }

        // Task sections
        $todaySection = TaskSection::create(['user_id' => $user->id, 'label' => 'Today', 'position' => 1]);
        $weekSection = TaskSection::create(['user_id' => $user->id, 'label' => 'This Week', 'position' => 2]);
        $somedaySection = TaskSection::create(['user_id' => $user->id, 'label' => 'Someday', 'position' => 3]);

        // Tasks
        foreach ([
            ['label' => 'Reply to the landlord', 'section_id' => $todaySection->id, 'done' => false],
            ['label' => 'Book a dentist appointment', 'section_id' => $todaySection->id, 'done' => false],
            ['label' => 'Pay the electric bill', 'section_id' => $todaySection->id, 'done' => false],
            ['label' => 'Grocery run', 'section_id' => $todaySection->id, 'done' => true],
            ['label' => 'Finish the Q3 deck', 'section_id' => $weekSection->id, 'done' => false],
            ['label' => 'Renew car insurance quote', 'section_id' => $weekSection->id, 'done' => false],
            ['label' => 'Clean out the garage', 'section_id' => $weekSection->id, 'done' => false],
            ['label' => 'Learn to make sourdough', 'section_id' => $somedaySection->id, 'done' => false],
            ['label' => 'Plan Batangas trip', 'section_id' => $somedaySection->id, 'done' => false],
        ] as $task) {
            Task::create(array_merge($task, ['user_id' => $user->id]));
        }

        // Health metrics
        foreach ([
            ['name' => 'Steps', 'value' => '7,420', 'sub' => 'of 10,000', 'pct' => 74, 'color' => '#2f7d5b', 'icon' => 'directions_walk'],
            ['name' => 'Sleep', 'value' => '7h 10m', 'sub' => 'of 8h', 'pct' => 90, 'color' => '#3f7fb0', 'icon' => 'bedtime'],
            ['name' => 'Active energy', 'value' => '480', 'sub' => 'of 600 cal', 'pct' => 80, 'color' => '#d98a2b', 'icon' => 'local_fire_department'],
            ['name' => 'Resting HR', 'value' => '58', 'sub' => 'bpm · steady', 'pct' => 0, 'color' => '#c0512c', 'icon' => 'favorite'],
        ] as $metric) {
            HealthMetric::create(array_merge($metric, ['user_id' => $user->id]));
        }

        // Health week (Mon–Sun steps)
        foreach ([
            ['day' => 'Mon', 'steps' => 9200],
            ['day' => 'Tue', 'steps' => 6800],
            ['day' => 'Wed', 'steps' => 11400],
            ['day' => 'Thu', 'steps' => 7900],
            ['day' => 'Fri', 'steps' => 7420],
            ['day' => 'Sat', 'steps' => 0],
            ['day' => 'Sun', 'steps' => 0],
        ] as $row) {
            HealthWeek::create(array_merge($row, ['user_id' => $user->id]));
        }

        // Habits + logs
        $habitsData = [
            ['name' => 'Drink water', 'icon' => 'water_drop', 'color' => '#3f7fb0',
                'done_days' => ['Sun', 'Mon', 'Tue', 'Wed']],
            ['name' => 'Read 20 min', 'icon' => 'menu_book', 'color' => '#7d4bd0',
                'done_days' => ['Sun', 'Mon', 'Tue', 'Wed', 'Thu']],
            ['name' => 'Move 30 min', 'icon' => 'directions_run', 'color' => '#d98a2b',
                'done_days' => ['Sun', 'Tue', 'Wed', 'Fri']],
            ['name' => 'Meditate', 'icon' => 'self_improvement', 'color' => '#2f7d5b',
                'done_days' => ['Sun', 'Mon', 'Tue', 'Thu']],
        ];

        $weekStart = $today->copy()->startOfWeek(Carbon::SUNDAY);
        $dayOffsets = ['Sun' => 0, 'Mon' => 1, 'Tue' => 2, 'Wed' => 3, 'Thu' => 4, 'Fri' => 5, 'Sat' => 6];

        foreach ($habitsData as $data) {
            $habit = Habit::create([
                'user_id' => $user->id,
                'name' => $data['name'],
                'icon' => $data['icon'],
                'color' => $data['color'],
            ]);
            foreach ($data['done_days'] as $dayName) {
                HabitLog::create([
                    'habit_id' => $habit->id,
                    'date' => $weekStart->copy()->addDays($dayOffsets[$dayName])->toDateString(),
                    'done' => true,
                ]);
            }
        }
    }
}
