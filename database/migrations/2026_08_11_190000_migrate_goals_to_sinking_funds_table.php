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

        Goal::orderBy('id')->get()->each(function (Goal $goal) use ($now): void {
            $maxPosition = (int) SinkingFund::where('user_id', $goal->user_id)->max('position');

            $fund = SinkingFund::create([
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

            DB::table('transactions')
                ->where('goal_id', $goal->id)
                ->update(['sinking_fund_id' => $fund->id]);
        });
    }

    public function down(): void
    {
        SinkingFund::whereNotNull('migrated_goal_id')->get()->each(function (SinkingFund $fund): void {
            DB::table('transactions')
                ->where('goal_id', $fund->migrated_goal_id)
                ->where('sinking_fund_id', $fund->id)
                ->update(['sinking_fund_id' => null]);

            $fund->delete();
        });
    }
};
