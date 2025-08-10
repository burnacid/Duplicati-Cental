<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md">
    <h2 class="text-2xl font-bold mb-4 text-gray-800 dark:text-white">Backup Results</h2>

    <div class="mb-4">
        <label for="server-select" class="block text-sm font-medium text-gray-700 dark:text-gray-300">Select Server</label>
        <select id="server-select" wire:model.live="selectedServerId" class="mt-1 block w-full pl-3 pr-10 py-2 text-base border-gray-300 dark:border-gray-600 bg-white dark:bg-zinc-700 text-gray-700 dark:text-gray-300 focus:outline-none focus:ring-indigo-500 dark:focus:ring-indigo-400 focus:border-indigo-500 dark:focus:border-indigo-400 sm:text-sm rounded-md">
            <option value="">All Servers</option>
            @foreach($backupServers as $server)
                <option value="{{ $server->id }}">{{ $server->name }}</option>
            @endforeach
        </select>
    </div>

    <div class="bg-white dark:bg-zinc-900 overflow-hidden shadow-sm rounded-lg p-6">
    <div class="hidden md:block overflow-x-auto">
        <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
            <thead class="bg-gray-50 dark:bg-zinc-700">
            <tr>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Server - Backup</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">End Time</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Duration</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Source Size</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Destination Size</th>
                <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-300 uppercase tracking-wider">Status</th>
            </tr>
            </thead>
            <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
            @forelse ($latestBackupResults as $result)
                <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-zinc-800" onclick="window.location='{{ route('backup-detail', $result->id) }}'">
                    <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                        {{ $result->backupServer->name }} - {{ $result->backup_name }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                        {{ $result->EndTime->format('Y-m-d H:i:s') }}
                        <br>
                        <span class="text-xs">({{ $result->EndTime->diffForHumans() }})</span>
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                        {{ $this->formatDuration($result->Duration) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                        {{ $this->formatSize($result->SizeOfExaminedFiles) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
                        {{ $this->formatSize($result->BackendStatistics['KnownFileSize'] ?? 0) }}
                    </td>
                    <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $result->ParsedResult == 'Fatal' ? 'bg-red-100 text-red-800' : ( $result->ParsedResult == 'Warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800') }}">
                                {{ $result->ParsedResult }}
                            </span>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-300">
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
            <a href="{{ route('backup-detail', $result->id) }}" class="block">
                <div class="border-b border-gray-200 dark:border-gray-700 pb-4 hover:bg-gray-50 dark:hover:bg-zinc-800">
                    <div class="flex justify-between items-start">
                        <div>
                            <h4 class="text-lg font-semibold">{{ $result->backupServer->name }} - {{ $result->backup_name }}</h4>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                End Time: {{ $result->EndTime->format('Y-m-d H:i:s') }}
                                ({{ $result->EndTime->diffForHumans() }})
                            </p>
                            <p class="text-sm text-gray-600 dark:text-gray-400">
                                Duration: {{ $this->formatDuration($result->Duration) }}
                            </p>
                        </div>
                        <div class="text-right">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $result->ParsedResult == 'Fatal'? 'bg-red-100 text-red-800' : ( $result->ParsedResult ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800') }}">
                                {{ $result->ParsedResult }}
                            </span>
                        </div>
                    </div>
                    <div class="mt-2 text-sm text-gray-600 dark:text-gray-400">
                        <p>Source Size: {{ $this->formatSize($result->SizeOfExaminedFiles) }}</p>
                        <p>Destination Size: {{ $this->formatSize($result->BackendStatistics['KnownFileSize'] ?? 0) }}</p>
                    </div>
                </div>
            </a>
        @empty
            <div class="text-sm text-gray-500 dark:text-gray-300">
                No backup results found.
            </div>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $latestBackupResults->links() }}
    </div>
</div>
</div>

