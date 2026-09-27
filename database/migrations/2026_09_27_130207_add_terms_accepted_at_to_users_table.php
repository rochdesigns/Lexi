<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Record when each account accepted the terms and privacy policy.
     *
     * Nullable because accounts that predate the consent gate never saw it;
     * a null means "no recorded consent", not "declined".
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')->nullable()->after('email_verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('terms_accepted_at');
        });
    }
};
