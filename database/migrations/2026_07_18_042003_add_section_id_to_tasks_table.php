<?php

use App\Models\Task;
use App\Models\TaskSection;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('section_id')->nullable()->constrained('task_sections')->cascadeOnDelete();
        });

        // Seed default sections for every existing user and map tasks to them.
        $defaults = [
            ['key' => 'today', 'label' => 'Today', 'position' => 1],
            ['key' => 'week', 'label' => 'This Week', 'position' => 2],
            ['key' => 'someday', 'label' => 'Someday', 'position' => 3],
        ];

        User::query()->each(function (User $user) use ($defaults): void {
            $sectionIds = [];
            foreach ($defaults as $default) {
                $section = TaskSection::create([
                    'user_id' => $user->id,
                    'label' => $default['label'],
                    'position' => $default['position'],
                ]);
                $sectionIds[$default['key']] = $section->id;
            }

            $user->tasks()->each(function (Task $task) use ($sectionIds): void {
                $group = $task->getAttribute('group');
                $key = is_string($group) ? $group : 'today';
                $task->update(['section_id' => $sectionIds[$key] ?? $sectionIds['today']]);
            });
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->foreignId('section_id')->nullable(false)->change();
            $table->dropColumn('group');
        });
    }

    public function down(): void
    {
        Schema::table('tasks', function (Blueprint $table): void {
            $table->string('group')->default('today');
        });

        // Restore group values from section label
        DB::table('tasks')
            ->join('task_sections', 'tasks.section_id', '=', 'task_sections.id')
            ->each(function ($row): void {
                $key = match ($row->label) {
                    'Today' => 'today',
                    'This Week' => 'week',
                    'Someday' => 'someday',
                    default => 'today',
                };
                DB::table('tasks')
                    ->where('id', $row->id)
                    ->update(['group' => $key]);
            });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropForeign(['section_id']);
            $table->dropColumn('section_id');
        });
    }
};
