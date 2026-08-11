<?php

use App\Models\Goal;
use App\Models\SinkingFund;
use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();

        DB::transaction(function () use ($now): void {
            Goal::orderBy('id')->get()->each(function (Goal $goal) use ($now): void {
                $maxPosition = (int) SinkingFund::where('user_id', $goal->user_id)->max('position');

                SinkingFund::create([
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
            SinkingFund::whereNotNull('migrated_goal_id')->delete();
        });
    }
};
