<?php

return [
    /*
    | A backup job is "overdue" when its last run is older than twice the
    | learned schedule interval plus this grace period.
    */
    'overdue_grace_minutes' => (int) env('BACKUP_OVERDUE_GRACE_MINUTES', 60),

    /*
    | When there is not enough history to learn a schedule (fewer than three
    | runs), a job counts as overdue after this many hours without a backup.
    */
    'overdue_fallback_hours' => (int) env('BACKUP_OVERDUE_FALLBACK_HOURS', 168),

    /*
    | Retention. Backup results older than this are pruned daily (0 = keep forever).
    | Remote-control command records are kept for a short time only.
    */
    'retention_days' => (int) env('BACKUP_RETENTION_DAYS', 365),
    'remote_command_retention_hours' => (int) env('REMOTE_COMMAND_RETENTION_HOURS', 24),

    /*
    | Time of day (HH:MM, app timezone) for the daily summary, and for the
    | weekly summary on Mondays. Channels choose which one they receive.
    */
    'digest_time' => env('BACKUP_DIGEST_TIME', '08:00'),

    /*
    | Backups of Duplicati Central itself (php artisan app:backup): database,
    | APP_KEY and remote-control keys. Optional daily run at `time` (app timezone);
    | the newest `keep` backups are kept (0 = all). `password` encrypts them (AES-256).
    */
    'central' => [
        'enabled' => (bool) env('CENTRAL_BACKUP_ENABLED', false),
        'time' => env('CENTRAL_BACKUP_TIME', '03:00'),
        'keep' => (int) env('CENTRAL_BACKUP_KEEP', 7),
        'path' => env('CENTRAL_BACKUP_PATH') ?: storage_path('backups'),
        'password' => env('CENTRAL_BACKUP_PASSWORD'),
    ],
];
