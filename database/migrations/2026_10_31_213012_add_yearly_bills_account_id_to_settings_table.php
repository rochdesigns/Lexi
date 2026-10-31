<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The account yearly and quarterly bills are saved in, so its balance shows how much is put aside for them.
     */
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->foreignId('yearly_bills_account_id')->nullable()->after('recovery_months')->constrained('accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('yearly_bills_account_id');
        });
    }
};
