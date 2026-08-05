<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->decimal('fi_number', 12, 2)->nullable()->after('budget_cycle_anchor');
            $table->decimal('fi_expected_return_rate', 5, 2)->nullable()->default(6.00)->after('fi_number');
            $table->unsignedTinyInteger('emergency_fund_target_months')->nullable()->default(6)->after('fi_expected_return_rate');
            $table->decimal('investment_monthly_contribution_target', 12, 2)->nullable()->after('emergency_fund_target_months');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn([
                'fi_number',
                'fi_expected_return_rate',
                'emergency_fund_target_months',
                'investment_monthly_contribution_target',
            ]);
        });
    }
};
