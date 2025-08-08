<?php

namespace App\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use App\Models\BackupResult;
use App\Models\BackupServer;

class BackupResults extends Component
{
    use WithPagination;

    public $backupServers;
    public $selectedServerId;

    protected $paginationTheme = 'tailwind';

    public function mount()
    {
        $this->backupServers = BackupServer::where('user_id', auth()->user()->id)->get();
        $this->selectedServerId = '';
    }

    public function render()
    {
        $query = BackupResult::with('backupServer')
            ->orderBy('EndTime', 'desc');

        if ($this->selectedServerId) {
            $query->where('backup_server_id', $this->selectedServerId);
        }

        $latestBackupResults = $query->paginate(10);

        return view('livewire.backup-results', [
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
}
