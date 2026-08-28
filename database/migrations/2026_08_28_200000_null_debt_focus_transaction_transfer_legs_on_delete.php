<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->dropForeign(['from_account_id']);
            $table->dropForeign(['to_account_id']);
        });

        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->foreign('from_account_id')->references('id')->on('debt_focus_accounts')->nullOnDelete();
            $table->foreign('to_account_id')->references('id')->on('debt_focus_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->dropForeign(['from_account_id']);
            $table->dropForeign(['to_account_id']);
        });

        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->foreign('from_account_id')->references('id')->on('debt_focus_accounts')->cascadeOnDelete();
            $table->foreign('to_account_id')->references('id')->on('debt_focus_accounts')->cascadeOnDelete();
        });
    }
};
