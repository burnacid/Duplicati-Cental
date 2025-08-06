<?php

namespace App\Livewire\BackupServers;

use App\Models\BackupServer;
use Livewire\Component;

class Edit extends Component
{
    public BackupServer $backupServer;
    public $name;
    public $description;
    public $api_key;

    protected $rules = [
        'name' => 'required|min:3'
    ];

    public function mount(BackupServer $backupServer)
    {
        $this->backupServer = $backupServer;
        $this->name = $backupServer->name;
        $this->description = $backupServer->description;
        $this->api_key = $backupServer->api_key;
    }

    public function render()
    {
        return view('livewire.backup-servers.edit');
    }

    public function save()
    {
        $this->validate();

        $this->backupServer->update([
            'name' => $this->name,
            'description' => $this->description,
        ]);

        return redirect()->route('backup-servers.index');
    }
}
