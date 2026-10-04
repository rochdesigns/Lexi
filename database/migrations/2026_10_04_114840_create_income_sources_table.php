<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * amount is integer centavos received on each pay day.
     */
    public function up(): void
    {
        Schema::create('income_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('amount');
            $table->string('schedule')->default('monthly');
            $table->unsignedTinyInteger('pay_day_1');
            $table->unsignedTinyInteger('pay_day_2')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_sources');
    }
};
