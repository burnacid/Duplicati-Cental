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
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-600 dark:text-zinc-300">{{ $backup->backup_name }}</td>
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
