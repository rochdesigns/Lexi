<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->string('cycle_type')->default('fixed_days')->after('budget_cycle_anchor');
            $table->unsignedTinyInteger('monthly_start_day')->nullable()->after('cycle_type');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn(['cycle_type', 'monthly_start_day']);
        });
    }
};
