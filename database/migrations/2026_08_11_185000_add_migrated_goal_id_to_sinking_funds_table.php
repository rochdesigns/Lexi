<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sinking_funds', function (Blueprint $table): void {
            $table->unsignedBigInteger('migrated_goal_id')->nullable()->after('due_date');
        });
    }

    public function down(): void
    {
        Schema::table('sinking_funds', function (Blueprint $table): void {
            $table->dropColumn('migrated_goal_id');
        });
    }
};
