<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bill is a shape a month takes, not a single amount. `frequency` and
     * `anchor_month` say which months it actually lands in — a quarterly bill
     * should not sit in the envelope for the two months it isn't due — and
     * `amount_varies` says the envelope should follow what it really costs
     * rather than a figure that was only ever a guess.
     */
    public function up(): void
    {
        Schema::table('debt_focus_bills', function (Blueprint $table) {
            $table->string('frequency', 12)->default('monthly')->after('expected_amount');
            // Which month the cycle counts from, for anything less often than
            // monthly. Null for monthly bills, which land every month regardless.
            $table->unsignedTinyInteger('anchor_month')->nullable()->after('frequency');
            $table->boolean('amount_varies')->default(false)->after('anchor_month');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debt_focus_bills', function (Blueprint $table) {
            $table->dropColumn(['frequency', 'anchor_month', 'amount_varies']);
        });
    }
};
