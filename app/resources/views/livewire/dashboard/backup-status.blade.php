<div class="mt-8 space-y-8" wire:poll.60s>
    @if($flash)
        <flux:callout icon="information-circle" variant="secondary">
            <flux:callout.text>{{ $flash }}</flux:callout.text>
        </flux:callout>
    @endif

    @if($backupServers->isEmpty())
        {{-- Onboarding --}}
        <div class="bg-white dark:bg-zinc-900 shadow-sm rounded-lg p-8 text-center">
            <flux:heading size="lg">Welcome to Duplicati Central</flux:heading>
            <flux:subheading class="mt-2 max-w-xl mx-auto">
                Add a backup server, then paste its URL into Duplicati
                (Settings &rarr; Default options &rarr; <code>send-http-url</code>, or per backup job).
                Results show up here after the next run.
            </flux:subheading>
            <div class="mt-6 flex justify-center gap-2">
                <flux:button variant="primary" icon="plus" :href="route('backup-servers.create')" wire:navigate>Add a backup server</flux:button>
                @if(config('remote_control.enabled'))
                    <flux:button icon="command-line" :href="route('remote-control.index')" wire:navigate>Remote control</flux:button>
                @endif
            </div>
        </div>
    @else
        @if($attention->isNotEmpty())
            <div id="needs-attention" class="bg-white dark:bg-zinc-900 shadow-sm rounded-lg p-6">
                <flux:heading size="lg" class="mb-3">Needs attention</flux:heading>
                <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                    @foreach($attention as $job)
                        <li class="py-2 flex flex-wrap items-center justify-between gap-2">
                            <a href="{{ route('backup-results', ['server' => $job['server_id'], 'backup' => $job['backup_id']]) }}" wire:navigate class="font-medium hover:underline">
                                {{ $job['server_name'] }} - {{ $job['backup_name'] }}
                            </a>
                            <span class="flex items-center gap-2 text-sm text-zinc-500">
                                <x-job-flags :job="$job" />
                                <x-backup-status-badge :status="$job['last_status']" />
                                last backup {{ $job['last_backup_at']?->diffForHumans() ?? 'never' }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div>
            <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
                <flux:heading size="xl">Backup Status</flux:heading>
                <div class="flex flex-wrap items-center gap-3 text-xs text-zinc-500">
                    @foreach([\App\Enums\BackupStatus::Success, \App\Enums\BackupStatus::Warning, \App\Enums\BackupStatus::Error, \App\Enums\BackupStatus::None] as $legend)
                        <span class="inline-flex items-center gap-1">
                            <span class="inline-block w-3 h-3 rounded-sm" style="background-color: {{ $legend->color() }}"></span>
                            {{ $legend === \App\Enums\BackupStatus::Error ? 'Error / Fatal' : $legend->label() }}
                        </span>
                    @endforeach
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-6">
                @foreach ($backupServers as $server)
                    @php
                        $jobs = $jobsByServer->get($server->id, collect());
                        $registration = $registrationsByServer->get($server->id);
                        $connected = $registration?->agentState?->is_connected ?? false;
                        $version = $jobs->map(fn ($j) => $j['last_result'])->sortByDesc('EndTime')->first()?->Version;
                    @endphp
                    <div class="bg-white dark:bg-zinc-900 shadow-sm rounded-lg p-6" wire:key="server-{{ $server->id }}">
                        <div class="flex items-start justify-between gap-2">
                            <flux:heading size="lg">{{ $server->name }}</flux:heading>
                            @if($registration)
                                <flux:badge size="sm" :color="$connected ? 'green' : 'zinc'">{{ $connected ? 'Online' : 'Offline' }}</flux:badge>
                            @endif
                        </div>
                        <p class="text-sm text-zinc-500 mb-4">Version: {{ $version ?? 'Unknown' }}</p>

                        <flux:subheading>Last 30 days</flux:subheading>
                        <x-status-bar :items="collect($statusData[$server->id] ?? [])->map(fn ($day) => [
                            'status' => $day['status'],
                            'title' => $day['date'] . ' — ' . $day['status']->label(),
                        ])" />

                        @if($jobs->isNotEmpty())
                            <flux:subheading class="mt-4">Backup jobs (last 30 runs)</flux:subheading>
                            @foreach ($jobs as $job)
                                @php
                                    $runs = $job['recent'];
                                    $items = array_merge(
                                        array_fill(0, max(0, 30 - count($runs)), ['status' => \App\Enums\BackupStatus::None, 'title' => 'No data']),
                                        array_map(fn ($r) => ['status' => $r['status'], 'title' => $r['title'], 'href' => $r['url']], $runs),
                                    );
                                @endphp
                                <div class="mt-3" wire:key="job-{{ $server->id }}-{{ $job['backup_id'] }}">
                                    <div class="flex items-center justify-between gap-2">
                                        <a href="{{ route('backup-results', ['server' => $server->id, 'backup' => $job['backup_id']]) }}" wire:navigate class="text-sm font-semibold hover:underline truncate">
                                            {{ $job['backup_name'] }}
                                        </a>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <x-job-flags :job="$job" />
                                            @if($connected)
                                                <flux:button size="xs" icon="play" wire:click="runNow({{ \Illuminate\Support\Js::from($server->id) }}, {{ \Illuminate\Support\Js::from($job['backup_id']) }})" wire:confirm="Start {{ $job['backup_name'] }} now?">Run</flux:button>
                                            @endif
                                        </div>
                                    </div>
                                    <p class="text-xs text-zinc-500">Last backup {{ $job['last_backup_at']?->diffForHumans() ?? 'unknown' }}</p>
                                    <x-status-bar :items="$items" />
                                </div>
                            @endforeach
                        @else
                            <p class="text-sm text-zinc-500 mt-4">No results yet. Paste this server's URL into Duplicati to start reporting.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>

        <div class="bg-white dark:bg-zinc-900 shadow-sm rounded-lg p-6">
            <div class="flex items-center justify-between mb-4">
                <flux:heading size="lg">Latest Backup Results</flux:heading>
                <flux:link :href="route('backup-results')" wire:navigate>View all</flux:link>
            </div>

            <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($latestBackupResults as $result)
                    <li wire:key="latest-{{ $result->id }}">
                        <a href="{{ route('backup-detail', $result->id) }}" wire:navigate class="flex flex-wrap items-center justify-between gap-2 py-3 hover:bg-zinc-50 dark:hover:bg-zinc-800 px-2 rounded">
                            <div>
                                <p class="font-medium">{{ $result->backupServer->name }} - {{ $result->backup_name }}</p>
                                <p class="text-sm text-zinc-500">
                                    {{ $result->MainOperation }} &middot; {{ $result->EndTime?->format('Y-m-d H:i') }} ({{ $result->EndTime?->diffForHumans() }})
                                    &middot; {{ $this->formatDuration($result->Duration) }}
                                    &middot; {{ $this->formatSize($result->BackendStatistics['KnownFileSize'] ?? 0) }}
                                </p>
                            </div>
                            <x-backup-status-badge :status="$result->status" />
                        </a>
                    </li>
                @empty
                    <li class="py-3 text-sm text-zinc-500">No backup results found.</li>
                @endforelse
            </ul>
        </div>
    @endif
</div>
