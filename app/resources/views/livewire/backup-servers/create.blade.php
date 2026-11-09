<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md max-w-2xl">
    <flux:heading size="xl" class="mb-1">Add Backup Server</flux:heading>
    <flux:subheading class="mb-6">After saving you get a reporting URL to paste into Duplicati.</flux:subheading>

    <form wire:submit="save" class="space-y-6">
        <flux:input wire:model="name" label="Name" placeholder="e.g. Office NAS" required autofocus />
        <flux:textarea wire:model="description" label="Description" rows="3" />

        <div class="flex items-center gap-2">
            <flux:button type="submit" variant="primary">Create server</flux:button>
            <flux:button variant="ghost" :href="route('backup-servers.index')" wire:navigate>Cancel</flux:button>
        </div>
    </form>
</div>
