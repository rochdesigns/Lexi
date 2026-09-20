<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debt_focus_settings', function (Blueprint $table): void {
            // Nullable rather than defaulted to zero: "not answered yet" and
            // "I earn nothing" read differently on the Forecast page.
            $table->decimal('expected_monthly_income', 12, 2)->nullable()->after('extra_monthly');
        });
    }

    public function down(): void
    {
        Schema::table('debt_focus_settings', function (Blueprint $table): void {
            $table->dropColumn('expected_monthly_income');
        });
    }
};
