<?php

namespace App\Livewire\Backups;

use App\Models\BackupResult;
use Illuminate\Support\Facades\DB;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public $confirmingBackupDeletion = false;
    public $backupToDelete = null;
    public $searchTerm = '';
    public $selectedServer = '';

    public function render()
    {
        $query = DB::table('backup_results')
            ->select('backup_server_id', 'backup_name', 'backup_id',
                DB::raw('COUNT(*) as total_backups'),
                DB::raw('MAX(EndTime) as last_backup_time'),
                DB::raw('SUM(CASE WHEN ParsedResult = "Success" THEN 1 ELSE 0 END) as successful_backups'),
                DB::raw('SUM(CASE WHEN ParsedResult = "Warning" THEN 1 ELSE 0 END) as warning_backups'),
                DB::raw('SUM(CASE WHEN ParsedResult = "Fatal" THEN 1 ELSE 0 END) as failed_backups'))
            ->join('backup_servers', 'backup_results.backup_server_id', '=', 'backup_servers.id')
            ->groupBy('backup_server_id', 'backup_name', 'backup_id');

        if ($this->searchTerm) {
            $query->where('backup_name', 'like', '%' . $this->searchTerm . '%');
        }

        if ($this->selectedServer) {
            $query->where('backup_server_id', $this->selectedServer);
        }

        $backups = $query->paginate(10);
        $servers = DB::table('backup_servers')->select('id', 'name')->get();

        return view('livewire.backups.index', [
            'backups' => $backups,
            'servers' => $servers
        ]);
    }

    public function confirmBackupDeletion($serverId, $backupId)
    {
        $this->confirmingBackupDeletion = true;
        $this->backupToDelete = [
            'server_id' => $serverId,
            'backup_id' => $backupId
        ];
    }

    public function deleteBackupHistory()
    {
        if ($this->backupToDelete) {
            BackupResult::where('backup_server_id', $this->backupToDelete['server_id'])
                ->where('backup_id', $this->backupToDelete['backup_id'])
                ->delete();

            $this->confirmingBackupDeletion = false;
            $this->backupToDelete = null;

            $this->dispatch('backup-deleted');
        }
    }

    public function cancelDeletion()
    {
        $this->confirmingBackupDeletion = false;
        $this->backupToDelete = null;
    }
}
