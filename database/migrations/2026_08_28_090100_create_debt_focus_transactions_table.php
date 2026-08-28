<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('debt_focus_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->foreignId('account_id')->nullable()->constrained('debt_focus_accounts')->cascadeOnDelete();
            $table->foreignId('from_account_id')->nullable()->constrained('debt_focus_accounts')->cascadeOnDelete();
            $table->foreignId('to_account_id')->nullable()->constrained('debt_focus_accounts')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('description')->nullable();
            $table->date('occurred_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('debt_focus_transactions');
    }
};
