<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * amount is integer centavos per payment. due_month is for annual bills only.
     */
    public function up(): void
    {
        Schema::create('bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedBigInteger('amount');
            $table->string('frequency')->default('monthly');
            $table->unsignedTinyInteger('due_day');
            $table->unsignedTinyInteger('due_month')->nullable();
            $table->boolean('autopay')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bills');
    }
};
