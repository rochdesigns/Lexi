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
        Schema::table('debts', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->constrained('accounts')->nullOnDelete()->after('budget_category_id');
            $table->decimal('minimum_payment_rate', 5, 2)->nullable()->after('interest_rate_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
            $table->dropColumn(['account_id', 'minimum_payment_rate']);
        });
    }
};
