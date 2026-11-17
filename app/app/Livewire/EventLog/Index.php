<?php

namespace App\Livewire\EventLog;

use App\Models\BackupServer;
use App\Models\EventLog;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Event log: incoming reports from Duplicati (stored, ignored or rejected),
 * notification deliveries and heartbeat pings of the current user.
 */
#[Title('Event log')]
class Index extends Component
{
    use WithPagination;

    #[Url(except: '')]
    public string $category = '';

    #[Url(except: '')]
    public string $level = '';

    #[Url(as: 'server', except: '')]
    public string $serverId = '';

    public function updated($property): void
    {
        if (in_array($property, ['category', 'level', 'serverId'], true)) {
            $this->resetPage();
        }
    }

    public function clearFilters(): void
    {
        $this->reset(['category', 'level', 'serverId']);
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();

        $events = EventLog::forUser($user)
            ->with('backupServer:id,name')
            ->when(array_key_exists($this->category, EventLog::CATEGORIES), fn ($q) => $q->where('category', $this->category))
            ->when(in_array($this->level, EventLog::LEVELS, true), fn ($q) => $q->where('level', $this->level))
            ->when($this->serverId !== '', fn ($q) => $q->where('backup_server_id', $this->serverId))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        return view('livewire.event-log.index', [
            'events' => $events,
            'backupServers' => BackupServer::ownedBy($user)->orderBy('name')->get(['id', 'name']),
            'hasFilters' => $this->category !== '' || $this->level !== '' || $this->serverId !== '',
            'retentionDays' => (int) config('backups.event_log_retention_days'),
        ]);
    }
}
