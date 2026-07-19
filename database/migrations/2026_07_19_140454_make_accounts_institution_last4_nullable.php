<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');
        DB::statement('
            CREATE TABLE accounts_new (
                id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name varchar NOT NULL,
                institution varchar NULL,
                last4 varchar NULL,
                balance numeric NOT NULL DEFAULT \'0\',
                type varchar NOT NULL DEFAULT \'cash\',
                created_at datetime NULL,
                updated_at datetime NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ');
        DB::statement('INSERT INTO accounts_new SELECT * FROM accounts');
        DB::statement('DROP TABLE accounts');
        DB::statement('ALTER TABLE accounts_new RENAME TO accounts');
        DB::statement('PRAGMA foreign_keys=ON');
    }

    public function down(): void
    {
        DB::statement('PRAGMA foreign_keys=OFF');
        DB::statement('
            CREATE TABLE accounts_new (
                id INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                name varchar NOT NULL,
                institution varchar NOT NULL,
                last4 varchar NOT NULL,
                balance numeric NOT NULL DEFAULT \'0\',
                type varchar NOT NULL DEFAULT \'cash\',
                created_at datetime NULL,
                updated_at datetime NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )
        ');
        DB::statement('INSERT INTO accounts_new SELECT * FROM accounts');
        DB::statement('DROP TABLE accounts');
        DB::statement('ALTER TABLE accounts_new RENAME TO accounts');
        DB::statement('PRAGMA foreign_keys=ON');
    }
};
