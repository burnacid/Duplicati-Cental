<?php

namespace App\Livewire\RemoteControl;

use App\Models\BackupResult;
use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Jobs\ImportRemoteControlLogs;
use App\Services\RemoteControlCommandService;
use App\Services\RemoteLogImportService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class Show extends Component
{
    public const OPERATIONS = [
        'run' => ['label' => 'Backup', 'path' => '/api/v1/backup/%s/run?skipQueue=false'],
        'repair' => ['label' => 'Repair', 'path' => '/api/v1/backup/%s/repair'],
    ];

    public RemoteControlRegistration $registration;

    public ?string $backupsCommandId = null;
    public ?string $progressCommandId = null;

    /**
     * Operations started from this page, per Duplicati backup id.
     *
     * @var array<string, array{
     *     operation: string, phase: string, task_id: ?int, message: ?string,
     *     command_id: ?string, command_kind: ?string, updated_at: string
     * }>
     */
    public array $tasks = [];

    public ?array $backups = null;
    public ?array $progress = null;
    public ?string $progressError = null;

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

        foreach (array_keys($this->tasks) as $backupId) {
            $this->advanceTask((string) $backupId, $commandService);
        }
    }

    public function runBackup(string $backupId, RemoteControlCommandService $commandService): void
    {
        $this->startOperation('run', $backupId, $commandService);
    }

    public function repairBackup(string $backupId, RemoteControlCommandService $commandService): void
    {
        $this->startOperation('repair', $backupId, $commandService);
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

    private function startOperation(string $operation, string $backupId, RemoteControlCommandService $commandService): void
    {
        if (! isset(self::OPERATIONS[$operation]) || $this->taskInFlight($backupId)) {
            return;
        }

        $command = $commandService->dispatch(
            $this->registration,
            'POST',
            sprintf(self::OPERATIONS[$operation]['path'], rawurlencode($backupId)),
            requestedByUserId: Auth::id(),
        );

        $this->tasks[$backupId] = [
            'operation' => self::OPERATIONS[$operation]['label'],
            'phase' => 'sending',
            'task_id' => null,
            'message' => null,
            'command_id' => $command->id,
            'command_kind' => 'start',
            'updated_at' => now()->toIso8601String(),
        ];
    }

    private function taskInFlight(string $backupId): bool
    {
        return isset($this->tasks[$backupId]) && ! $this->isTaskFinished($this->tasks[$backupId]);
    }

    /**
     * Moves a task forward: start command -> Duplicati task id -> poll task status until done.
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
                $task['command_id'] = $commandService->dispatch($this->registration, 'GET', '/api/v1/task/' . $task['task_id'])->id;
                $task['command_kind'] = 'status';
                $this->tasks[$backupId] = $task;
            }

            return;
        }

        $command = RemoteControlCommand::query()->find($task['command_id']);

        if ($command === null) {
            $task = $this->fail($task, 'The request to the instance was lost.');
        } elseif (! $command->isFinished()) {
            return;
        } elseif ($command->status !== RemoteControlCommand::STATUS_COMPLETED) {
            $task = $this->fail($task, $command->error_message ?: 'The instance did not respond in time.');
        } else {
            $body = json_decode((string) $command->response_body, true);
            $body = is_array($body) ? $body : [];

            $task = $task['command_kind'] === 'start'
                ? $this->applyStartResponse($task, $command->response_status_code, $body)
                : $this->applyStatusResponse($task, $command->response_status_code, $body);
        }

        $task['command_id'] = null;
        $task['updated_at'] = now()->toIso8601String();
        $this->tasks[$backupId] = $task;
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

    private function collectBackups(): void
    {
        if ($this->backupsCommandId === null) {
            return;
        }

        $command = RemoteControlCommand::query()->find($this->backupsCommandId);

        if ($command === null || ! $command->isFinished()) {
            return;
        }

        if ($command->status === RemoteControlCommand::STATUS_COMPLETED && $command->response_status_code === 200) {
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

        $command = RemoteControlCommand::query()->find($this->progressCommandId);

        if ($command === null || ! $command->isFinished()) {
            return;
        }

        if ($command->status === RemoteControlCommand::STATUS_COMPLETED && $command->response_status_code === 200) {
            $decoded = json_decode((string) $command->response_body, true);
            $this->progress = is_array($decoded) ? $decoded : null;
            $this->progressError = null;
        } elseif ($command->status === RemoteControlCommand::STATUS_COMPLETED && $command->response_status_code === 404) {
            $this->progress = null;
            $this->progressError = null;
        } else {
            $this->progress = null;
            $this->progressError = $command->error_message ?? 'Unable to fetch progress.';
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
        $candidates = $ids->concat($ids->map(fn ($id) => 'DB-' . $id))->all();

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
        ]);
    }
}
