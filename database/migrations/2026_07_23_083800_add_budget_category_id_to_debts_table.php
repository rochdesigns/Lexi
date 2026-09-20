<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->foreignId('budget_category_id')->nullable()->constrained()->nullOnDelete()->after('user_id');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('budget_category_id');
        });
    }
};
