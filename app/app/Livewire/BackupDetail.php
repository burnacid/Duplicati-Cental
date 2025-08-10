<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\BackupResult;

class BackupDetail extends Component
{
    public $backupResult;

    public function mount($id)
    {
        $this->backupResult = BackupResult::with('backupServer')->findOrFail($id);
    }

    public function render()
    {
        return view('livewire.backup-detail');
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
