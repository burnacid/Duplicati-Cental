<div class="mt-8">
    <h2 class="text-2xl font-semibold mb-4">Backup Status</h2>

    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
        @foreach ($backupServers as $server)
            <div class="bg-white dark:bg-zinc-900 overflow-hidden shadow-sm rounded-lg p-6">
                <h3 class="text-xl font-semibold mb-4">{{ $server->name }}</h3>

                <div class="mb-4">
                    <h4 class="text-lg font-semibold mb-2">Last 30 Days Overview</h4>
                    <div class="flex gap-0.5">
                        @foreach ($statusData[$server->id] as $day)
                            <div
                                class="flex-grow h-8 rounded-sm"
                                style="background-color: {{ $day['status'] }};"
                                title="{{ $day['date'] }}"
                            ></div>
                        @endforeach
                    </div>
                </div>

                @if(isset($backupStatusData[$server->id]))
                    <div>
                        <h4 class="text-lg font-semibold mb-2">Individual Backups</h4>
                        @foreach ($backupStatusData[$server->id] as $backupId => $backupInfo)
                            <div class="mb-4">
                                <h5 class="text-sm font-semibold mb-1">{{ $backupInfo['name'] }}</h5>
                                <div class="flex gap-0.5">
                                    @foreach ($backupInfo['status'] as $status)
                                        <div
                                            class="flex-grow h-6 rounded-sm"
                                            style="background-color: {{ $status['status'] }};"
                                            title="{{ $status['date'] }}"
                                        ></div>
                                    @endforeach
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endforeach
    </div>
</div>
