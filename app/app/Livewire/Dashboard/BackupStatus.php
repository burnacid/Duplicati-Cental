<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\BackupServer;
use App\Models\BackupResult;
use Carbon\Carbon;

class BackupStatus extends Component
{
    use WithPagination;

    public $backupServers;
    public $statusData = [];
    public $backupStatusData = [];
    public $selectedServerId;

    protected $paginationTheme = 'tailwind';

    public function mount()
    {
        $this->backupServers = BackupServer::where('user_id', auth()->user()->id)->with('backupResults')->get();
        $this->loadStatusData();
        $this->loadBackupStatusData();
        $this->selectedServerId = $this->backupServers->first()->id ?? null;
    }


    private function loadStatusData()
    {
        $endDate = Carbon::today();
        $startDate = $endDate->copy()->subDays(29);

        foreach ($this->backupServers as $server) {
            $serverData = [];
            for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
                $dayResults = $server->backupResults()
                    ->whereDate('EndTime', $date)
                    ->get();

                if ($dayResults->isEmpty()) {
                    $status = 'gray'; // No backups
                } elseif ($dayResults->contains('ParsedResult', 'Error')) {
                    $status = 'red'; // At least one backup has errors
                } elseif ($dayResults->contains('ParsedResult', 'Warning')) {
                    $status = 'orange'; // No errors, but at least one backup has warnings
                } else {
                    $status = '#5cdd8b'; // All backups successful
                }

                $serverData[] = [
                    'date' => $date->format('Y-m-d'),
                    'status' => $status
                ];
            }
            $this->statusData[$server->id] = $serverData;
        }
    }

    private function formatDuration($duration)
    {
        $parts = explode(':', $duration);
        $hours = intval($parts[0]);
        $minutes = intval($parts[1]);
        $seconds = intval($parts[2]);

        $result = [];
        if ($hours > 0) {
            $result[] = $hours . ' hour' . ($hours > 1 ? 's' : '');
        }
        if ($minutes > 0) {
            $result[] = $minutes . ' minute' . ($minutes > 1 ? 's' : '');
        }
        if ($seconds > 0 || (empty($result) && $seconds == 0)) {
            $result[] = $seconds . ' second' . ($seconds != 1 ? 's' : '');
        }

        return implode(', ', $result);
    }

    private function loadBackupStatusData()
    {
        foreach ($this->backupServers as $server) {
            $serverBackups = $server->backupResults()
                ->select('backup_id', 'backup_name')
                ->distinct('backup_id')
                ->get();

            $latestBackupResult = $server->backupResults()
                ->orderBy('EndTime', 'desc')
                ->first();

            $serverVersion = $latestBackupResult ? $latestBackupResult->Version : 'Unknown';

            $this->backupStatusData[$server->id] = [
                'version' => $serverVersion,
                'backups' => []
            ];

            foreach ($serverBackups as $backup) {
                $backupResults = $server->backupResults()
                    ->where('backup_id', $backup->backup_id)
                    ->orderBy('EndTime', 'desc')
                    ->take(30)
                    ->get();

                $backupStatus = [];
                foreach ($backupResults as $result) {
                    if ($result->ParsedResult == 'Fatal') {
                        $status = 'red';
                    } elseif ($result->ParsedResult == 'Warning') {
                        $status = 'orange';
                    } else {
                        $status = '#5cdd8b';
                    }

                    $backupStatus[] = [
                        'date' => $result->EndTime,
                        'status' => $status
                    ];
                }

                $this->backupStatusData[$server->id]['backups'][$backup->backup_id] = [
                    'name' => $backup->backup_name,
                    'status' => $backupStatus
                ];
            }
        }
    }

    public function render()
    {
        $latestBackupResults = BackupResult::with('backupServer')
            ->orderBy('EndTime', 'desc')
            ->take(5)  // Limit to 5 results, adjust as needed
            ->get();

        return view('livewire.dashboard.backup-status', [
            'backupServers' => $this->backupServers,
            'statusData' => $this->statusData,
            'backupStatusData' => $this->backupStatusData,
            'latestBackupResults' => $latestBackupResults,
        ]);
    }

    public function updatedSelectedServerId()
    {
        $this->resetPage();
    }

    private function formatSize($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}
