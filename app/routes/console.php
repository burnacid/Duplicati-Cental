<?php

use App\Models\BackupResult;
use App\Models\RemoteControlCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command('backups:check-overdue')->hourly()->withoutOverlapping();

// Summaries for channels that opted in (BACKUP_DIGEST_TIME, app timezone).
Schedule::command('backups:send-digest daily')->dailyAt(config('backups.digest_time'))->withoutOverlapping();
Schedule::command('backups:send-digest weekly')->weeklyOn(1, config('backups.digest_time'))->withoutOverlapping();

// Retention: keep the database from growing forever.
Schedule::call(function () {
    $days = (int) config('backups.retention_days');
    if ($days > 0) {
        BackupResult::query()
            ->where('EndTime', '<', now()->utc()->subDays($days)->format('Y-m-d H:i:s'))
            ->delete();
    }

    RemoteControlCommand::query()
        ->where('created_at', '<', now()->subHours((int) config('backups.remote_command_retention_hours')))
        ->delete();
})->daily()->name('prune-old-records')->withoutOverlapping();
