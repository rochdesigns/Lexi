<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recovery mode: the months chosen to clear carried-over card balances.
     * Null means recovery mode is off.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->unsignedTinyInteger('recovery_months')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn('recovery_months');
        });
    }
};
