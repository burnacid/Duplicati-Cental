@php
    $phaseLabels = [
        'sending' => ['Sending to instance…', 'zinc'],
        'waiting' => ['Queued', 'zinc'],
        'running' => ['Running', 'blue'],
        'completed' => ['Completed', 'green'],
        'failed' => ['Failed', 'red'],
        'error' => ['Could not start', 'red'],
    ];
    $backupNames = collect($backups ?? [])->pluck('name', 'id');
    $fmtBytes = function ($bytes) {
        $bytes = (float) $bytes; $units = ['B', 'KB', 'MB', 'GB', 'TB']; $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) { $bytes /= 1024; $i++; }
        return round($bytes, 1) . ' ' . $units[$i];
    };
@endphp

<div class="py-6" wire:poll.2s="poll">
    <div class="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8 space-y-6">
        <div>
            <flux:button size="sm" icon="arrow-left" :href="route('remote-control.index')" wire:navigate>Remote control</flux:button>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900 px-4 py-4">
            <div class="flex flex-wrap items-center gap-3">
                <flux:heading size="xl">{{ $registration->display_name }}</flux:heading>
                @if ($registration->agentState?->is_connected)
                    <flux:badge size="sm" color="green">Online</flux:badge>
                    <span class="text-xs text-zinc-500">Seen {{ $registration->agentState->last_seen_at?->diffForHumans() }}</span>
                @else
                    <flux:badge size="sm" color="zinc">Offline</flux:badge>
                @endif
            </div>
            <p class="text-sm text-zinc-500">@if ($registration->machine_name && $registration->machine_name !== $registration->display_name){{ $registration->machine_name }} &middot; @endif{{ $registration->machine_id ?: 'no machine id' }} &middot; {{ $registration->version ?: 'n/a' }}</p>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800">
                <flux:heading size="lg">Live progress</flux:heading>
            </div>
            <div class="px-4 py-4">
                @if ($progressError)
                    <p class="text-sm text-red-600 dark:text-red-400">{{ $progressError }}</p>
                @elseif ($progress === null)
                    <p class="text-sm text-zinc-500">Idle &mdash; nothing is running.</p>
                @else
                    @php
                        $overall = isset($progress['OverallProgress']) ? max(0, min(1, (float) $progress['OverallProgress'])) : null;
                    @endphp
                    <div class="space-y-2">
                        <p class="text-sm">
                            <strong>{{ $backupNames[(string) ($progress['BackupID'] ?? '')] ?? ('Backup ' . ($progress['BackupID'] ?? '?')) }}</strong>
                            &middot; task {{ $progress['TaskID'] ?? '?' }}
                            &middot; {{ $progress['Phase'] ?? 'Working' }}
                        </p>
                        @if ($overall !== null)
                            <div class="h-2 w-full rounded bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuenow="{{ round($overall * 100) }}" aria-valuemin="0" aria-valuemax="100">
                                <div class="h-2 rounded bg-blue-500" style="width: {{ round($overall * 100) }}%"></div>
                            </div>
                        @endif
                        @if (isset($progress['TotalFileCount']))
                            <p class="text-xs text-zinc-500">
                                {{ number_format((float) ($progress['ProcessedFileCount'] ?? 0)) }} / {{ number_format((float) $progress['TotalFileCount']) }} files
                                @if (isset($progress['TotalFileSize']))
                                    &middot; {{ $fmtBytes($progress['ProcessedFileSize'] ?? 0) }} / {{ $fmtBytes($progress['TotalFileSize']) }}
                                @endif
                            </p>
                        @endif
                        @if (!empty($progress['CurrentFilename']))
                            <p class="text-xs text-zinc-500 truncate" title="{{ $progress['CurrentFilename'] }}">{{ $progress['CurrentFilename'] }}</p>
                        @endif
                        <details class="text-xs">
                            <summary class="cursor-pointer text-zinc-500">Raw progress data</summary>
                            <pre class="mt-2 overflow-x-auto rounded-lg bg-zinc-100 p-3 text-zinc-800 dark:bg-zinc-800 dark:text-zinc-200">{{ json_encode($progress, JSON_PRETTY_PRINT) }}</pre>
                        </details>
                    </div>
                @endif
            </div>
        </div>

        <div class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            @php
                $importBusy = in_array($importStatus['state'] ?? null, ['queued', 'running'], true);
            @endphp
            <div class="border-b border-zinc-200 px-4 py-3 dark:border-zinc-800 flex flex-wrap items-center justify-between gap-2">
                <flux:heading size="lg">Backup jobs</flux:heading>
                <button type="button"
                        wire:click="importHistory"
                        @disabled($importBusy || ! $registration->agentState?->is_connected)
                        title="Fetch results of the last 30 days from this instance's local logs. Results that are already stored are skipped."
                        class="inline-flex items-center rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                    Import history (30 days)
                </button>
            </div>

            @if ($importStatus)
                <div class="border-b border-zinc-200 px-4 py-2 text-sm dark:border-zinc-800 flex flex-wrap items-center gap-2">
                    @switch($importStatus['state'])
                        @case('queued')
                            <flux:badge size="sm" color="zinc">Import queued</flux:badge>
                            <span class="text-xs text-zinc-500">Waiting for the queue worker…</span>
                            @break
                        @case('running')
                            <flux:badge size="sm" color="blue">Importing</flux:badge>
                            <span class="text-xs text-zinc-500">{{ $importStatus['jobs'] ?? 0 }} job(s) done · {{ $importStatus['imported'] ?? 0 }} imported · {{ $importStatus['skipped'] ?? 0 }} already stored</span>
                            @break
                        @case('done')
                            <flux:badge size="sm" color="green">Import finished</flux:badge>
                            <span class="text-xs text-zinc-500">
                                {{ $importStatus['imported'] ?? 0 }} result(s) imported, {{ $importStatus['skipped'] ?? 0 }} already stored
                                ({{ \Illuminate\Support\Carbon::parse($importStatus['updated_at'])->diffForHumans() }})
                            </span>
                            @break
                        @default
                            <flux:badge size="sm" color="red">Import failed</flux:badge>
                            <span class="text-xs text-red-600 dark:text-red-400">{{ $importStatus['message'] ?? 'Unknown error' }}</span>
                    @endswitch
                    @foreach ($importStatus['failed_jobs'] ?? [] as $failure)
                        <span class="w-full text-xs text-red-600 dark:text-red-400">{{ $failure }}</span>
                    @endforeach
                </div>
            @endif

            @if ($backups === null)
                <p class="px-4 py-6 text-sm text-zinc-500">Loading backup jobs…</p>
            @elseif (empty($backups))
                <p class="px-4 py-6 text-sm text-zinc-500">No backup jobs configured on this instance.</p>
            @else
                <ul class="divide-y divide-zinc-100 dark:divide-zinc-800">
                    @foreach ($backups as $backup)
                        @php
                            $task = $tasks[$backup['id']] ?? null;
                            $busy = $task && ! $this->isTaskFinished($task);
                            $last = $lastResults->get($backup['id']);
                        @endphp
                        <li class="px-4 py-3" wire:key="rc-backup-{{ $backup['id'] }}">
                            <div class="flex flex-wrap items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="font-medium">{{ $backup['name'] }}</div>
                                    @if (!empty($backup['description']))
                                        <div class="text-xs text-zinc-500">{{ $backup['description'] }}</div>
                                    @endif
                                    <div class="mt-1 flex flex-wrap items-center gap-2 text-xs text-zinc-500">
                                        @if ($last)
                                            <span>Last result:</span>
                                            <a href="{{ route('backup-detail', $last->id) }}" wire:navigate class="inline-flex items-center gap-1 hover:underline">
                                                <x-backup-status-badge :status="$last->status" />
                                                {{ $last->MainOperation }} {{ $last->EndTime?->diffForHumans() }}
                                            </a>
                                        @else
                                            <span>No results received yet.</span>
                                        @endif
                                    </div>
                                </div>

                                <div class="flex shrink-0 items-center gap-2">
                                    <button type="button"
                                            wire:click="repairBackup(@js($backup['id']))"
                                            wire:confirm="Repair the local database of {{ $backup['name'] }}? Duplicati rebuilds missing information from the remote files."
                                            @disabled($busy)
                                            class="inline-flex items-center rounded-md border border-zinc-300 px-3 py-1.5 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 disabled:opacity-50 dark:border-zinc-700 dark:text-zinc-300 dark:hover:bg-zinc-800">
                                        Repair
                                    </button>
                                    <button type="button"
                                            wire:click="runBackup(@js($backup['id']))"
                                            @disabled($busy)
                                            class="inline-flex items-center rounded-md border border-indigo-200 px-3 py-1.5 text-xs font-medium text-indigo-700 transition hover:bg-indigo-50 disabled:opacity-50 dark:border-indigo-900/50 dark:text-indigo-300 dark:hover:bg-indigo-950/40">
                                        Run now
                                    </button>
                                </div>
                            </div>

                            @if ($task)
                                @php([$phaseText, $phaseColor] = $phaseLabels[$task['phase']] ?? [$task['phase'], 'zinc'])
                                <div class="mt-2 flex flex-wrap items-center gap-2 rounded-lg bg-zinc-50 px-3 py-2 text-sm dark:bg-zinc-800/60">
                                    <span class="font-medium">{{ $task['operation'] }}</span>
                                    <flux:badge size="sm" :color="$phaseColor">{{ $phaseText }}</flux:badge>
                                    @if ($task['task_id'])
                                        <span class="text-xs text-zinc-500">task {{ $task['task_id'] }}</span>
                                    @endif
                                    @if ($task['message'])
                                        <span class="text-xs {{ in_array($task['phase'], ['failed', 'error']) ? 'text-red-600 dark:text-red-400' : 'text-zinc-500' }}">{{ $task['message'] }}</span>
                                    @endif
                                    @if ($task['phase'] === 'failed' && $task['operation'] === 'Backup' && str_contains((string) $task['message'], 'repair'))
                                        <span class="text-xs text-zinc-500">&rarr; try <strong>Repair</strong>.</span>
                                    @endif
                                    @unless ($busy)
                                        <button type="button" wire:click="dismissTask(@js($backup['id']))" class="ml-auto text-xs text-zinc-500 hover:underline">Dismiss</button>
                                    @endunless
                                </div>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
</div>
