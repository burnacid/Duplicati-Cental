<?php

namespace App\Jobs;

use App\Models\BackupResult;
use App\Services\BackupAlertRuleService;
use App\Services\NotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendBackupResultNotification implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $backupResultId) {}

    public function handle(NotificationService $notifications, BackupAlertRuleService $rules): void
    {
        $result = BackupResult::with('backupServer.user')->find($this->backupResultId);

        if ($result) {
            $notifications->notifyBackupResult($result);
            $notifications->notifyAlerts($result, $rules->check($result));
        }
    }
}
