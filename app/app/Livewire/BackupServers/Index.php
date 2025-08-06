<?php

namespace App\Livewire\BackupServers;

use App\Models\BackupServer;
use Livewire\Component;

class Index extends Component
{
    public $deleteId = null;

    public function render()
    {
        return view('livewire.backup-servers.index', [
            'backupServers' => BackupServer::all(),
        ]);
    }

    public function confirmDelete($id)
    {
        $this->deleteId = $id;
        $this->dispatch('openDeleteModal');
    }

    public function deleteServer($serverId)
    {
        $server = BackupServer::find($serverId);
        if ($server) {
            $server->delete();
            session()->flash('message', 'Backup server deleted successfully.');
        }
    }
}
