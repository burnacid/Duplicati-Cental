<div wire:poll.60s class="space-y-4">
    <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
        <a href="{{ route('backup-servers.index') }}" wire:navigate class="block bg-white dark:bg-zinc-900 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-zinc-300 dark:hover:ring-zinc-600">
            <flux:subheading>Backup Servers</flux:subheading>
            <p class="text-3xl font-bold">{{ $backupServersCount }}</p>
        </a>
        <a href="{{ route('backups') }}" wire:navigate class="block bg-white dark:bg-zinc-900 shadow-sm sm:rounded-lg p-6 hover:ring-2 hover:ring-zinc-300 dark:hover:ring-zinc-600">
            <flux:subheading>Backup Jobs</flux:subheading>
            <p class="text-3xl font-bold">{{ $backupsCount }}</p>
        </a>
        <div class="bg-white dark:bg-zinc-900 shadow-sm sm:rounded-lg p-6">
            <flux:subheading>Total Backup Volume</flux:subheading>
            <p class="text-3xl font-bold">{{ $totalBackupVolume }}</p>
        </div>
    </div>

    <div class="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <a href="{{ route('backup-results', ['status' => 'Success']) }}" wire:navigate class="block rounded-lg p-4 bg-green-50 dark:bg-green-950/40 border border-green-200 dark:border-green-900">
            <p class="text-sm text-green-800 dark:text-green-300">Healthy</p>
            <p class="text-2xl font-bold text-green-900 dark:text-green-200">{{ $healthyCount }}</p>
        </a>
        <a href="{{ route('backup-results', ['status' => 'Warning']) }}" wire:navigate class="block rounded-lg p-4 bg-amber-50 dark:bg-amber-950/40 border border-amber-200 dark:border-amber-900">
            <p class="text-sm text-amber-800 dark:text-amber-300">Warning</p>
            <p class="text-2xl font-bold text-amber-900 dark:text-amber-200">{{ $warningCount }}</p>
        </a>
        <a href="{{ route('backup-results', ['status' => 'Failed']) }}" wire:navigate class="block rounded-lg p-4 bg-red-50 dark:bg-red-950/40 border border-red-200 dark:border-red-900">
            <p class="text-sm text-red-800 dark:text-red-300">Failed</p>
            <p class="text-2xl font-bold text-red-900 dark:text-red-200">{{ $failedCount }}</p>
        </a>
        <a href="#needs-attention" class="block rounded-lg p-4 bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700">
            <p class="text-sm text-zinc-700 dark:text-zinc-300">Overdue</p>
            <p class="text-2xl font-bold">{{ $overdueCount }}</p>
        </a>
    </div>
</div>
