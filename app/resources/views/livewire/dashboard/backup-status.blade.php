<div class="mt-8">
    <h2 class="text-2xl font-semibold mb-4">Backup Status</h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-2 xl:grid-cols-3 gap-6 mb-8">
        @foreach ($backupServers as $server)
            <div class="bg-white dark:bg-zinc-900 overflow-hidden shadow-sm rounded-lg p-6">
                <h3 class="text-xl font-semibold mb-4">{{ $server->name }}</h3>

                @if(isset($backupStatusData[$server->id]))
                    <p class="text-sm text-gray-600 dark:text-gray-400 mb-4">Version: {{ $backupStatusData[$server->id]['version'] }}</p>
                @endif

                <div class="mb-4">
                    <h4 class="text-lg font-semibold mb-2">Last 30 Days Overview</h4>
                    <div class="flex gap-0.5">
                        @foreach ($statusData[$server->id] as $day)
                            <div
                                class="flex-grow h-7 m-0.5 my-2 rounded-sm transition-all duration-200 ease-in-out hover:h-11 hover:p-0.5 hover:m-0 hover:rounded-xl"
                                style="background-color: {{ $day['status'] }};"
                                title="{{ $day['date'] }}"
                            ></div>
                        @endforeach
                    </div>
                </div>

                @if(isset($backupStatusData[$server->id]['backups']) && count($backupStatusData[$server->id]['backups']) > 0)
                    <div>
                        <h4 class="text-lg font-semibold mb-2">Individual Backups (last 30 backups)</h4>
                        @foreach ($backupStatusData[$server->id]['backups'] as $backupId => $backupInfo)
                            <div class="mb-4">
                                <h5 class="text-sm font-semibold mb-1">{{ $backupInfo['name'] }}</h5>
                                <div class="flex gap-0.5">
                                    @php
                                        $statuses = array_reverse($backupInfo['status']);
                                        $count = count($statuses);
                                        $missingCount = max(0, 30 - $count);
                                    @endphp
                                    @for ($i = 0; $i < $missingCount; $i++)
                                        <div
                                            class="flex-grow h-7 m-0.5 my-2 rounded-sm transition-all duration-200 ease-in-out hover:h-11 hover:p-0.5 hover:m-0 hover:rounded-xl"
                                            style="background-color: #808080;"
                                            title="No data"
                                        ></div>
                                    @endfor
                                    @foreach ($statuses as $status)
                                        <div
                                            class="flex-grow h-7 m-0.5 my-2 rounded-sm transition-all duration-200 ease-in-out hover:h-11 hover:p-0.5 hover:m-0 hover:rounded-xl"
                                            style="background-color: {{ $status['status'] }};"
                                            title="{{ $status['date'] }}"
                                        ></div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @else
                    <div class="text-gray-600 dark:text-gray-400 mt-4">
                        <p>No backups found for this server.</p>
                    </div>
                @endif
            </div>
        @endforeach
    </div>

    <div class="bg-white dark:bg-zinc-900 overflow-hidden shadow-sm rounded-lg p-6">
        <h3 class="text-xl font-semibold mb-4">Latest Backup Results</h3>

        <div class="hidden lg:block"> <!-- Table view for large screens -->
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-zinc-800">
                <tr>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Server - Backup</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">End Time</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Duration</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Source Size</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Destination Size</th>
                    <th scope="col" class="px-6 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Status</th>
                </tr>
                </thead>
                <tbody class="bg-white dark:bg-zinc-900 divide-y divide-gray-200 dark:divide-gray-700">
                @forelse ($latestBackupResults as $result)
                    <tr class="cursor-pointer hover:bg-gray-50 dark:hover:bg-zinc-800" onclick="window.location='{{ route('backup-detail', $result->id) }}'">
                        <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-gray-900 dark:text-white">
                            {{ $result->backupServer->name }} - {{ $result->backup_name }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                            {{ $result->EndTime->format('Y-m-d H:i:s') }}
                            <br>
                            <span class="text-xs">({{ $result->EndTime->diffForHumans() }})</span>
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                            {{ $this->formatDuration($result->Duration) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                            {{ $this->formatSize($result->SizeOfExaminedFiles) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                            {{ $this->formatSize($result->BackendStatistics['KnownFileSize'] ?? 0) }}
                        </td>
                        <td class="px-6 py-4 whitespace-nowrap">
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $result->ParsedResult == 'Fatal' ? 'bg-red-100 text-red-800' : ($result->ParsedResult == 'Warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800') }}">
                                {{ $result->ParsedResult }}
                            </span>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="px-6 py-4 whitespace-nowrap text-sm text-gray-500 dark:text-gray-400">
                            No backup results found.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>

        <div class="lg:hidden space-y-4"> <!-- Div layout for small screens -->
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
                            <span class="px-2 inline-flex text-xs leading-5 font-semibold rounded-full {{ $result->ParsedResult == 'Fatal' ? 'bg-red-100 text-red-800' : ($result->ParsedResult == 'Warning' ? 'bg-yellow-100 text-yellow-800' : 'bg-green-100 text-green-800') }}">
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
                <p class="text-gray-600 dark:text-gray-400">No backup results found.</p>
            @endforelse
        </div>

        <div class="mt-4 text-center">
            <a href="{{ route('backup-results') }}" class="inline-flex items-center px-4 py-2 border border-transparent text-sm font-medium rounded-md text-white bg-indigo-600 hover:bg-indigo-700 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500">
                View All Backup Results
            </a>
        </div>
    </div>
</div>
