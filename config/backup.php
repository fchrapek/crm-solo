<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Local backup folder
    |--------------------------------------------------------------------------
    |
    | Where db:backup writes and db:backup:list / db:restore read. These are
    | local dev tools; one key so tests can point them at a temp directory.
    |
    */

    'path' => storage_path('backups'),

    /*
    |--------------------------------------------------------------------------
    | Scheduled dump outside local
    |--------------------------------------------------------------------------
    |
    | A time of day (HH:MM, UTC: the scheduler's zone) at which a hosted
    | stack runs db:backup into the folder above, which the hosting compose
    | file puts on its own volume. Unset, only a local install schedules it
    | (02:00). Copying the dumps off the box is the host's job, not the app's.
    |
    */

    'schedule_at' => env('BACKUP_SCHEDULE_AT'),

];
