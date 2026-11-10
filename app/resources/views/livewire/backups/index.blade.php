@php
    $localTime = fn ($utc) => $utc ? \Illuminate\Support\Carbon::parse($utc, 'UTC')->setTimezone(config('app.timezone')) : null;
@endphp

<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md">
    <flux:heading size="xl" class="mb-4">Backup Management</flux:heading>

    @if (session()->has('message'))
        <flux:callout icon="check-circle" variant="success" class="mb-4">
            <flux:callout.text>{{ session('message') }}</flux:callout.text>
        </flux:callout>
    @endif

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-6">
        <flux:input wire:model.live.debounce.300ms="searchTerm" icon="magnifying-glass" placeholder="Search backups..." aria-label="Search backups" />

        <flux:select wire:model.live="selectedServer" aria-label="Server">
            <flux:select.option value="">All servers</flux:select.option>
            @foreach($servers as $server)
                <flux:select.option value="{{ $server->id }}">{{ $server->name }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="overflow-x-auto bg-white dark:bg-zinc-900 rounded-lg">
        <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
            <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Server</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Backup Name</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Last Run</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Status</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Total Runs</th>
                    <th scope="col" class="px-6 py-3 text-right text-xs font-medium text-zinc-500 dark:text-zinc-400 uppercase tracking-wider">Actions</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse($backups as $backup)
                    @php($last = $localTime($backup->last_backup_time))
                    <tr wire:key="backup-{{ $backup->backup_server_id }}-{{ $backup->backup_id }}">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-zinc-900 dark:text-white">{{ $backup->name }}</td>
                        @php($setting = $jobSettings[$backup->backup_server_id . '|' . $backup->backup_id] ?? null)
                        @php($snoozed = \App\Support\Snooze::label($setting?->isMuted() ? $setting->muted_until : $mutedServers->get($backup->backup_server_id)?->muted_until))
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-600 dark:text-zinc-300">
                            {{ $backup->backup_name }}
                            @if($snoozed || ($setting && ! $setting->monitored) || $setting?->expected_interval_hours)
                                <div class="mt-1 flex flex-wrap gap-1">
                                    @if($snoozed)
                                        <flux:badge size="sm" color="zinc" icon="bell-slash">Snoozed {{ $snoozed }}</flux:badge>
                                    @endif
                                    @if($setting && ! $setting->monitored)
                                        <flux:badge size="sm" color="zinc" icon="eye-slash">Not monitored</flux:badge>
                                    @elseif($setting?->expected_interval_hours)
                                        <flux:badge size="sm" color="sky" icon="clock">Every {{ $setting->expected_interval_hours }} h</flux:badge>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-400">
                            {{ $last?->diffForHumans() }}
                            <br>
                            <span class="text-xs">{{ $last?->format('Y-m-d H:i:s') }}</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <div class="flex items-center gap-1">
                                @if($backup->successful_backups > 0)
                                    <flux:badge size="sm" color="green">{{ $backup->successful_backups }} Success</flux:badge>
                                @endif
                                @if($backup->warning_backups > 0)
                                    <flux:badge size="sm" color="amber">{{ $backup->warning_backups }} Warning</flux:badge>
                                @endif
                                @if($backup->failed_backups > 0)
                                    <flux:badge size="sm" color="red">{{ $backup->failed_backups }} Failed</flux:badge>
                                @endif
                            </div>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-400">{{ $backup->total_backups }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                            <div class="flex justify-end gap-2">
                                <flux:button size="sm" variant="ghost" icon="clock"
                                             :href="route('backup-results', ['server' => $backup->backup_server_id, 'backup' => $backup->backup_id])"
                                             wire:navigate>History</flux:button>
                                <flux:button size="sm" variant="ghost" icon="cog-6-tooth"
                                             wire:click="openSettings({{ \Illuminate\Support\Js::from($backup->backup_server_id) }}, {{ \Illuminate\Support\Js::from($backup->backup_id) }})">Settings</flux:button>
                                <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600 dark:text-red-400"
                                             wire:click="confirmBackupDeletion({{ \Illuminate\Support\Js::from($backup->backup_server_id) }}, {{ \Illuminate\Support\Js::from($backup->backup_id) }})">Delete history</flux:button>
                            </div>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 text-sm text-zinc-500 dark:text-zinc-400 text-center">No backups found.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="mt-4">
        {{ $backups->links() }}
    </div>

    <flux:modal name="job-settings" class="w-full max-w-lg">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Job settings</flux:heading>
                <flux:subheading>{{ $settingsBackupName }}</flux:subheading>
            </div>

            <form wire:submit="saveSettings" class="space-y-5">
                <flux:switch wire:model="monitored" label="Warn when backups are missed"
                             description="Turn off for jobs you run by hand. They are never marked overdue." />

                <flux:input wire:model="expectedIntervalHours" type="number" min="1" max="8760" label="Expected every (hours)"
                            :placeholder="$learnedInterval ? 'Learned: ' . $learnedInterval : 'Learn automatically'"
                            description="Leave empty to learn the schedule from past runs. When set, the job is overdue one missed run (plus the grace period) after its last backup." />

                <flux:separator variant="subtle" />

                <div class="space-y-4">
                    <div>
                        <flux:heading size="sm">Alert rules</flux:heading>
                        <flux:text size="sm">Sent to channels that receive warnings. Leave a field empty to turn that rule off.</flux:text>
                    </div>
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <flux:input wire:model="alertSizeChangePercent" type="number" min="1" label="Source size changes by (%)" placeholder="e.g. 50" />
                        <flux:input wire:model="alertDurationFactor" type="number" min="1.1" step="0.1" label="Takes longer than (× usual)" placeholder="e.g. 3" />
                        <flux:input wire:model="alertUnchangedRuns" type="number" min="1" label="No files changed for (runs)" placeholder="e.g. 7" />
                        <flux:input wire:model="alertQuotaFreePercent" type="number" min="1" max="99" label="Destination free below (%)" placeholder="e.g. 10" />
                    </div>
                </div>

                <flux:separator variant="subtle" />

                <flux:input wire:model="heartbeatUrl" type="url" label="Heartbeat URL"
                            placeholder="https://status.example.com/api/push/…"
                            description="Called (GET) after every successful backup, e.g. an Uptime Kuma push monitor or a Healthchecks.io check. Failed backups send nothing, so that service raises the alarm." />

                <flux:separator variant="subtle" />

                <div class="space-y-3">
                    <flux:switch wire:model.live="onStatusPage" label="Show on the public status page"
                                 description="Only the status and the time of the last backup are shown, never the server, sizes or logs." />
                    @if($onStatusPage)
                        <flux:input wire:model="statusPageName" label="Public name" :placeholder="$settingsBackupName" description="Leave empty to use the job name." />
                    @endif
                </div>

                <div class="flex justify-end">
                    <flux:button type="submit" variant="primary">Save</flux:button>
                </div>
            </form>

            <flux:separator />

            <div class="space-y-3">
                <flux:heading size="sm">Snooze alerts</flux:heading>
                @if($mutedLabel)
                    <flux:callout icon="bell-slash" color="zinc">
                        <flux:callout.text>Alerts for this job are snoozed {{ $mutedLabel }}.</flux:callout.text>
                    </flux:callout>
                    <flux:button wire:click="resumeJob" icon="bell">Resume alerts</flux:button>
                @else
                    <flux:text>No result or overdue alerts are sent while a job is snoozed, for example during maintenance.</flux:text>
                    <div class="flex gap-2">
                        <flux:select wire:model="snoozeFor" aria-label="Snooze for" class="max-w-48">
                            @foreach(\App\Support\Snooze::OPTIONS as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:button wire:click="snoozeJob" icon="bell-slash">Snooze</flux:button>
                    </div>
                @endif
            </div>
        </div>
    </flux:modal>

    <flux:modal name="delete-backup-history" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete backup history?</flux:heading>
                <flux:subheading>All stored results for this backup job will be removed. This cannot be undone. New results will still be accepted.</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:button variant="ghost" wire:click="cancelBackupDeletion">Cancel</flux:button>
                <flux:button variant="danger" wire:click="deleteBackupHistory">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
