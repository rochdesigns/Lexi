<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::transaction(function () use ($now): void {
            DB::table('goals')->orderBy('id')->get()->each(function (object $goal) use ($now): void {
                $maxPosition = (int) DB::table('sinking_funds')->where('user_id', $goal->user_id)->max('position');

                DB::table('sinking_funds')->insert([
                    'user_id' => $goal->user_id,
                    'name' => $goal->name,
                    'icon' => $goal->icon,
                    'color' => $goal->color,
                    'bg' => $goal->bg,
                    'saved' => $goal->saved,
                    'target' => $goal->target,
                    'per_month' => $goal->per_month,
                    'due_date' => $goal->by_date,
                    'position' => $maxPosition + 1,
                    'migrated_goal_id' => $goal->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            DB::table('sinking_funds')->whereNotNull('migrated_goal_id')->delete();
        });
    }
};
