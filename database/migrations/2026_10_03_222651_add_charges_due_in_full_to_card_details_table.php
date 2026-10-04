<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Finance and late charges on the latest statement, in centavos. Banks
     * count these in full toward the minimum instead of taking a percent.
     */
    public function up(): void
    {
        Schema::table('card_details', function (Blueprint $table): void {
            $table->unsignedBigInteger('charges_due_in_full')->default(0)->after('revolving_balance');
        });
    }

    public function down(): void
    {
        Schema::table('card_details', function (Blueprint $table): void {
            $table->dropColumn('charges_due_in_full');
        });
    }
};
