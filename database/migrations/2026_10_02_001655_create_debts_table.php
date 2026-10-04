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
        Schema::create('debts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('type', 20);
            $table->string('lender', 100)->nullable();
            $table->string('interest_type', 20)->default('none');
            $table->decimal('monthly_interest_rate', 6, 3)->nullable();
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('outstanding_balance')->nullable();
            $table->date('target_payoff_date')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debts');
    }
};
