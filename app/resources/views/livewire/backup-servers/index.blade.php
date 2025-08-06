<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md">
    <h2 class="text-2xl font-bold mb-4 text-gray-800 dark:text-white">Backup Servers</h2>

    @if (session()->has('message'))
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative mb-4" role="alert">
            <span class="block sm:inline">{{ session('message') }}</span>
        </div>
    @endif

    <div class="mb-4">
        <a href="{{ route('backup-servers.create') }}" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded inline-block">
            Add New Server
        </a>
    </div>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        @foreach($backupServers as $server)
            <div class="bg-gray-50 dark:bg-zinc-700 rounded-lg shadow-md overflow-hidden">
                <div class="p-4">
                    <h3 class="text-lg font-semibold text-gray-800 dark:text-gray-200 mb-2">{{ $server->name }}</h3>
                    <p class="text-gray-600 dark:text-gray-300 mb-4">{{ $server->description }}</p>
                    <p>{{$server->BackupServerUrl}}</p>
                    <div class="flex justify-end space-x-2">
                        <a href="{{ route('backup-servers.edit', $server) }}" class="text-blue-500 hover:text-blue-700 dark:hover:text-blue-400">
                            <span class="sr-only">Edit</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793zM11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z" />
                            </svg>
                        </a>
                        <button
                            @click="$dispatch('open-delete-modal', { serverId: '{{ $server->id }}' })"
                            class="text-red-500 hover:text-red-700 dark:hover:text-red-400"
                        >
                            <span class="sr-only">Delete</span>
                            <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor">
                                <path fill-rule="evenodd" d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd" />
                            </svg>
                        </button>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    @if($backupServers->isEmpty())
        <p class="text-gray-600 dark:text-gray-400 text-center mt-4">No backup servers found.</p>
    @endif

    <!-- Delete Confirmation Modal -->
    <div
        x-data="{ show: false, serverId: null }"
        x-show="show"
        @open-delete-modal.window="show = true; serverId = $event.detail.serverId"
        @close-delete-modal.window="show = false"
        class="fixed inset-0 overflow-y-auto px-4 py-6 md:py-24 sm:px-0 z-40"
        x-cloak
    >
        <div class="fixed inset-0 transform" @click="show = false">
            <div class="absolute inset-0 bg-gray-500 opacity-75"></div>
        </div>

        <div class="bg-white dark:bg-zinc-800 rounded-lg overflow-hidden transform max-w-lg w-full mx-auto">
            <div class="p-6">
                <h3 class="text-lg font-medium text-gray-900 dark:text-white mb-4">Confirm Deletion</h3>
                <p class="text-gray-700 dark:text-gray-300 mb-4">Are you sure you want to delete this backup server? This action cannot be undone.</p>
                <div class="flex justify-end">
                    <button @click="show = false" class="bg-gray-300 hover:bg-gray-400 text-gray-800 font-bold py-2 px-4 rounded mr-2">
                        Cancel
                    </button>
                    <button @click="$wire.deleteServer(serverId); show = false" class="bg-red-500 hover:bg-red-700 text-white font-bold py-2 px-4 rounded">
                        Delete
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>
