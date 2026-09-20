<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Databases created before the accounts table declared these columns
     * nullable still have them as NOT NULL, so widen them here.
     *
     * This previously rebuilt the table with raw SQLite SQL, which failed on
     * every other driver. Laravel implements change() per driver, including
     * the SQLite table rebuild, so the portable form is enough.
     */
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('institution')->nullable()->change();
            $table->string('last4')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            $table->string('institution')->nullable(false)->change();
            $table->string('last4')->nullable(false)->change();
        });
    }
};
