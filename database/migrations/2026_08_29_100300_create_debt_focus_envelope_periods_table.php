<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debt_focus_envelope_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envelope_id')->constrained('debt_focus_envelopes')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('target', 10, 2);
            $table->timestamp('sweep_offered_at')->nullable();
            $table->boolean('sweep_accepted')->nullable();
            $table->timestamps();

            $table->unique(['envelope_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_focus_envelope_periods');
    }
};
