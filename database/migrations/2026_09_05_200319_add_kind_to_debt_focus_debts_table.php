<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `debt_type` says how a debt behaves for the payoff engine — revolving or
     * installment. `kind` says what it actually is, which is what someone
     * recognises it by: a card, a bank loan, a phone plan, money owed to a
     * person. Several kinds share one behaviour, so the two cannot be collapsed.
     */
    public function up(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->string('kind', 20)->nullable()->after('debt_type');
        });

        DB::table('debt_focus_debts')->where('debt_type', 'revolving')->update(['kind' => 'card']);
        DB::table('debt_focus_debts')->where('debt_type', 'installment')->update(['kind' => 'loan']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
