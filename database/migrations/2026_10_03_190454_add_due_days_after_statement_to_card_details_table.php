<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Banks often set the due date as a number of days after the statement
     * closes. When set, it replaces the debt's fixed due_day for the card.
     */
    public function up(): void
    {
        Schema::table('card_details', function (Blueprint $table): void {
            $table->unsignedTinyInteger('due_days_after_statement')->nullable()->after('statement_day');
        });
    }

    public function down(): void
    {
        Schema::table('card_details', function (Blueprint $table): void {
            $table->dropColumn('due_days_after_statement');
        });
    }
};
