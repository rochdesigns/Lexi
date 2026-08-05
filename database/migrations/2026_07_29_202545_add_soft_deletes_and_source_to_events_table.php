<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->softDeletes();

            // Marks where an event came from. Only 'outlook' rows are eligible for
            // the sync's cancellation sweep, so hand-made events — including Work
            // ones, which share the synced tag and colour — are never touched.
            $table->string('source')->nullable()->after('color');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropSoftDeletes();
            $table->dropColumn('source');
        });
    }
};
