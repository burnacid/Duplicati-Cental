<?php

use App\Jobs\QueueHeartbeat;
use App\Models\BackupResult;
use App\Models\RemoteControlCommand;
use App\Services\SystemHealthService;
use Illuminate\Support\Facades\Schedule;

// Heartbeats for Settings → Health.
Schedule::call(fn () => app(SystemHealthService::class)->beat(SystemHealthService::SCHEDULER))
    ->everyMinute()->name('health-scheduler');
Schedule::job(new QueueHeartbeat)->everyFiveMinutes()->name('health-queue');

Schedule::command('backups:check-overdue')->hourly()->withoutOverlapping();

// Remote-control instances that stopped checking in (REMOTE_CONTROL_OFFLINE_ALERT_MINUTES).
Schedule::command('remote-control:check-offline')->everyFiveMinutes()->withoutOverlapping();

if (config('backups.central.enabled')) {
    Schedule::command('app:backup')->dailyAt(config('backups.central.time'))->withoutOverlapping();
}

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
