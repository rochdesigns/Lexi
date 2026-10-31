<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A monthly cap on spending in one category, from the month it was set or
     * changed. A custom category's limit keeps "other" and points at it, as its
     * entries do. No amount means no limit from that month on.
     */
    public function up(): void
    {
        Schema::create('spending_limits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('category');
            $table->foreignId('custom_category_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount')->nullable();
            $table->date('starts_on');
            $table->timestamps();

            $table->unique(['user_id', 'category', 'custom_category_id', 'starts_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('spending_limits');
    }
};
