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
        Schema::create('card_details', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('debt_id')->unique()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('credit_limit')->default(0);
            $table->unsignedTinyInteger('statement_day');
            $table->decimal('min_payment_percent', 5, 2)->nullable();
            $table->unsignedBigInteger('min_payment_floor')->nullable();
            $table->unsignedBigInteger('revolving_balance')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_details');
    }
};
