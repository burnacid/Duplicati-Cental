<?php

namespace App\Jobs;

use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Calls the job's heartbeat URL (Uptime Kuma push monitor, Healthchecks.io, ...) after a
 * successful backup. A failed backup sends nothing, so the external monitor raises the alarm.
 * Separate from the notification job so a slow or failing ping never repeats notifications.
 */
class PingHeartbeat implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public int $timeout = 30;

    public function __construct(public int $backupResultId) {}

    public function handle(): void
    {
        $result = BackupResult::query()->find($this->backupResultId);
        if (! $result || ! self::shouldPing($result)) {
            return;
        }

        $url = BackupJobSetting::query()
            ->where('backup_server_id', $result->backup_server_id)
            ->where('backup_id', $result->backup_id)
            ->first()?->heartbeat_url;

        if (blank($url)) {
            return;
        }

        $response = Http::timeout(15)->get($url);

        if ($response->failed()) {
            Log::warning('Heartbeat ping failed', ['backup_result_id' => $result->id, 'status' => $response->status()]);
            $response->throw();
        }
    }

    /** Successful (or warning) backups only; other operations do not prove a backup ran. */
    public static function shouldPing(BackupResult $result): bool
    {
        return $result->MainOperation === 'Backup' && ! $result->status->isFailure();
    }
}
