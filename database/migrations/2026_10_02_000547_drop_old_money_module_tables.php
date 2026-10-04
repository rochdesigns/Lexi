<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables from the retired money module (Debt Focus and the earlier ledger).
     *
     * @var list<string>
     */
    private const TABLES = [
        'debt_focus_transactions',
        'debt_focus_bill_periods',
        'debt_focus_bills',
        'debt_focus_envelope_periods',
        'debt_focus_envelopes',
        'debt_focus_debts',
        'debt_focus_accounts',
        'debt_focus_settings',
        'debt_payments',
        'debt_balance_snapshots',
        'account_balance_snapshots',
        'envelope_assignments',
        'transactions',
        'sinking_funds',
        'goals',
        'recurring_bills',
        'debts',
        'accounts',
        'budget_months',
        'budget_categories',
        'budget_cycles',
    ];

    /**
     * @var list<string>
     */
    private const SETTINGS_COLUMNS = [
        'budget_cycle_days',
        'budget_cycle_anchor',
        'fi_number',
        'fi_expected_return_rate',
        'emergency_fund_target_months',
        'investment_monthly_contribution_target',
        'cycle_type',
        'monthly_start_day',
    ];

    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            foreach (self::TABLES as $table) {
                Schema::dropIfExists($table);
            }
        });

        Schema::table('settings', function (Blueprint $table): void {
            $table->dropColumn(self::SETTINGS_COLUMNS);
        });
    }

    /**
     * The old money module is intentionally not restorable; use the pre-rebuild database backup.
     */
    public function down(): void
    {
        //
    }
};
