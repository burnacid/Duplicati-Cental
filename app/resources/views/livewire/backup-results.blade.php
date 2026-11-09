<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md">
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="xl">Backup Results</flux:heading>
        @if($hasFilters)
            <flux:button size="sm" variant="ghost" icon="x-mark" wire:click="clearFilters">Clear filters</flux:button>
        @endif
    </div>

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5 mb-6">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search backup name..." aria-label="Search" />

        <flux:select wire:model.live="selectedServerId" aria-label="Server">
            <flux:select.option value="">All servers</flux:select.option>
            @foreach($backupServers as $server)
                <flux:select.option value="{{ $server->id }}">{{ $server->name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="selectedBackupId" aria-label="Backup job">
            <flux:select.option value="">All backup jobs</flux:select.option>
            @foreach($backupJobs as $job)
                <flux:select.option value="{{ $job->backup_id }}">{{ $job->backup_name }}</flux:select.option>
            @endforeach
        </flux:select>

        <flux:select wire:model.live="status" aria-label="Status">
            <flux:select.option value="">All statuses</flux:select.option>
            <flux:select.option value="Success">Success</flux:select.option>
            <flux:select.option value="Warning">Warning</flux:select.option>
            <flux:select.option value="Failed">Error / Fatal</flux:select.option>
        </flux:select>

        <flux:select wire:model.live="operation" aria-label="Operation">
            <flux:select.option value="">All operations</flux:select.option>
            @foreach($operations as $op)
                <flux:select.option value="{{ $op }}">{{ $op }}</flux:select.option>
            @endforeach
        </flux:select>
    </div>

    <div class="bg-white dark:bg-zinc-900 overflow-hidden shadow-sm rounded-lg p-6">
        <div class="hidden md:block overflow-x-auto">
            <table class="min-w-full divide-y divide-zinc-200 dark:divide-zinc-700">
                <thead class="bg-zinc-50 dark:bg-zinc-800">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">Server - Backup</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">Operation</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">End Time</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">Duration</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">Source Size</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">Destination Size</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-zinc-500 dark:text-zinc-300 uppercase tracking-wider">Status</th>
                </tr>
                </thead>
                <tbody class="divide-y divide-zinc-200 dark:divide-zinc-700">
                @forelse ($latestBackupResults as $result)
                    @php($claimedRegistration = $claimedRegistrationsByMachineId->get($result->machine_id))
                    <tr class="hover:bg-zinc-50 dark:hover:bg-zinc-800" wire:key="result-{{ $result->id }}">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-zinc-900 dark:text-white">
                            <a href="{{ route('backup-detail', $result->id) }}" wire:navigate class="hover:underline focus:underline">
                                {{ $result->backupServer->name }} - {{ $result->backup_name }}
                            </a>
                            @if($claimedRegistration)
                                <div class="mt-1 text-xs text-indigo-600 dark:text-indigo-400">
                                    Claimed instance: {{ $claimedRegistration->machine_name ?: $claimedRegistration->machine_id }}
                                </div>
                            @endif
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-300">{{ $result->MainOperation }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-300">
                            {{ $result->EndTime?->format('Y-m-d H:i:s') }}
                            <br>
                            <span class="text-xs">({{ $result->EndTime?->diffForHumans() }})</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-300">{{ $this->formatDuration($result->Duration) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-300">{{ $this->formatSize($result->SizeOfExaminedFiles) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-300">{{ $this->formatSize($result->BackendStatistics['KnownFileSize'] ?? 0) }}</td>
                        <td class="px-6 py-4 whitespace-nowrap"><x-backup-status-badge :status="$result->status" /></td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-6 py-4 whitespace-nowrap text-sm text-zinc-500 dark:text-zinc-300">
                            No backup results found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <!-- Card view for mobile screens -->
        <div class="md:hidden space-y-4">
            @forelse ($latestBackupResults as $result)
                @php($claimedRegistration = $claimedRegistrationsByMachineId->get($result->machine_id))
                <a href="{{ route('backup-detail', $result->id) }}" wire:navigate class="block" wire:key="result-m-{{ $result->id }}">
                    <div class="border-b border-zinc-200 dark:border-zinc-700 pb-4 hover:bg-zinc-50 dark:hover:bg-zinc-800">
                        <div class="flex justify-between items-start">
                            <div>
                                <h4 class="text-lg font-semibold">{{ $result->backupServer->name }} - {{ $result->backup_name }}</h4>
                                @if($claimedRegistration)
                                    <p class="text-xs text-indigo-600 dark:text-indigo-400">
                                        Claimed instance: {{ $claimedRegistration->machine_name ?: $claimedRegistration->machine_id }}
                                    </p>
                                @endif
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    {{ $result->MainOperation }} &middot; {{ $result->EndTime?->format('Y-m-d H:i:s') }}
                                    ({{ $result->EndTime?->diffForHumans() }})
                                </p>
                                <p class="text-sm text-zinc-600 dark:text-zinc-400">
                                    Duration: {{ $this->formatDuration($result->Duration) }}
                                </p>
                            </div>
                            <div class="text-right">
                                <x-backup-status-badge :status="$result->status" />
                            </div>
                        </div>
                        <div class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                            <p>Source Size: {{ $this->formatSize($result->SizeOfExaminedFiles) }}</p>
                            <p>Destination Size: {{ $this->formatSize($result->BackendStatistics['KnownFileSize'] ?? 0) }}</p>
                        </div>
                    </div>
                </a>
            @empty
                <div class="text-sm text-zinc-500 dark:text-zinc-300">
                    No backup results found.
                </div>
            @endforelse
        </div>

        <div class="mt-4">
            {{ $latestBackupResults->links() }}
        </div>
    </div>
</div>
