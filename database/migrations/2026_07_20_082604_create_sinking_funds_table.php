<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sinking_funds', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name', 100);
            $table->string('icon', 50);
            $table->string('color', 7);
            $table->string('bg', 7);
            $table->decimal('saved', 10, 2)->default(0);
            $table->decimal('target', 10, 2);
            $table->decimal('per_month', 10, 2);
            $table->string('due_text', 100)->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sinking_funds');
    }
};
