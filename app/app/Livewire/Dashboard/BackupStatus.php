<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;
use App\Models\BackupServer;
use App\Models\BackupResult;
use Carbon\Carbon;

class BackupStatus extends Component
{
    public $backupServers;
    public $statusData = [];
    public $backupStatusData = [];

    public function mount()
    {
        $this->backupServers = BackupServer::where('user_id', auth()->user()->id)->with('backupResults')->get();
        $this->loadStatusData();
        $this->loadBackupStatusData();
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
                } elseif ($dayResults->contains('HasErrors', true)) {
                    $status = 'red'; // At least one backup has errors
                } elseif ($dayResults->contains('HasWarnings', true)) {
                    $status = 'yellow'; // No errors, but at least one backup has warnings
                } else {
                    $status = 'green'; // All backups successful
                }

                $serverData[] = [
                    'date' => $date->format('Y-m-d'),
                    'status' => $status
                ];
            }
            $this->statusData[$server->id] = $serverData;
        }
    }

    private function loadBackupStatusData()
    {
        foreach ($this->backupServers as $server) {
            $serverBackups = $server->backupResults()
                ->select('backup_id', 'backup_name')
                ->distinct('backup_id')
                ->get();

            foreach ($serverBackups as $backup) {
                $backupResults = $server->backupResults()
                    ->where('backup_id', $backup->backup_id)
                    ->orderBy('EndTime', 'desc')
                    ->take(30)
                    ->get();

                $backupStatus = [];
                foreach ($backupResults as $result) {
                    if ($result->HasErrors) {
                        $status = 'red';
                    } elseif ($result->HasWarnings) {
                        $status = 'yellow';
                    } else {
                        $status = 'green';
                    }

                    $backupStatus[] = [
                        'date' => $result->EndTime,
                        'status' => $status
                    ];
                }

                $this->backupStatusData[$server->id][$backup->backup_id] = [
                    'name' => $backup->backup_name,
                    'status' => $backupStatus
                ];
            }
        }
    }


    public function render()
    {
        return view('livewire.dashboard.backup-status');
    }
}
