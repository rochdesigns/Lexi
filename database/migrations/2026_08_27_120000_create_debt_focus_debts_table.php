<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_focus_debts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->decimal('balance', 10, 2);
            $table->decimal('apr', 5, 2)->nullable();
            $table->decimal('minimum_payment', 10, 2);
            $table->string('debt_type')->default('installment');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_focus_debts');
    }
};
