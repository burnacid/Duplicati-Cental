<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md max-w-2xl">
    <flux:heading size="xl" class="mb-6">Edit Backup Server</flux:heading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" required />
        <flux:textarea wire:model="description" label="Description" rows="3" />
        <x-copy-field label="Reporting URL" :value="$backupServer->backup_server_url" />

        <div class="flex items-center gap-2">
            <flux:button type="submit" variant="primary">Save</flux:button>
            <flux:button variant="ghost" :href="route('backup-servers.index')" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
