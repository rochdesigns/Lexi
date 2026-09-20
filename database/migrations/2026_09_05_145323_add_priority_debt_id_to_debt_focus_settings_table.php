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
        Schema::table('debt_focus_settings', function (Blueprint $table) {
            $table->foreignId('priority_debt_id')->nullable()->after('extra_monthly')
                ->constrained('debt_focus_debts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debt_focus_settings', function (Blueprint $table) {
            $table->dropForeign(['priority_debt_id']);
            $table->dropColumn('priority_debt_id');
        });
    }
};
