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
        Schema::table('recurring_bills', function (Blueprint $table) {
            $table->date('last_paid_at')->nullable()->after('day_of_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('recurring_bills', function (Blueprint $table) {
            $table->dropColumn('last_paid_at');
        });
    }
};
