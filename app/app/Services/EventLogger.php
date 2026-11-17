<?php

namespace App\Services;

use App\Models\BackupServer;
use App\Models\EventLog;
use Illuminate\Support\Facades\Log;

/**
 * Writes the per-user event log. Logging must never break the request or job
 * it describes, so failures only end up in laravel.log.
 */
class EventLogger
{
    /** Longest string kept in the context (payload previews, error bodies). */
    private const MAX_STRING = 2000;

    public function record(string $userId, string $category, string $level, string $message, array $context = [], ?string $backupServerId = null): void
    {
        try {
            EventLog::query()->create([
                'user_id' => $userId,
                'backup_server_id' => $backupServerId,
                'category' => $category,
                'level' => $level,
                'message' => mb_substr($message, 0, 500),
                'context' => $context === [] ? null : $this->limit($context),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Could not write event log entry', ['message' => $message, 'error' => $e->getMessage()]);
        }
    }

    public function forServer(BackupServer $server, string $category, string $level, string $message, array $context = []): void
    {
        $this->record($server->user_id, $category, $level, $message, $context, $server->id);
    }

    private function limit(array $context): array
    {
        array_walk_recursive($context, function (&$value) {
            if (is_string($value) && mb_strlen($value) > self::MAX_STRING) {
                $value = mb_substr($value, 0, self::MAX_STRING).'…';
            }
        });

        return $context;
    }
}
