<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Marking a bill paid writes an expense, but until now nothing tied the two
     * together — the entry was only recognisable by its description. Without the
     * link a bill cannot be un-marked, because there is no way to find the money
     * it moved and put it back.
     */
    public function up(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->foreignId('bill_period_id')->nullable()->after('debt_id')
                ->constrained('debt_focus_bill_periods')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->dropForeign(['bill_period_id']);
            $table->dropColumn('bill_period_id');
        });
    }
};
