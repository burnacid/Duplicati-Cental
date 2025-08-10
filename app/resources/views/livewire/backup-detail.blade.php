<div class="py-2">
    <div class="overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-gray-900 dark:text-gray-100">
            @php
                $previousUrl = url()->previous();
                $currentUrl = url()->current();
            @endphp

            @if($previousUrl !== $currentUrl)
                <div class="mb-4">
                    <a href="{{ $previousUrl }}" class="inline-flex items-center px-4 py-2 bg-gray-800 dark:bg-gray-200 border border-transparent rounded-md font-semibold text-xs text-white dark:text-gray-800 uppercase tracking-widest hover:bg-gray-700 dark:hover:bg-white focus:bg-gray-700 dark:focus:bg-white active:bg-gray-900 dark:active:bg-gray-300 focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-gray-800 transition ease-in-out duration-150">
                        <svg class="w-5 h-5 mr-2" fill="none" stroke="currentColor" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18"></path>
                        </svg>
                        Back
                    </a>
                </div>
            @endif

            <h2 class="text-2xl font-semibold mb-4">Backup Detail</h2>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                <div>
                    <p><strong>Server:</strong> {{ $backupResult->backupServer->name }}</p>
                    <p><strong>Backup Name:</strong> {{ $backupResult->extra['backup-name'] }}</p>
                    <p><strong>Operation:</strong> {{ $backupResult->MainOperation }}</p>
                    <p><strong>Result:</strong> {{ $backupResult->ParsedResult }}</p>
                    <p><strong>Start Time:</strong> {{ $backupResult->BeginTime }}</p>
                    <p><strong>End Time:</strong> {{ $backupResult->EndTime }}</p>
                    <p><strong>Duration:</strong> {{ $this->formatDuration($backupResult->Duration) }}</p>
                </div>
                <div>
                    <p><strong>Version:</strong> {{ $backupResult->Version }}</p>
                    <p><strong>Examined Files:</strong> {{ number_format($backupResult->ExaminedFiles) }}</p>
                    <p><strong>Size of Examined Files:</strong> {{ $this->formatSize($backupResult->SizeOfExaminedFiles) }}</p>
                    <p><strong>Added Files:</strong> {{ number_format($backupResult->AddedFiles) }}</p>
                    <p><strong>Size of Added Files:</strong> {{ $this->formatSize($backupResult->SizeOfAddedFiles) }}</p>
                    <p><strong>Modified Files:</strong> {{ number_format($backupResult->ModifiedFiles) }}</p>
                    <p><strong>Size of Modified Files:</strong> {{ $this->formatSize($backupResult->SizeOfModifiedFiles) }}</p>
                </div>
            </div>

            <h3 class="text-xl font-semibold mb-2">Backend Statistics</h3>
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                @foreach($backupResult->BackendStatistics as $key => $value)
                    <p><strong>{{ $key }}:</strong> {{ is_numeric($value) ? $this->formatSize($value) : $value }}</p>
                @endforeach
            </div>

            @if($backupResult->log_lines)
                <h3 class="text-xl font-semibold mb-2">Log Lines</h3>
                <div class="bg-gray-100 dark:bg-gray-700 p-4 rounded-lg overflow-auto max-h-96">
                    @foreach($backupResult->log_lines as $line)
                        <p class="mb-1">{{ $line }}</p>
                    @endforeach
                </div>
            @endif
        </div>
    </div>
</div>
