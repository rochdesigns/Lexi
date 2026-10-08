<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A bill can be charged to a credit card (debt_id) instead of paid from an account.
     */
    public function up(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->foreignId('debt_id')->nullable()->after('account_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('bills', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('debt_id');
        });
    }
};
