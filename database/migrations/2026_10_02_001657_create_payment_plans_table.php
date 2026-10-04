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
        Schema::create('payment_plans', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debt_id')->constrained()->cascadeOnDelete();
            $table->string('description', 150);
            $table->unsignedBigInteger('principal');
            $table->unsignedBigInteger('monthly_amount');
            $table->unsignedSmallInteger('total_months');
            $table->date('start_month');
            $table->string('interest_type', 20)->nullable();
            $table->boolean('in_minimum')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_plans');
    }
};
