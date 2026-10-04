<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A transaction is an expense or money received. Income is paid into an
     * account and can point at the income source it settles (income_source_id),
     * and at the pay day it was for (pay_date), so that pay day is not counted
     * again in the forecast.
     */
    public function up(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->string('kind')->default('expense');
            $table->foreignId('income_source_id')->nullable()->constrained()->nullOnDelete();
            $table->date('pay_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('transactions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('income_source_id');
            $table->dropColumn(['kind', 'pay_date']);
        });
    }
};
