<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md">
    <h2 class="text-2xl font-bold mb-4 text-gray-800 dark:text-white">Edit Backup Server</h2>

    <form wire:submit.prevent="save">
        <div class="mb-4">
            <label for="name" class="block text-gray-700 dark:text-gray-200 text-sm font-bold mb-2">Name:</label>
            <input type="text" id="name" wire:model="name" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 dark:text-gray-200 dark:bg-zinc-700 dark:border-zinc-600 leading-tight focus:outline-none focus:shadow-outline">
            @error('name') <span class="text-red-500 text-xs italic">{{ $message }}</span> @enderror
        </div>

        <div class="mb-4">
            <label for="description" class="block text-gray-700 dark:text-gray-200 text-sm font-bold mb-2">Description:</label>
            <textarea id="description" wire:model="description" class="shadow appearance-none border rounded w-full py-2 px-3 text-gray-700 dark:text-gray-200 dark:bg-zinc-700 dark:border-zinc-600 leading-tight focus:outline-none focus:shadow-outline"></textarea>
            @error('description') <span class="text-red-500 text-xs italic">{{ $message }}</span> @enderror
        </div>

        <div class="flex items-center justify-between">
            <button type="submit" class="bg-blue-500 hover:bg-blue-700 text-white font-bold py-2 px-4 rounded focus:outline-none focus:shadow-outline">
                Update Server
            </button>
            <a href="{{ route('backup-servers.index') }}" class="inline-block align-baseline font-bold text-sm text-blue-500 hover:text-blue-800">
                Cancel
            </a>
        </div>
    </form>
</div>
