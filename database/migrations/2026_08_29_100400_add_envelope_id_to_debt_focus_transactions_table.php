<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->foreignId('envelope_id')->nullable()->after('to_account_id')
                ->constrained('debt_focus_envelopes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('debt_focus_transactions', function (Blueprint $table) {
            $table->dropForeign(['envelope_id']);
            $table->dropColumn('envelope_id');
        });
    }
};
