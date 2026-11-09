<?php

namespace App\Livewire\Backups;

use App\Models\BackupResult;
use App\Models\BackupServer;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Component;
use Livewire\WithPagination;

class Index extends Component
{
    use WithPagination;

    public $confirmingBackupDeletion = false;
    public $backupToDelete = null;
    public $searchTerm = '';
    public $selectedServer = '';

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
            $query->where('backup_results.backup_name', 'like', '%' . $this->searchTerm . '%');
        }

        if ($this->selectedServer) {
            $query->where('backup_results.backup_server_id', $this->selectedServer);
        }

        return view('livewire.backups.index', [
            'backups' => $query->paginate(10),
            'servers' => BackupServer::ownedBy($userId)->orderBy('name')->get(['id', 'name']),
        ]);
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

                session()->flash('message', "Deleted {$deleted} stored " . Str::plural('result', $deleted) . '.');
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
