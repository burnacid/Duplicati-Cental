<?php

use App\Models\BackupResult;
use App\Models\RemoteControlCommand;
use Illuminate\Support\Facades\Schedule;

Schedule::command('backups:check-overdue')->hourly()->withoutOverlapping();

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
