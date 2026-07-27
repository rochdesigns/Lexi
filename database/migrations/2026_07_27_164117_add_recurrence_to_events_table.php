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
            $table->string('recurrence_type')->nullable()->after('color');
            $table->unsignedInteger('recurrence_interval')->default(1)->after('recurrence_type');
            $table->json('recurrence_days')->nullable()->after('recurrence_interval');
            $table->date('recurrence_ends_on')->nullable()->after('recurrence_days');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('events', function (Blueprint $table): void {
            $table->dropColumn(['recurrence_type', 'recurrence_interval', 'recurrence_days', 'recurrence_ends_on']);
        });
    }
};
