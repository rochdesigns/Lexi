<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->foreignId('linked_account_id')->nullable()->after('due_day')
                ->constrained('debt_focus_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_focus_debts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('linked_account_id');
        });
    }
};
