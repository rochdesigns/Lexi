<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_focus_bill_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bill_id')->constrained('debt_focus_bills')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('expected_amount', 10, 2);
            $table->decimal('actual_amount', 10, 2)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['bill_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_focus_bill_periods');
    }
};
