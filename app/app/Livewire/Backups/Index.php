<?php

namespace App\Livewire\Backups;

use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Services\BackupHealthService;
use App\Support\Snooze;
use Carbon\CarbonInterval;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public $confirmingBackupDeletion = false;

    public $backupToDelete = null;

    public $searchTerm = '';

    public $selectedServer = '';

    // Job settings modal
    public ?string $settingsServerId = null;

    public ?string $settingsBackupId = null;

    public string $settingsBackupName = '';

    public bool $monitored = true;

    public string $expectedIntervalHours = '';

    public ?string $learnedInterval = null;

    public ?string $mutedLabel = null;

    public string $snoozeFor = '1d';

    // Alert rules (empty = off)
    public string $alertSizeChangePercent = '';

    public string $alertDurationFactor = '';

    public string $alertUnchangedRuns = '';

    public string $alertQuotaFreePercent = '';

    public string $heartbeatUrl = '';

    public bool $onStatusPage = false;

    public string $statusPageName = '';

    public function updatedSearchTerm(): void
    {
        $this->resetPage();
    }

    public function updatedSelectedServer(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $userId = auth()->id();

        $query = DB::table('backup_results')
            ->join('backup_servers', 'backup_results.backup_server_id', '=', 'backup_servers.id')
            ->where('backup_servers.user_id', $userId)
            ->select(
                'backup_results.backup_server_id',
                'backup_servers.name',
                'backup_results.backup_name',
                'backup_results.backup_id',
                DB::raw('COUNT(*) as total_backups'),
                DB::raw('MAX(backup_results.EndTime) as last_backup_time'),
            )
            ->selectRaw("SUM(CASE WHEN backup_results.ParsedResult = 'Success' THEN 1 ELSE 0 END) as successful_backups")
            ->selectRaw("SUM(CASE WHEN backup_results.ParsedResult = 'Warning' THEN 1 ELSE 0 END) as warning_backups")
            ->selectRaw("SUM(CASE WHEN backup_results.ParsedResult IN ('Error', 'Fatal') THEN 1 ELSE 0 END) as failed_backups")
            ->groupBy('backup_results.backup_server_id', 'backup_servers.name', 'backup_results.backup_name', 'backup_results.backup_id')
            ->orderBy('backup_servers.name')
            ->orderBy('backup_results.backup_name');

        if ($this->searchTerm) {
            $query->where('backup_results.backup_name', 'like', '%'.$this->searchTerm.'%');
        }

        if ($this->selectedServer) {
            $query->where('backup_results.backup_server_id', $this->selectedServer);
        }

        $servers = BackupServer::ownedBy($userId)->orderBy('name')->get(['id', 'name', 'muted_until']);

        return view('livewire.backups.index', [
            'backups' => $query->paginate(10),
            'servers' => $servers,
            'mutedServers' => $servers->filter->isMuted()->keyBy('id'),
            'jobSettings' => BackupJobSetting::query()
                ->whereIn('backup_server_id', $servers->pluck('id'))
                ->get()
                ->keyBy(fn (BackupJobSetting $s) => $s->backup_server_id.'|'.$s->backup_id),
        ]);
    }

    public function openSettings(string $serverId, string $backupId): void
    {
        $server = $this->ownedServer($serverId);
        $setting = $server->jobSettings()->where('backup_id', $backupId)->first();
        $job = app(BackupHealthService::class)->jobs(auth()->user())
            ->first(fn ($j) => $j['server_id'] === $server->id && $j['backup_id'] === $backupId);

        $this->resetValidation();
        $this->settingsServerId = $server->id;
        $this->settingsBackupId = $backupId;
        $this->settingsBackupName = $job['backup_name'] ?? $backupId;
        $this->monitored = $setting?->monitored ?? true;
        $this->expectedIntervalHours = (string) ($setting?->expected_interval_hours ?? '');
        $this->learnedInterval = isset($job['learned_interval'])
            ? CarbonInterval::seconds($job['learned_interval'])->cascade()->forHumans(['short' => true, 'parts' => 2])
            : null;
        $this->mutedLabel = Snooze::label($setting?->muted_until);
        $this->snoozeFor = '1d';
        $this->alertSizeChangePercent = (string) ($setting?->alert_size_change_percent ?? '');
        $this->alertDurationFactor = (string) ($setting?->alert_duration_factor ?? '');
        $this->alertUnchangedRuns = (string) ($setting?->alert_unchanged_runs ?? '');
        $this->alertQuotaFreePercent = (string) ($setting?->alert_quota_free_percent ?? '');
        $this->heartbeatUrl = (string) ($setting?->heartbeat_url ?? '');
        $this->onStatusPage = $setting?->on_status_page ?? false;
        $this->statusPageName = (string) ($setting?->status_page_name ?? '');

        Flux::modal('job-settings')->show();
    }

    public function saveSettings(): void
    {
        $this->validate([
            'monitored' => 'boolean',
            'expectedIntervalHours' => 'nullable|integer|min:1|max:8760',
            'alertSizeChangePercent' => 'nullable|integer|min:1|max:10000',
            'alertDurationFactor' => 'nullable|numeric|min:1.1|max:100',
            'alertUnchangedRuns' => 'nullable|integer|min:1|max:1000',
            'alertQuotaFreePercent' => 'nullable|integer|min:1|max:99',
            'heartbeatUrl' => 'nullable|url:http,https|max:2000',
            'onStatusPage' => 'boolean',
            'statusPageName' => 'nullable|string|max:100',
        ], [], [
            'expectedIntervalHours' => 'expected interval',
            'alertSizeChangePercent' => 'size change',
            'alertDurationFactor' => 'duration factor',
            'alertUnchangedRuns' => 'unchanged runs',
            'alertQuotaFreePercent' => 'free space',
            'heartbeatUrl' => 'heartbeat URL',
            'statusPageName' => 'public name',
        ]);

        $nullable = fn (string $value, string $cast) => $value === '' ? null : ($cast === 'float' ? (float) $value : (int) $value);

        $this->settingRecord()->fill([
            'monitored' => $this->monitored,
            'expected_interval_hours' => $nullable($this->expectedIntervalHours, 'int'),
            'alert_size_change_percent' => $nullable($this->alertSizeChangePercent, 'int'),
            'alert_duration_factor' => $nullable($this->alertDurationFactor, 'float'),
            'alert_unchanged_runs' => $nullable($this->alertUnchangedRuns, 'int'),
            'alert_quota_free_percent' => $nullable($this->alertQuotaFreePercent, 'int'),
            'heartbeat_url' => trim($this->heartbeatUrl) === '' ? null : trim($this->heartbeatUrl),
            'on_status_page' => $this->onStatusPage,
            'status_page_name' => trim($this->statusPageName) === '' ? null : trim($this->statusPageName),
        ])->save();

        session()->flash('message', "Settings saved for \"{$this->settingsBackupName}\".");
        Flux::modal('job-settings')->close();
    }

    public function snoozeJob(): void
    {
        $this->validate(['snoozeFor' => ['required', Rule::in(array_keys(Snooze::OPTIONS))]]);

        $setting = $this->settingRecord();
        $setting->fill(['muted_until' => Snooze::until($this->snoozeFor)])->save();
        $this->mutedLabel = Snooze::label($setting->muted_until);
    }

    public function resumeJob(): void
    {
        $this->settingRecord()->fill(['muted_until' => null])->save();
        $this->mutedLabel = null;
    }

    /** The settings row of the job open in the modal (created on first save). */
    private function settingRecord(): BackupJobSetting
    {
        $server = $this->ownedServer((string) $this->settingsServerId);

        return $server->jobSettings()->firstOrNew(['backup_id' => (string) $this->settingsBackupId]);
    }

    private function ownedServer(string $serverId): BackupServer
    {
        $server = BackupServer::ownedBy(auth()->user())->findOrFail($serverId);
        $this->authorize('update', $server);

        return $server;
    }

    public function confirmBackupDeletion($serverId, $backupId)
    {
        $this->confirmingBackupDeletion = true;
        $this->backupToDelete = [
            'server_id' => (string) $serverId,
            'backup_id' => (string) $backupId,
        ];

        Flux::modal('delete-backup-history')->show();
    }

    public function deleteBackupHistory()
    {
        if ($this->backupToDelete) {
            $server = BackupServer::ownedBy(auth()->user())->find($this->backupToDelete['server_id']);

            if ($server) {
                $this->authorize('delete', $server);

                $deleted = BackupResult::where('backup_server_id', $server->id)
                    ->where('backup_id', $this->backupToDelete['backup_id'])
                    ->delete();

                session()->flash('message', "Deleted {$deleted} stored ".Str::plural('result', $deleted).'.');
                $this->dispatch('backup-deleted');
            }
        }

        $this->cancelBackupDeletion();
    }

    public function cancelBackupDeletion()
    {
        $this->confirmingBackupDeletion = false;
        $this->backupToDelete = null;

        Flux::modal('delete-backup-history')->close();
    }
}
