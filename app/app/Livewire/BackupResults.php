<?php

namespace App\Livewire;

use App\Livewire\Concerns\FormatsBackupValues;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\RemoteControlRegistration;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

class BackupResults extends Component
{
    use FormatsBackupValues;
    use WithPagination;

    #[Url(as: 'server', except: '')]
    public string $selectedServerId = '';

    #[Url(as: 'backup', except: '')]
    public string $selectedBackupId = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $operation = '';

    #[Url(as: 'q', except: '')]
    public string $search = '';

    public function updated($property): void
    {
        if (in_array($property, ['selectedServerId', 'selectedBackupId', 'status', 'operation', 'search'], true)) {
            $this->resetPage();
        }

        if ($property === 'selectedServerId') {
            $this->selectedBackupId = '';
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['selectedServerId', 'selectedBackupId', 'status', 'operation', 'search']);
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();

        $query = BackupResult::forUser($user)
            ->with('backupServer')
            ->when($this->selectedServerId !== '', fn ($q) => $q->where('backup_server_id', $this->selectedServerId))
            ->when($this->selectedBackupId !== '', fn ($q) => $q->where('backup_id', $this->selectedBackupId))
            ->when($this->status === 'Failed', fn ($q) => $q->whereIn('ParsedResult', ['Error', 'Fatal']))
            ->when($this->status !== '' && $this->status !== 'Failed', fn ($q) => $q->where('ParsedResult', $this->status))
            ->when($this->operation !== '', fn ($q) => $q->where('MainOperation', $this->operation))
            ->when($this->search !== '', fn ($q) => $q->where('backup_name', 'like', '%' . $this->search . '%'))
            ->orderBy('EndTime', 'desc');

        $results = $query->paginate(15);

        $machineIds = $results->getCollection()->pluck('machine_id')->filter()->unique()->values();

        $claimedRegistrationsByMachineId = ($machineIds->isEmpty() || ! config('remote_control.enabled'))
            ? collect()
            : RemoteControlRegistration::query()
                ->where('claimed_by_user_id', $user->id)
                ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
                ->whereIn('machine_id', $machineIds)
                ->get()
                ->keyBy('machine_id');

        $backupJobs = BackupResult::forUser($user)
            ->when($this->selectedServerId !== '', fn ($q) => $q->where('backup_server_id', $this->selectedServerId))
            ->select('backup_id', 'backup_name')
            ->distinct()
            ->orderBy('backup_name')
            ->get();

        $operations = BackupResult::forUser($user)->distinct()->orderBy('MainOperation')->pluck('MainOperation');

        return view('livewire.backup-results', [
            'latestBackupResults' => $results,
            'claimedRegistrationsByMachineId' => $claimedRegistrationsByMachineId,
            'backupServers' => BackupServer::ownedBy($user)->orderBy('name')->get(['id', 'name']),
            'backupJobs' => $backupJobs,
            'operations' => $operations,
            'hasFilters' => $this->selectedServerId !== '' || $this->selectedBackupId !== '' || $this->status !== '' || $this->operation !== '' || $this->search !== '',
        ]);
    }
}
