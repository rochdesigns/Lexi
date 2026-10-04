<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Savings goals, in integer centavos. A buffer holds buffer_months of
     * obligations, so its target is worked out. A sinking fund has a
     * target_amount and an optional target_date. saved is typed by the user
     * unless account_id links the goal to an account, whose balance is used.
     */
    public function up(): void
    {
        Schema::create('goals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind');
            $table->string('name');
            $table->unsignedBigInteger('monthly_contribution')->default(0);
            $table->unsignedBigInteger('saved')->default(0);
            $table->unsignedTinyInteger('buffer_months')->nullable();
            $table->unsignedBigInteger('target_amount')->nullable();
            $table->date('target_date')->nullable();
            $table->foreignId('account_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goals');
    }
};
