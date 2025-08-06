<?php

namespace App\Livewire\Dashboard;

use Livewire\Component;
use App\Models\BackupServer;
use App\Models\BackupResult;

class Statistics extends Component
{
    public $backupServersCount;
    public $backupsCount;
    public $totalBackupVolume;
    public $totalBackupVolumeUnit;

    public function mount()
    {
        $backupServers = BackupServer::where('user_id',auth()->user()->id)->get();
        $this->backupServersCount = $backupServers->count();
        $this->backupsCount = 0;

        foreach ($backupServers as $backupServer) {
            $backupResults = $backupServer->backupResults->where('MainOperation', 'Backup');

            if($backupResults->count() > 0) {
                $this->backupsCount += $backupResults->unique('backup_id')->count();

                $latestBackups = $backupResults
                    ->groupBy('backup_id')
                    ->map(function ($group) {
                        return $group->sortByDesc('EndTime')->first();
                    });

                //$latestBackups = $backupResults->latest()->unique('backup_id');
                //$this->totalBackupVolume += $backupServer->backupResults->sum('SizeOfAddedFiles');
                foreach ($latestBackups as $latestBackup) {
                    $this->totalBackupVolume += $latestBackup->BackendStatistics['KnownFileSize'];
                }
            }
        }

        $formattedSize = $this->formatFileSize($this->totalBackupVolume);
        $this->totalBackupVolume = $formattedSize['size'];
        $this->totalBackupVolumeUnit = $formattedSize['unit'];
    }

    private function formatFileSize($bytes)
    {
        $units = ['MB', 'GB', 'TB'];
        $size = $bytes / 1048576; // Convert to MB
        $unit = 0;

        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return [
            'size' => round($size, 2),
            'unit' => $units[$unit]
        ];
    }

    public function render()
    {
        return view('livewire.dashboard.statistics');
    }
}
