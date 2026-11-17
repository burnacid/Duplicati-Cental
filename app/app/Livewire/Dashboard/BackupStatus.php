<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\FormatsBackupValues;
use App\Livewire\RemoteControl\Show;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Services\BackupHealthService;
use App\Services\RemoteControlCommandService;
use App\Support\DuplicatiJobConfig;
use Livewire\Component;

class BackupStatus extends Component
{
    use FormatsBackupValues;

    public ?string $flash = null;

    /** 'info', 'success' or 'error'. */
    public string $flashVariant = 'info';

    /** The run request we are waiting for, and the job's name. */
    public ?string $runCommandId = null;

    public ?string $runJobName = null;

    /**
     * Start a backup on a claimed, connected Duplicati instance.
     */
    public function runNow(string $serverId, string $backupId, RemoteControlCommandService $commands): void
    {
        $registration = $this->registrationsByServer()->get($serverId);

        if (! $registration || ! $registration->agentState?->is_connected) {
            $this->setFlash('This server is not connected for remote control.', 'error');

            return;
        }

        $this->runJobName = (string) (BackupResult::forUser(auth()->user())
            ->where('backup_server_id', $serverId)
            ->where('backup_id', $backupId)
            ->value('backup_name') ?? $backupId);

        // Reports identify a job as "DB-<id>"; Duplicati's API wants the plain id.
        $this->runCommandId = $commands->dispatch(
            $registration,
            'POST',
            '/api/v1/backup/'.rawurlencode(DuplicatiJobConfig::apiBackupId($backupId)).'/run?skipQueue=false',
            requestedByUserId: auth()->id(),
        )->id;

        $this->setFlash("Starting {$this->runJobName}…");
    }

    /** Polled while a run request is open: show what the instance answered. */
    public function checkRun(): void
    {
        if ($this->runCommandId === null) {
            return;
        }

        $command = RemoteControlCommand::query()
            ->where('requested_by_user_id', auth()->id())
            ->find($this->runCommandId);

        if ($command !== null && ! $command->isFinished()) {
            return;
        }

        $name = $this->runJobName;
        $this->runCommandId = null;

        if ($command === null || $command->status !== RemoteControlCommand::STATUS_COMPLETED) {
            $this->setFlash("Could not start {$name}: ".($command?->error_message ?: 'the instance did not respond in time.'), 'error');

            return;
        }

        $body = json_decode((string) $command->response_body, true);
        $body = is_array($body) ? $body : [];
        $code = (int) $command->response_status_code;

        if ($code >= 200 && $code < 300 && ($body['Success'] ?? true) !== false) {
            $this->setFlash("Backup of {$name} started. The result appears here when it finishes.", 'success');
        } else {
            $this->setFlash("Could not start {$name}: ".Show::remoteError($code, $body), 'error');
        }
    }

    private function setFlash(string $message, string $variant = 'info'): void
    {
        $this->flash = $message;
        $this->flashVariant = $variant;
    }

    public function render(BackupHealthService $health)
    {
        $user = auth()->user();
        $jobs = $health->jobs($user);

        return view('livewire.dashboard.backup-status', [
            'backupServers' => BackupServer::ownedBy($user)->orderBy('name')->get(),
            'statusData' => $health->dailyStatusByServer($user),
            'jobsByServer' => $jobs->groupBy('server_id'),
            'attention' => $jobs->filter(fn ($j) => $j['is_overdue'] || $j['last_status']->isFailure())->values(),
            'registrationsByServer' => $this->registrationsByServer(),
            'latestBackupResults' => BackupResult::forUser($user)
                ->with('backupServer')
                ->orderBy('EndTime', 'desc')
                ->take(5)
                ->get(),
        ]);
    }

    private function registrationsByServer()
    {
        // Disabled remote control: no badges, no Run buttons, runNow() refuses.
        if (! config('remote_control.enabled')) {
            return collect();
        }

        return RemoteControlRegistration::query()
            ->with('agentState')
            ->where('claimed_by_user_id', auth()->id())
            ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
            ->whereNotNull('backup_server_id')
            ->get()
            ->keyBy('backup_server_id');
    }
}
