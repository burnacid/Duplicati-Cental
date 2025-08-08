<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-6">
    <div class="bg-white dark:bg-zinc-900 overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-gray-900 dark:text-gray-100">
            <h2 class="text-xl font-semibold mb-2">Backup Servers</h2>
            <p class="text-3xl font-bold">{{ $backupServersCount }}</p>
        </div>
    </div>
    <div class="bg-white dark:bg-zinc-900 overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-gray-900 dark:text-gray-100">
            <h2 class="text-xl font-semibold mb-2">Total Backups</h2>
            <p class="text-3xl font-bold">{{ $backupsCount }}</p>
        </div>
    </div>
    <div class="bg-white dark:bg-zinc-900 overflow-hidden shadow-sm sm:rounded-lg">
        <div class="p-6 text-gray-900 dark:text-gray-100">
            <h2 class="text-xl font-semibold mb-2">Total Backup Volume</h2>
            <p class="text-3xl font-bold">{{ $totalBackupVolume }} {{ $totalBackupVolumeUnit }}</p>
        </div>
    </div>
</div>
