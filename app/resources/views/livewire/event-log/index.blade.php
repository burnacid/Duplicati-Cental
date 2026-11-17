<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md">
    <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
        <flux:heading size="xl">Event Log</flux:heading>
        @if($hasFilters)
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">Clear filters</flux:button>
        @endif
    </div>
    <flux:subheading class="mb-4">
        Every report Duplicati sends (also the rejected ones), notifications and heartbeat pings.
        @if($retentionDays > 0)
            Entries are kept {{ $retentionDays }} days.
        @endif
    </flux:subheading>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3 mb-6">
        <flux:select wire:model.live="category" aria-label="Category">
            <flux:select.option value="">All events</flux:select.option>
            @foreach(\App\Models\EventLog::CATEGORIES as $key => $label)
                <flux:select.option value="{{ $key }}">{{ $label }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="level" aria-label="Level">
            <flux:select.option value="">All levels</flux:select.option>
            <flux:select.option value="info">Info</flux:select.option>
            <flux:select.option value="warning">Warning</flux:select.option>
            <flux:select.option value="error">Error</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="serverId" aria-label="Server">
            <flux:select.option value="">All servers</flux:select.option>
            @foreach($backupServers as $server)
                <flux:select.option value="{{ $server->id }}">{{ $server->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="bg-white dark:bg-zinc-900 shadow-sm rounded-lg divide-y divide-zinc-200 dark:divide-zinc-700">
        @forelse($events as $event)
            <div class="p-4" wire:key="event-{{ $event->id }}" x-data="{ open: false }">
                <div class="flex flex-wrap items-start gap-x-3 gap-y-1">
                    <flux:badge size="sm" :color="$event->levelColor()">{{ ucfirst($event->level) }}</flux:badge>
                    <flux:badge size="sm" variant="outline">{{ \App\Models\EventLog::CATEGORIES[$event->category] ?? $event->category }}</flux:badge>
                    <div class="min-w-0 flex-1 text-sm text-zinc-900 dark:text-white break-words">
                        {{ $event->message }}
                        @if(isset($event->context['backup_result_id']))
                            <a href="{{ route('backup-detail', $event->context['backup_result_id']) }}" wire:navigate class="ms-1 text-indigo-600 dark:text-indigo-400 hover:underline">View result</a>
                        @endif
                    </div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400 whitespace-nowrap" title="{{ $event->created_at->timezone(config('app.timezone'))->format('Y-m-d H:i:s') }}">
                        {{ $event->created_at->diffForHumans() }}
                    </div>
                </div>
                <div class="mt-1 flex flex-wrap gap-x-3 text-xs text-zinc-500 dark:text-zinc-400">
                    @if($event->backupServer)
                        <span>{{ $event->backupServer->name }}</span>
                    @endif
                    @if(isset($event->context['ip']))
                        <span>from {{ $event->context['ip'] }}</span>
                    @endif
                    @if(! empty($event->context))
                        <button type="button" class="hover:underline" x-on:click="open = ! open" x-text="open ? 'Hide details' : 'Details'">Details</button>
                    @endif
                </div>
                @if(! empty($event->context))
                    <pre x-show="open" x-cloak class="mt-2 max-h-80 overflow-auto rounded bg-zinc-100 dark:bg-zinc-800 p-3 text-xs text-zinc-700 dark:text-zinc-300 whitespace-pre-wrap break-all">{{ json_encode($event->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
                @endif
            </div>
        @empty
            <div class="p-6 text-sm text-zinc-500 dark:text-zinc-400">
                No events yet. If Duplicati should be reporting but nothing appears here, its reports are not reaching
                Duplicati Central: check the URL in the job's <code>send-http-url</code> option and Duplicati's own log.
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $events->links() }}
    </div>
</div>
