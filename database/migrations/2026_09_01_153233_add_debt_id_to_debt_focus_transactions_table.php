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
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->foreignId('debt_id')->nullable()->after('envelope_id')
                ->constrained('debt_focus_debts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->dropForeign(['debt_id']);
            $table->dropColumn('debt_id');
        });
    }
};
