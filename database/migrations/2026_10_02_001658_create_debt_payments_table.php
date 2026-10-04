<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Money columns are integer centavos. Rates are percentages, not money.
     */
    public function up(): void
    {
        Schema::create('debt_payments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_plan_id')->nullable()->constrained()->nullOnDelete();
            $table->date('date');
            $table->unsignedBigInteger('amount');
            $table->boolean('is_extra')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_payments');
    }
};
