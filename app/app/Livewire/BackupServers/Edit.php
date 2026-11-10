<?php

namespace App\Livewire\BackupServers;

use App\Models\BackupServer;
use Livewire\Component;

class Edit extends Component
{
    public BackupServer $backupServer;

    public $name;

    public $description;

    protected $rules = [
        'name' => 'required|min:3|max:255',
        'description' => 'nullable|string|max:2000',
    ];

    public function mount(BackupServer $backupServer)
    {
        $this->authorize('update', $backupServer);

        $this->backupServer = $backupServer;
        $this->name = $backupServer->name;
        $this->description = $backupServer->description;
    }

    public function render()
    {
        return view('livewire.backup-servers.edit');
    }

    public function save()
    {
        $this->validate();
        $this->authorize('update', $this->backupServer);

        $this->backupServer->update([
            'name' => $this->name,
            'description' => $this->description,
        ]);

        session()->flash('message', 'Backup server saved.');

        return redirect()->route('backup-servers.index');
    }
}
