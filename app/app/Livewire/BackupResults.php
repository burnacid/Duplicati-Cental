<?php

namespace App\Livewire;

use App\Livewire\Concerns\FormatsBackupValues;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\RemoteControlRegistration;
use Illuminate\Support\Carbon;
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

    /** Period of the size trend chart, shown when one job is selected. */
    #[Url(as: 'days', except: 90)]
    public int $chartDays = 90;

    public const CHART_PERIODS = [30, 90, 365];

    /** More points than this are reduced to the last run per day. */
    private const CHART_MAX_POINTS = 400;

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
            ->when($this->search !== '', fn ($q) => $q->where('backup_name', 'like', '%'.$this->search.'%'))
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
            'sizeTrend' => $this->sizeTrend(),
        ]);
    }

    /**
     * Source and destination size per Backup run of the selected job.
     *
     * @return list<array{t: int, source: int, backup: int}>|null null when not exactly one job is selected
     */
    private function sizeTrend(): ?array
    {
        if ($this->selectedServerId === '' || $this->selectedBackupId === '') {
            return null;
        }

        $days = in_array($this->chartDays, self::CHART_PERIODS, true) ? $this->chartDays : 90;

        $points = BackupResult::forUser(auth()->user())
            ->where('backup_server_id', $this->selectedServerId)
            ->where('backup_id', $this->selectedBackupId)
            ->where('MainOperation', 'Backup')
            ->where('EndTime', '>=', now()->utc()->subDays($days)->format('Y-m-d H:i:s'))
            ->orderBy('EndTime')
            ->get(['id', 'EndTime', 'SizeOfExaminedFiles', 'BackendStatistics'])
            ->map(fn (BackupResult $r) => [
                't' => $r->EndTime->getTimestamp(),
                'source' => (int) $r->SizeOfExaminedFiles,
                'backup' => (int) ($r->BackendStatistics['KnownFileSize'] ?? 0),
            ])
            ->filter(fn ($p) => $p['source'] > 0 || $p['backup'] > 0);

        if ($points->count() > self::CHART_MAX_POINTS) {
            $timezone = config('app.timezone');
            $points = $points->groupBy(fn ($p) => Carbon::createFromTimestamp($p['t'])->setTimezone($timezone)->format('Y-m-d'))
                ->map(fn ($day) => $day->last());
        }

        return $points->values()->all();
    }
}
