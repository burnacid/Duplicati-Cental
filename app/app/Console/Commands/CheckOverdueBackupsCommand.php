<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\BackupHealthService;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class CheckOverdueBackupsCommand extends Command
{
    protected $signature = 'backups:check-overdue';

    protected $description = 'Notify users about backup jobs that stopped reporting.';

    public function handle(BackupHealthService $health, NotificationService $notifications): int
    {
        $sent = 0;

        User::query()->each(function (User $user) use ($health, $notifications, &$sent) {
            foreach ($health->jobs($user) as $job) {
                if (! $job['is_overdue'] || $job['is_muted']) {
                    continue;
                }

                // Notify once per missed period: the key changes when a new backup arrives.
                $key = sprintf('overdue-notified:%s:%s:%s', $job['server_id'], $job['backup_id'], $job['last_backup_at']?->timestamp);

                if (Cache::add($key, true, now()->addDays(30))) {
                    $notifications->notifyOverdue($user, $job);
                    $sent++;
                }
            }
        });

        $this->info("Sent {$sent} overdue notification(s).");

        return self::SUCCESS;
    }
}
