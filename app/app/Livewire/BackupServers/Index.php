<?php

namespace App\Livewire\BackupServers;

use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Support\Snooze;
use Flux\Flux;
use Illuminate\Support\Str;
use Livewire\Component;

class Index extends Component
{
    public ?string $deleteId = null;

    public ?string $regenerateId = null;

    public function render()
    {
        $servers = BackupServer::ownedBy(auth()->user())
            ->select('backup_servers.*')
            ->addSelect([
                'backup_jobs_count' => BackupResult::selectRaw('COUNT(DISTINCT backup_id)')->whereColumn('backup_server_id', 'backup_servers.id'),
                'last_report_at' => BackupResult::selectRaw('MAX(EndTime)')->whereColumn('backup_server_id', 'backup_servers.id'),
            ])
            ->orderBy('name')
            ->get();

        return view('livewire.backup-servers.index', [
            'backupServers' => $servers,
        ]);
    }

    public function confirmDelete(string $id): void
    {
        $this->deleteId = $id;
        Flux::modal('delete-server')->show();
    }

    public function deleteServer(): void
    {
        $server = $this->deleteId ? BackupServer::ownedBy(auth()->user())->find($this->deleteId) : null;

        if ($server) {
            $this->authorize('delete', $server);
            $server->delete();
            session()->flash('message', 'Backup server deleted.');
        }

        $this->deleteId = null;
        Flux::modal('delete-server')->close();
    }

    public function snoozeServer(string $id, string $duration): void
    {
        abort_unless(array_key_exists($duration, Snooze::OPTIONS), 422);

        $server = BackupServer::ownedBy(auth()->user())->findOrFail($id);
        $this->authorize('update', $server);
        $server->update(['muted_until' => Snooze::until($duration)]);

        session()->flash('message', "Alerts for {$server->name} snoozed ".Snooze::label($server->muted_until).'.');
    }

    public function resumeServer(string $id): void
    {
        $server = BackupServer::ownedBy(auth()->user())->findOrFail($id);
        $this->authorize('update', $server);
        $server->update(['muted_until' => null]);

        session()->flash('message', "Alerts for {$server->name} resumed.");
    }

    public function confirmRegenerate(string $id): void
    {
        $this->regenerateId = $id;
        Flux::modal('regenerate-key')->show();
    }

    public function regenerateKey(): void
    {
        $server = $this->regenerateId ? BackupServer::ownedBy(auth()->user())->find($this->regenerateId) : null;

        if ($server) {
            $this->authorize('update', $server);
            $server->api_key = Str::random(64);
            $server->save();
            session()->flash('message', "New API key generated for {$server->name}. Update the URL in Duplicati.");
        }

        $this->regenerateId = null;
        Flux::modal('regenerate-key')->close();
    }
}
