<?php

namespace App\Livewire\BackupServers;

use App\Models\BackupServer;
use Livewire\Component;

class Create extends Component
{
    public $name;
    public $description;

    protected $rules = [
        'name' => 'required|min:3|max:255',
        'description' => 'nullable|string|max:2000',
    ];

    public function render()
    {
        return view('livewire.backup-servers.create');
    }

    public function save()
    {
        $this->validate();

        BackupServer::create([
            'name' => $this->name,
            'description' => $this->description,
            'user_id' => auth()->id(),
        ]);

        session()->flash('message', 'Backup server saved.');

        return redirect()->route('backup-servers.index');
    }
}
