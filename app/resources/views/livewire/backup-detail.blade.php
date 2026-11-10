@php
    $num = fn ($v) => $v === null ? '—' : number_format((float) $v);
    $isSizeKey = fn ($key) => str_contains((string) $key, 'Size') || str_contains((string) $key, 'Quota') || str_contains((string) $key, 'Space');
@endphp

<div class="py-2">
    <div class="overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-zinc-900 dark:text-zinc-100">
            <div class="mb-4 flex flex-wrap gap-2">
                <flux:button size="sm" icon="arrow-left" :href="route('backup-results')" wire:navigate>All results</flux:button>
                <flux:button size="sm" variant="ghost" icon="clock"
                             :href="route('backup-results', ['server' => $backupResult->backup_server_id, 'backup' => $backupResult->backup_id])"
                             wire:navigate>
                    History of this backup
                </flux:button>
            </div>

            <div class="flex items-center gap-3 mb-4">
                <flux:heading size="xl">{{ $backupResult->backup_name }}</flux:heading>
                <x-backup-status-badge :status="$backupResult->status" />
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div class="space-y-1">
                    <p><strong>Server:</strong> {{ $backupResult->backupServer->name }}</p>
                    <p><strong>Machine:</strong> {{ data_get($backupResult->extra, 'machine-name', 'Unknown') }} <span class="text-xs text-zinc-500">({{ $backupResult->machine_id ?: 'unknown id' }})</span></p>
                    @if(config('remote_control.enabled'))
                        <p>
                            <strong>Claimed Instance:</strong>
                            @if($claimedRegistration)
                                <a class="text-indigo-600 dark:text-indigo-400 hover:underline" href="{{ route('remote-control.show', $claimedRegistration->id) }}" wire:navigate>
                                    {{ $claimedRegistration->machine_name ?: $claimedRegistration->machine_id }}
                                </a>
                            @else
                                Not matched to a claimed server
                            @endif
                        </p>
                    @endif
                    <p><strong>Operation:</strong> {{ $backupResult->MainOperation }}</p>
                    <p><strong>Start Time:</strong> {{ $backupResult->BeginTime?->format('Y-m-d H:i:s') }}</p>
                    <p><strong>End Time:</strong> {{ $backupResult->EndTime?->format('Y-m-d H:i:s') }} <span class="text-xs text-zinc-500">({{ $backupResult->EndTime?->diffForHumans() }})</span></p>
                    <p><strong>Duration:</strong> {{ $this->formatDuration($backupResult->Duration) }}</p>
                    <p><strong>Version:</strong> {{ $backupResult->Version }}</p>
                    <p><strong>Warnings / Errors:</strong> {{ $backupResult->WarningsActualLength }} / {{ $backupResult->ErrorsActualLength }}</p>
                </div>
                <div class="space-y-1">
                    @if($backupResult->MainOperation === 'Restore')
                        <p><strong>Restored Files:</strong> {{ $num($backupResult->RestoredFiles) }}</p>
                        <p><strong>Size of Restored Files:</strong> {{ $this->formatSize($backupResult->SizeOfRestoredFiles) }}</p>
                        <p><strong>Restored Folders:</strong> {{ $num($backupResult->RestoredFolders) }}</p>
                        <p><strong>Patched Files:</strong> {{ $num($backupResult->PatchedFiles) }}</p>
                    @else
                        <p><strong>Examined Files:</strong> {{ $num($backupResult->ExaminedFiles) }}</p>
                        <p><strong>Size of Examined Files:</strong> {{ $this->formatSize($backupResult->SizeOfExaminedFiles) }}</p>
                        <p><strong>Added Files:</strong> {{ $num($backupResult->AddedFiles) }}</p>
                        <p><strong>Size of Added Files:</strong> {{ $this->formatSize($backupResult->SizeOfAddedFiles) }}</p>
                        <p><strong>Modified Files:</strong> {{ $num($backupResult->ModifiedFiles) }}</p>
                        <p><strong>Size of Modified Files:</strong> {{ $this->formatSize($backupResult->SizeOfModifiedFiles) }}</p>
                        <p><strong>Deleted Files:</strong> {{ $num($backupResult->DeletedFiles) }}</p>
                        <p><strong>Files With Error:</strong> {{ $num($backupResult->FilesWithError) }}</p>
                    @endif
                </div>
            </div>

            @if(!empty($backupResult->BackendStatistics))
                <flux:heading size="lg" class="mb-2">Backend Statistics</flux:heading>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-x-4 gap-y-1 mb-6">
                    @foreach($backupResult->BackendStatistics as $key => $value)
                        @continue(is_array($value))
                        <p>
                            <strong>{{ $key }}:</strong>
                            @if(is_numeric($value) && $isSizeKey($key))
                                {{ $this->formatSize($value) }}
                            @elseif(is_bool($value))
                                {{ $value ? 'Yes' : 'No' }}
                            @else
                                {{ $value }}
                            @endif
                        </p>
                    @endforeach
                </div>
            @endif

            @if($backupResult->log_lines)
                <flux:heading size="lg" class="mb-2">Log Lines</flux:heading>
                <div class="bg-zinc-100 dark:bg-zinc-700 p-4 rounded-lg overflow-auto max-h-96 font-mono text-xs">
                    @foreach($backupResult->log_lines as $line)
                        {{-- One line: whitespace-pre-wrap would also keep the template's indentation. --}}
                        <p class="mb-1 whitespace-pre-wrap">@if(is_array($line))@if(isset($line['Timestamp']))<span class="text-zinc-500">{{ $line['Timestamp'] }}</span> @endif{{ $line['Message'] ?? json_encode($line) }}@else{{ $line }}@endif</p>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
