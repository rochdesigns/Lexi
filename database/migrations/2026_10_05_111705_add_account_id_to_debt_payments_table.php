<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account a payment was paid from, when the user said. Null for history only.
     */
    public function up(): void
    {
        Schema::table('debt_payments', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->after('payment_plan_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_payments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
