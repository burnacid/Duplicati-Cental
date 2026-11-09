<?php

namespace App\Livewire\Dashboard;

use App\Enums\BackupStatus;
use App\Livewire\Concerns\FormatsBackupValues;
use App\Models\BackupServer;
use App\Services\BackupHealthService;
use Livewire\Component;

class Statistics extends Component
{
    use FormatsBackupValues;

    public function render(BackupHealthService $health)
    {
        $user = auth()->user();
        $jobs = $health->jobs($user);

        $totalVolume = $jobs->sum(fn ($job) => (float) data_get($job['last_result']?->BackendStatistics, 'KnownFileSize', 0));

        return view('livewire.dashboard.statistics', [
            'backupServersCount' => BackupServer::ownedBy($user)->count(),
            'backupsCount' => $jobs->count(),
            'totalBackupVolume' => $this->formatSize($totalVolume),
            'healthyCount' => $jobs->filter(fn ($j) => $j['last_status'] === BackupStatus::Success)->count(),
            'warningCount' => $jobs->filter(fn ($j) => $j['last_status'] === BackupStatus::Warning)->count(),
            'failedCount' => $jobs->filter(fn ($j) => $j['last_status']->isFailure())->count(),
            'overdueCount' => $jobs->where('is_overdue', true)->count(),
        ]);
    }
}
