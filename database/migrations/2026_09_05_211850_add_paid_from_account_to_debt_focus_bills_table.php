<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A recurring bill is nearly always paid from the same place, so the bill
     * remembers it rather than asking again every month. Nullable: a bill set
     * up before there were any accounts still works, and the mark-paid sheet
     * falls back to asking.
     */
    public function up(): void
    {
        Schema::table('debt_focus_bills', function (Blueprint $table) {
            $table->foreignId('paid_from_account_id')->nullable()->after('amount_varies')
                ->constrained('debt_focus_accounts')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debt_focus_bills', function (Blueprint $table) {
            $table->dropForeign(['paid_from_account_id']);
            $table->dropColumn('paid_from_account_id');
        });
    }
};
