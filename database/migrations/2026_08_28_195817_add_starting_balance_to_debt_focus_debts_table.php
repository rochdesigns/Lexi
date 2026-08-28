<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->decimal('starting_balance', 10, 2)->nullable()->after('balance');
        });

        DB::table('debt_focus_debts')->update(['starting_balance' => DB::raw('balance')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->dropColumn('starting_balance');
        });
    }
};
