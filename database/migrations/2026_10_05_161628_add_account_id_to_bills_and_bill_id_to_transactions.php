<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bill is usually paid from one account (account_id). An expense logged
     * against a bill points at it (bill_id), which marks that month's bill paid.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->foreignId('account_id')->nullable()->after('user_id')->constrained()->nullOnDelete();
        });

        Schema::table('transactions', function (Blueprint $table): void {
            $table->foreignId('bill_id')->nullable()->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('bill_id');
        });

        Schema::table('bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('account_id');
        });
    }
};
