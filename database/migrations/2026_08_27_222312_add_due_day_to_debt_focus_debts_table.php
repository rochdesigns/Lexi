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
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->unsignedTinyInteger('due_day')->nullable()->after('minimum_payment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->dropColumn('due_day');
        });
    }
};
