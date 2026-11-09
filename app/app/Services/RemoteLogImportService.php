<?php

namespace App\Services;

use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Pulls historical results from a connected Duplicati instance over remote control.
 *
 * For every backup job it pages through GET /api/v1/backup/{id}/log (newest first)
 * and stores each "Result" entry of the last N days that is not stored yet.
 * Imported results get source = 'remote-log' and never trigger notifications.
 */
class RemoteLogImportService
{
    public const PAGE_SIZE = 25;
    private const MAX_PAGES_PER_JOB = 200;
    /** Seconds of tolerance when matching an imported run to an existing one. */
    private const MATCH_TOLERANCE = 2;

    public function __construct(
        private readonly RemoteControlCommandService $commands,
        private readonly BackupResultFactory $factory,
        private readonly RemoteControlProvisioningService $provisioning,
    ) {
    }

    /**
     * @param (callable(array): void)|null $onProgress receives the running totals
     * @return array{jobs: int, imported: int, skipped: int, failed_jobs: list<string>}
     */
    public function import(RemoteControlRegistration $registration, int $days = 30, ?callable $onProgress = null): array
    {
        $registration->loadMissing(['claimedByUser', 'agentState']);
        $user = $registration->claimedByUser;

        if (! $user) {
            throw new RuntimeException('This instance is not claimed by a user.');
        }

        if (! $registration->agentState?->is_connected) {
            throw new RuntimeException('The instance is not connected.');
        }

        $cutoff = now()->subDays($days);
        $stats = ['jobs' => 0, 'imported' => 0, 'skipped' => 0, 'failed_jobs' => []];

        [$status, $body] = $this->request($registration, 'GET', '/api/v1/backups');
        if ($status !== 200 || ! is_array($body)) {
            throw new RuntimeException("Could not list backup jobs (HTTP {$status}).");
        }

        foreach (self::normalizeBackups($body) as $job) {
            $stats['jobs']++;

            try {
                [$imported, $skipped] = $this->importJob($registration, $job, $cutoff);
                $stats['imported'] += $imported;
                $stats['skipped'] += $skipped;
            } catch (\Throwable $e) {
                $stats['failed_jobs'][] = "{$job['name']}: {$e->getMessage()}";
            }

            if ($onProgress) {
                $onProgress($stats);
            }
        }

        return $stats;
    }

    /**
     * @param array{id: string, name: string} $job
     * @return array{0: int, 1: int} imported, skipped
     */
    private function importJob(RemoteControlRegistration $registration, array $job, Carbon $cutoff): array
    {
        $reportId = 'DB-' . $job['id'];
        $server = $this->targetServer($registration, $job['id']);
        $known = $this->knownStartTimes($server, $job['id'], $cutoff);

        $imported = 0;
        $skipped = 0;
        $offset = null;

        for ($page = 0; $page < self::MAX_PAGES_PER_JOB; $page++) {
            $path = '/api/v1/backup/' . rawurlencode($job['id']) . '/log?pagesize=' . self::PAGE_SIZE
                . ($offset !== null ? '&offset=' . $offset : '');

            [$status, $rows] = $this->request($registration, 'GET', $path);
            if ($status !== 200 || ! is_array($rows)) {
                throw new RuntimeException("log request failed (HTTP {$status})");
            }

            if ($rows === []) {
                break;
            }

            $reachedCutoff = false;

            foreach ($rows as $row) {
                $timestamp = (int) ($row['Timestamp'] ?? 0);
                if ($timestamp > 0 && $timestamp < $cutoff->timestamp) {
                    $reachedCutoff = true;
                    continue;
                }

                if (($row['Type'] ?? null) !== 'Result') {
                    continue;
                }

                $data = json_decode((string) ($row['Message'] ?? ''), true);
                if (! is_array($data) || $this->factory->isIgnoredOperation($data['MainOperation'] ?? null) || empty($data['BeginTime'])) {
                    continue;
                }

                $begin = Carbon::parse($data['BeginTime'], 'UTC')->timestamp;
                if ($this->isKnown($known, $begin)) {
                    $skipped++;
                    continue;
                }

                $result = $this->factory->make($server, $data, [
                    'OperationName' => $data['MainOperation'],
                    'machine-id' => $registration->machine_id,
                    'machine-name' => $registration->machine_name,
                    'backup-name' => $job['name'],
                    'backup-id' => $reportId,
                ], $this->logLines($data));
                $result->source = 'remote-log';
                $result->remote_log_id = isset($row['ID']) ? (int) $row['ID'] : null;
                $result->save();

                $known[] = $begin;
                $imported++;
            }

            $ids = array_filter(array_map(fn ($r) => isset($r['ID']) ? (int) $r['ID'] : null, $rows));
            if ($reachedCutoff || count($rows) < self::PAGE_SIZE || $ids === []) {
                break;
            }

            $offset = min($ids);
        }

        return [$imported, $skipped];
    }

    /**
     * Store imports next to existing results of this machine/job, so the history
     * stays in one place; otherwise use the server created for the claimed instance.
     */
    private function targetServer(RemoteControlRegistration $registration, string $jobId): BackupServer
    {
        $existing = BackupResult::forUser($registration->claimed_by_user_id)
            ->whereIn('backup_id', ['DB-' . $jobId, $jobId])
            ->when($registration->machine_id, fn ($q) => $q->where('extra->machine-id', $registration->machine_id))
            ->latest('EndTime')
            ->first();

        return $existing?->backupServer
            ?? $this->provisioning->ensureBackupServer($registration, $registration->claimedByUser);
    }

    /** @return list<int> start times (unix) of runs already stored for this job */
    private function knownStartTimes(BackupServer $server, string $jobId, Carbon $cutoff): array
    {
        return BackupResult::query()
            ->where('backup_server_id', $server->id)
            ->whereIn('backup_id', ['DB-' . $jobId, $jobId])
            ->where('BeginTime', '>=', $cutoff->copy()->subDay()->utc()->format('Y-m-d H:i:s'))
            ->get(['BeginTime'])
            ->map(fn ($r) => $r->BeginTime?->timestamp)
            ->filter()
            ->values()
            ->all();
    }

    private function isKnown(array $known, int $begin): bool
    {
        foreach ($known as $time) {
            if (abs($time - $begin) <= self::MATCH_TOLERANCE) {
                return true;
            }
        }

        return false;
    }

    /** Log lines for the result: Duplicati keeps them in Messages (plus Warnings/Errors). */
    private function logLines(array $data): array
    {
        $lines = collect($data['Messages'] ?? [])
            ->concat($data['Warnings'] ?? [])
            ->concat($data['Errors'] ?? [])
            ->filter(fn ($l) => is_string($l) && $l !== '')
            ->unique()
            ->values();

        return $lines->all();
    }

    /**
     * Send a request to the instance and wait for its answer.
     * Protected so tests can replace the transport.
     *
     * @return array{0: ?int, 1: mixed} status code and decoded JSON body
     */
    protected function request(RemoteControlRegistration $registration, string $method, string $path, int $timeoutSeconds = 45): array
    {
        $command = $this->commands->dispatch($registration, $method, $path);
        $deadline = microtime(true) + $timeoutSeconds;

        while (microtime(true) < $deadline) {
            usleep(300_000);
            $command = RemoteControlCommand::query()->find($command->id);

            if ($command === null) {
                throw new RuntimeException('The request was lost.');
            }

            if ($command->isFinished()) {
                if ($command->status !== RemoteControlCommand::STATUS_COMPLETED) {
                    throw new RuntimeException($command->error_message ?: 'The instance did not answer.');
                }

                return [$command->response_status_code, json_decode((string) $command->response_body, true)];
            }
        }

        throw new RuntimeException('Timed out waiting for the instance.');
    }

    /**
     * Duplicati returns [{ "Backup": { "ID", "Name", ... }, "Schedule": {...} }].
     *
     * @return list<array{id: string, name: string, description: ?string, next_run: ?string}>
     */
    public static function normalizeBackups(array $items): array
    {
        $backups = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $backup = is_array($item['Backup'] ?? null) ? $item['Backup'] : $item;
            $id = $backup['ID'] ?? $backup['id'] ?? null;

            if ($id === null || $id === '') {
                continue;
            }

            $backups[] = [
                'id' => (string) $id,
                'name' => (string) ($backup['Name'] ?? $backup['name'] ?? "Backup {$id}"),
                'description' => $backup['Description'] ?? $backup['description'] ?? null,
                'next_run' => data_get($item, 'Schedule.Time') ?? data_get($item, 'schedule.time'),
            ];
        }

        return $backups;
    }
}
