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
        Schema::table('sinking_funds', function (Blueprint $table): void {
            $table->date('due_date')->nullable()->after('per_month');
            $table->dropColumn('due_text');
        });
    }

    public function down(): void
    {
        Schema::table('sinking_funds', function (Blueprint $table): void {
            $table->string('due_text', 100)->nullable()->after('per_month');
            $table->dropColumn('due_date');
        });
    }
};
