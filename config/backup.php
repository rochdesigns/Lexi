<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Backup Destination
    |--------------------------------------------------------------------------
    |
    | The disk and directory database dumps are written to. In production this
    | should be a disk that lives somewhere other than the application server,
    | so that losing the server does not lose the backups with it.
    |
    */

    'disk' => env('BACKUP_DISK', 'local'),

    'path' => env('BACKUP_PATH', 'backups'),

    /*
    |--------------------------------------------------------------------------
    | Retention
    |--------------------------------------------------------------------------
    |
    | How many days of dumps to keep. Older dumps are pruned after each
    | successful backup.
    |
    */

    'retain_days' => (int) env('BACKUP_RETAIN_DAYS', 30),

    /*
    |--------------------------------------------------------------------------
    | Client Binaries
    |--------------------------------------------------------------------------
    |
    | Paths to the MySQL client binaries used to dump and restore. Override
    | these when they are not on the PATH of the account running the app.
    |
    */

    'mysqldump_binary' => env('MYSQLDUMP_BINARY', 'mysqldump'),

    'mysql_binary' => env('MYSQL_BINARY', 'mysql'),

];
