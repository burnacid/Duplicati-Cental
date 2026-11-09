<?php

namespace App\Jobs;

use App\Models\RemoteControlRegistration;
use App\Services\RemoteLogImportService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;

/**
 * Imports the last N days of results from a connected instance.
 * Progress is kept in the cache under statusKey() so the UI can show it.
 */
class ImportRemoteControlLogs implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 1;
    public int $timeout = 1800;
    public int $uniqueFor = 1800;

    public function __construct(public string $registrationId, public int $days = 30)
    {
    }

    public function uniqueId(): string
    {
        return $this->registrationId;
    }

    public static function statusKey(string $registrationId): string
    {
        return "remote-log-import:{$registrationId}";
    }

    /** Mark as queued so the UI shows feedback before the worker picks it up. */
    public static function start(string $registrationId, int $days = 30): void
    {
        Cache::put(self::statusKey($registrationId), [
            'state' => 'queued', 'days' => $days, 'jobs' => 0, 'imported' => 0, 'skipped' => 0,
            'failed_jobs' => [], 'message' => null, 'updated_at' => now()->toIso8601String(),
        ], now()->addDay());

        static::dispatch($registrationId, $days);
    }

    public function handle(RemoteLogImportService $importer): void
    {
        $registration = RemoteControlRegistration::find($this->registrationId);
        if (! $registration || ! config('remote_control.enabled')) {
            $this->status(['state' => 'failed', 'message' => 'Remote control is not available for this instance.']);

            return;
        }

        $this->status(['state' => 'running']);

        try {
            $stats = $importer->import($registration, $this->days, fn (array $s) => $this->status(['state' => 'running'] + $s));
            $this->status(['state' => 'done'] + $stats);
        } catch (\Throwable $e) {
            $this->status(['state' => 'failed', 'message' => $e->getMessage()]);
        }
    }

    private function status(array $values): void
    {
        $key = self::statusKey($this->registrationId);
        $current = Cache::get($key, []);

        Cache::put($key, array_merge($current, $values, ['days' => $this->days, 'updated_at' => now()->toIso8601String()]), now()->addDay());
    }
}
