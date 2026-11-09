<?php

namespace App\Livewire\Dashboard;

use App\Livewire\Concerns\FormatsBackupValues;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\RemoteControlRegistration;
use App\Services\BackupHealthService;
use App\Services\RemoteControlCommandService;
use Livewire\Component;

class BackupStatus extends Component
{
    use FormatsBackupValues;

    public ?string $flash = null;

    /**
     * Start a backup on a claimed, connected Duplicati instance.
     */
    public function runNow(string $serverId, string $backupId, RemoteControlCommandService $commands): void
    {
        $registration = $this->registrationsByServer()->get($serverId);

        if (! $registration || ! $registration->agentState?->is_connected) {
            $this->flash = 'This server is not connected for remote control.';

            return;
        }

        $commands->dispatch(
            $registration,
            'POST',
            '/api/v1/backup/' . rawurlencode($backupId) . '/run?skipQueue=false',
            requestedByUserId: auth()->id(),
        );

        $this->flash = 'Backup start requested.';
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
