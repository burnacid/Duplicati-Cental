<?php

namespace App\Livewire\RemoteControl;

use App\Jobs\ImportRemoteControlLogs;
use App\Livewire\RemoteControl\Concerns\AwaitsRemoteCommands;
use App\Livewire\RemoteControl\Concerns\BrowsesRestore;
use App\Livewire\RemoteControl\Concerns\ShowsJobConfiguration;
use App\Models\BackupResult;
use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlCommandService;
use App\Services\RemoteLogImportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * One remote-controlled Duplicati instance: its jobs, live progress, operations
 * (run, verify, compact, repair, recreate database, restore), job details and schedule,
 * and pausing or stopping the instance.
 */
class Show extends Component
{
    use AwaitsRemoteCommands, BrowsesRestore, ShowsJobConfiguration;

    /**
     * Operations per job. `pre` is sent first (recreate = delete the local database, then repair).
     */
    public const OPERATIONS = [
        'run' => ['label' => 'Backup', 'path' => '/api/v1/backup/%s/run?skipQueue=false'],
        'verify' => ['label' => 'Verify', 'path' => '/api/v1/backup/%s/verify'],
        'compact' => ['label' => 'Compact', 'path' => '/api/v1/backup/%s/compact'],
        'repair' => ['label' => 'Repair', 'path' => '/api/v1/backup/%s/repair'],
        'recreate' => ['label' => 'Recreate database', 'pre' => '/api/v1/backup/%s/deletedb', 'path' => '/api/v1/backup/%s/repair'],
        'restore' => ['label' => 'Restore', 'path' => '/api/v1/backup/%s/restore'],
    ];

    /** Pause choices: Duplicati duration => label ('' = until resumed). */
    public const PAUSE_OPTIONS = ['15m' => '15 minutes', '1h' => '1 hour', '4h' => '4 hours', '1D' => '1 day', '' => 'Until resumed'];

    #[Locked]
    public RemoteControlRegistration $registration;

    public ?string $backupsCommandId = null;

    public ?string $progressCommandId = null;

    /**
     * Operations started from this page, per Duplicati backup id.
     *
     * @var array<string, array{
     *     operation: string, phase: string, task_id: ?int, message: ?string,
     *     command_id: ?string, command_kind: ?string, updated_at: string, next_path?: ?string
     * }>
     */
    public array $tasks = [];

    public ?array $backups = null;

    public ?array $progress = null;

    public ?string $progressError = null;

    /** Instance state: ProgramState (Running/Paused) and EstimatedPauseEnd. */
    public ?array $serverState = null;

    public ?string $instanceMessage = null;

    public string $pauseFor = '1h';

    public function mount(string $id, RemoteControlCommandService $commandService): void
    {
        $this->registration = RemoteControlRegistration::query()
            ->with('backupServer')
            ->where('claimed_by_user_id', Auth::id())
            ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
            ->findOrFail($id);

        $this->backupsCommandId = $commandService->dispatch($this->registration, 'GET', '/api/v1/backups')->id;
        $this->progressCommandId = $commandService->dispatch($this->registration, 'GET', '/api/v1/progressstate')->id;
    }

    public function poll(RemoteControlCommandService $commandService): void
    {
        $this->collectBackups();
        $this->collectProgress($commandService);
        $this->collectRequests();

        if ($this->serverState === null && ! $this->isWaitingFor('serverstate')) {
            $this->request('serverstate', 'GET', '/api/v1/serverstate');
        }

        foreach (array_keys($this->tasks) as $backupId) {
            $this->advanceTask((string) $backupId, $commandService);
        }
    }

    public function runBackup(string $backupId): void
    {
        $this->startOperation('run', $backupId);
    }

    public function repairBackup(string $backupId): void
    {
        $this->startOperation('repair', $backupId);
    }

    /** Run, verify, compact, repair or recreate from the job's menu. */
    public function runOperation(string $operation, string $backupId): void
    {
        if ($operation !== 'restore') {
            $this->startOperation($operation, $backupId);
        }
    }

    /**
     * Queue an import of the last 30 days of results from this instance's local logs.
     */
    public function importHistory(): void
    {
        $status = Cache::get(ImportRemoteControlLogs::statusKey($this->registration->id));

        if (in_array($status['state'] ?? null, ['queued', 'running'], true)) {
            return;
        }

        ImportRemoteControlLogs::start($this->registration->id, 30);
    }

    public function dismissTask(string $backupId): void
    {
        if (isset($this->tasks[$backupId]) && $this->isTaskFinished($this->tasks[$backupId])) {
            unset($this->tasks[$backupId]);
        }
    }

    public function isTaskFinished(array $task): bool
    {
        return in_array($task['phase'], ['completed', 'failed', 'error'], true);
    }

    /** Stop the running task after the current file (abort: immediately). */
    public function stopTask(bool $abort = false): void
    {
        $taskId = $this->progress['TaskID'] ?? null;
        if (! is_numeric($taskId)) {
            return;
        }

        $this->instanceMessage = $abort ? 'Aborting…' : 'Stopping…';
        $this->request($abort ? 'abort' : 'stop', 'POST', sprintf('/api/v1/task/%d/%s', (int) $taskId, $abort ? 'abort' : 'stop'));
    }

    /** Pause scheduled backups on the instance (and the running one). */
    public function pauseInstance(): void
    {
        if (! array_key_exists($this->pauseFor, self::PAUSE_OPTIONS)) {
            return;
        }

        $this->instanceMessage = 'Pausing…';
        $query = $this->pauseFor === '' ? '' : '?duration='.rawurlencode($this->pauseFor);
        $this->request('pause', 'POST', '/api/v1/serverstate/pause'.$query);
    }

    public function resumeInstance(): void
    {
        $this->instanceMessage = 'Resuming…';
        $this->request('resume', 'POST', '/api/v1/serverstate/resume');
    }

    protected function onRemoteResponse(string $key, ?int $code, array $body, ?string $error): void
    {
        match ($key) {
            'config', 'save' => $this->onJobConfigResponse($key, $body, $error),
            'filesets', 'folder' => $this->onRestoreResponse($key, $body, $error),
            'serverstate' => $this->serverState = $error === null ? $body : ['ProgramState' => null, 'Error' => $error],
            default => $this->onInstanceResponse($key, $error),
        };
    }

    private function onInstanceResponse(string $key, ?string $error): void
    {
        if ($error !== null) {
            $this->instanceMessage = 'Failed: '.$error;

            return;
        }

        $this->instanceMessage = match ($key) {
            'stop' => 'The task stops after the current file.',
            'abort' => 'The task was aborted.',
            'pause' => 'The instance is paused.',
            'resume' => 'The instance is running again.',
            default => null,
        };

        if (in_array($key, ['pause', 'resume'], true)) {
            $this->request('serverstate', 'GET', '/api/v1/serverstate');
        }
    }

    /**
     * Starts an operation for a job; false when one is still running for it.
     */
    protected function startOperation(string $operation, string $backupId, ?array $body = null): bool
    {
        if (! isset(self::OPERATIONS[$operation]) || $this->taskInFlight($backupId)) {
            return false;
        }

        $definition = self::OPERATIONS[$operation];
        $first = $definition['pre'] ?? $definition['path'];

        $command = app(RemoteControlCommandService::class)->dispatch(
            $this->registration,
            'POST',
            sprintf($first, rawurlencode($backupId)),
            $body,
            requestedByUserId: Auth::id(),
        );

        $this->tasks[$backupId] = [
            'operation' => $definition['label'],
            'phase' => 'sending',
            'task_id' => null,
            'message' => null,
            'command_id' => $command->id,
            'command_kind' => isset($definition['pre']) ? 'pre' : 'start',
            'next_path' => isset($definition['pre']) ? sprintf($definition['path'], rawurlencode($backupId)) : null,
            'updated_at' => now()->toIso8601String(),
        ];

        return true;
    }

    private function taskInFlight(string $backupId): bool
    {
        return isset($this->tasks[$backupId]) && ! $this->isTaskFinished($this->tasks[$backupId]);
    }

    /**
     * Moves a task forward: (pre-step ->) start command -> Duplicati task id -> poll task status until done.
     */
    private function advanceTask(string $backupId, RemoteControlCommandService $commandService): void
    {
        $task = $this->tasks[$backupId];

        if ($this->isTaskFinished($task)) {
            return;
        }

        if ($task['command_id'] === null) {
            // Ask Duplicati how the task is doing.
            if ($task['task_id'] !== null) {
                $task['command_id'] = $commandService->dispatch($this->registration, 'GET', '/api/v1/task/'.$task['task_id'])->id;
                $task['command_kind'] = 'status';
                $this->tasks[$backupId] = $task;
            }

            return;
        }

        $command = $this->findCommand($task['command_id']);

        if ($command === null) {
            $task = $this->fail($task, 'The request to the instance was lost.');
        } elseif (! $command->isFinished()) {
            return;
        } elseif ($command->status !== RemoteControlCommand::STATUS_COMPLETED) {
            $task = $this->fail($task, $command->error_message ?: 'The instance did not respond in time.');
        } else {
            $body = json_decode((string) $command->response_body, true);
            $body = is_array($body) ? $body : [];

            if ($task['command_kind'] === 'pre') {
                $task = $this->applyPreResponse($task, $command->response_status_code, $body, $commandService);
                $this->tasks[$backupId] = $task;

                return;
            }

            $task = $task['command_kind'] === 'start'
                ? $this->applyStartResponse($task, $command->response_status_code, $body)
                : $this->applyStatusResponse($task, $command->response_status_code, $body);
        }

        $task['command_id'] = null;
        $task['updated_at'] = now()->toIso8601String();
        $this->tasks[$backupId] = $task;
    }

    /** The pre-step succeeded: send the real start command. */
    private function applyPreResponse(array $task, ?int $statusCode, array $body, RemoteControlCommandService $commandService): array
    {
        if ($statusCode < 200 || $statusCode >= 300) {
            $task = $this->fail($task, self::remoteError($statusCode, $body));
            $task['command_id'] = null;

            return $task;
        }

        $task['command_id'] = $commandService->dispatch($this->registration, 'POST', (string) $task['next_path'], requestedByUserId: Auth::id())->id;
        $task['command_kind'] = 'start';
        $task['next_path'] = null;
        $task['updated_at'] = now()->toIso8601String();

        return $task;
    }

    private function applyStartResponse(array $task, ?int $statusCode, array $body): array
    {
        if ($statusCode !== 200 || ! isset($body['ID'])) {
            return $this->fail($task, $body['Error'] ?? $body['Message'] ?? "Duplicati answered with HTTP {$statusCode}.");
        }

        $task['task_id'] = (int) $body['ID'];
        $task['phase'] = 'waiting';

        return $task;
    }

    private function applyStatusResponse(array $task, ?int $statusCode, array $body): array
    {
        if ($statusCode === 404) {
            // Duplicati only caches recent task results; treat an unknown finished task as done.
            $task['phase'] = 'completed';
            $task['message'] = 'Finished. See the latest result below.';

            return $task;
        }

        if ($statusCode !== 200) {
            return $this->fail($task, $body['Error'] ?? "Duplicati answered with HTTP {$statusCode}.");
        }

        $task['phase'] = match ($body['Status'] ?? null) {
            'Running' => 'running',
            'Completed' => 'completed',
            'Failed' => 'failed',
            default => 'waiting',
        };
        $task['message'] = $task['phase'] === 'failed' ? ($body['ErrorMessage'] ?? 'The task failed.') : null;

        return $task;
    }

    private function fail(array $task, string $message): array
    {
        $task['phase'] = 'error';
        $task['message'] = $message;

        return $task;
    }

    /** Fetch the job list again (e.g. after a schedule change). */
    protected function refreshBackups(): void
    {
        if ($this->backupsCommandId === null) {
            $this->backupsCommandId = app(RemoteControlCommandService::class)->dispatch($this->registration, 'GET', '/api/v1/backups')->id;
        }
    }

    private function collectBackups(): void
    {
        if ($this->backupsCommandId === null) {
            return;
        }

        $command = $this->findCommand($this->backupsCommandId);

        if ($command !== null && ! $command->isFinished()) {
            return;
        }

        if ($command?->status === RemoteControlCommand::STATUS_COMPLETED && $command->response_status_code === 200) {
            $decoded = json_decode((string) $command->response_body, true);
            $this->backups = is_array($decoded) ? RemoteLogImportService::normalizeBackups($decoded) : [];
        }

        $this->backupsCommandId = null;
    }

    private function collectProgress(RemoteControlCommandService $commandService): void
    {
        if ($this->progressCommandId === null) {
            // Re-issue on every tick while the page stays open -- this is the "live" part.
            $this->progressCommandId = $commandService->dispatch($this->registration, 'GET', '/api/v1/progressstate')->id;

            return;
        }

        $command = $this->findCommand($this->progressCommandId);

        if ($command !== null && ! $command->isFinished()) {
            return;
        }

        if ($command?->status === RemoteControlCommand::STATUS_COMPLETED && $command->response_status_code === 200) {
            $decoded = json_decode((string) $command->response_body, true);
            $this->progress = is_array($decoded) ? $decoded : null;
            $this->progressError = null;
        } elseif ($command?->status === RemoteControlCommand::STATUS_COMPLETED && $command->response_status_code === 404) {
            $this->progress = null;
            $this->progressError = null;
        } else {
            $this->progress = null;
            $this->progressError = $command?->error_message ?? 'Unable to fetch progress.';
        }

        $this->progressCommandId = null;
    }

    /**
     * Latest stored result per Duplicati backup id for this machine.
     * Reports use "DB-<id>" as backup id, the remote API uses "<id>"; both are matched.
     *
     * @return Collection<string, BackupResult>
     */
    private function latestResultsByBackupId(): Collection
    {
        if (empty($this->backups)) {
            return collect();
        }

        $ids = collect($this->backups)->pluck('id');
        $candidates = $ids->concat($ids->map(fn ($id) => 'DB-'.$id))->all();

        $results = BackupResult::forUser(Auth::user())
            ->whereIn('backup_id', $candidates)
            ->where(function ($query) {
                $query->when($this->registration->backup_server_id, fn ($q) => $q->orWhere('backup_server_id', $this->registration->backup_server_id))
                    ->when($this->registration->machine_id, fn ($q) => $q->orWhere('extra->machine-id', $this->registration->machine_id));
            })
            ->orderByDesc('EndTime')
            ->get(['id', 'backup_server_id', 'backup_id', 'MainOperation', 'ParsedResult', 'EndTime', 'ErrorsActualLength', 'WarningsActualLength']);

        return $results
            ->groupBy(fn ($r) => preg_replace('/^DB-/', '', (string) $r->backup_id))
            ->map(fn ($group) => $group->first());
    }

    public function render()
    {
        return view('livewire.remote-control.show', [
            'lastResults' => $this->latestResultsByBackupId(),
            'importStatus' => Cache::get(ImportRemoteControlLogs::statusKey($this->registration->id)),
            'summary' => $this->jobSummary(),
            'detailsName' => collect($this->backups ?? [])->firstWhere('id', $this->detailsBackupId)['name'] ?? $this->detailsBackupId,
            'restoreName' => collect($this->backups ?? [])->firstWhere('id', $this->restoreBackupId)['name'] ?? $this->restoreBackupId,
        ]);
    }
}
