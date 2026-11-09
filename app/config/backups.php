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
];
