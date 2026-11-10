<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md">
    <div class="flex items-center justify-between mb-4">
        <flux:heading size="xl">Backup Servers</flux:heading>
        <flux:button variant="primary" icon="plus" :href="route('backup-servers.create')" wire:navigate>Add server</flux:button>
    </div>

    @if (session()->has('message'))
        <flux:callout icon="check-circle" variant="success" class="mb-4">
            <flux:callout.text>{{ session('message') }}</flux:callout.text>
        </flux:callout>
    @endif

    @if($backupServers->isEmpty())
        <div class="text-center py-10">
            <flux:heading>No backup servers yet</flux:heading>
            <flux:subheading class="mt-1">Create one to get a reporting URL for your Duplicati instance.</flux:subheading>
        </div>
    @endif

    <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        @foreach($backupServers as $server)
            @php
                $lastReport = $server->last_report_at ? \Illuminate\Support\Carbon::parse($server->last_report_at, 'UTC')->setTimezone(config('app.timezone')) : null;
                $options = '--send-http-url=' . $server->backup_server_url . ' --send-http-result-output-format=Json --send-http-log-level=Information';
            @endphp
            <div class="bg-zinc-50 dark:bg-zinc-700 rounded-lg shadow-sm p-4 space-y-3" wire:key="server-{{ $server->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <flux:heading size="lg">{{ $server->name }}</flux:heading>
                        @if($server->description)
                            <flux:subheading>{{ $server->description }}</flux:subheading>
                        @endif
                        @if($snoozed = \App\Support\Snooze::label($server->muted_until))
                            <flux:badge size="sm" color="zinc" icon="bell-slash" class="mt-1">Alerts snoozed {{ $snoozed }}</flux:badge>
                        @endif
                    </div>
                    <div class="flex gap-1 shrink-0">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button size="sm" variant="ghost" :icon="$server->isMuted() ? 'bell-slash' : 'bell'" aria-label="Snooze alerts for {{ $server->name }}" />
                            <flux:menu>
                                @if($server->isMuted())
                                    <flux:menu.item icon="bell" wire:click="resumeServer({{ \Illuminate\Support\Js::from($server->id) }})">Resume alerts</flux:menu.item>
                                @else
                                    <flux:menu.heading>Snooze alerts for</flux:menu.heading>
                                    @foreach(\App\Support\Snooze::OPTIONS as $value => $label)
                                        <flux:menu.item wire:click="snoozeServer({{ \Illuminate\Support\Js::from($server->id) }}, {{ \Illuminate\Support\Js::from($value) }})">{{ $label }}</flux:menu.item>
                                    @endforeach
                                @endif
                            </flux:menu>
                        </flux:dropdown>
                        <flux:button size="sm" variant="ghost" icon="pencil-square" :href="route('backup-servers.edit', $server)" wire:navigate aria-label="Edit {{ $server->name }}" />
                        <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600 dark:text-red-400" wire:click="confirmDelete({{ \Illuminate\Support\Js::from($server->id) }})" aria-label="Delete {{ $server->name }}" />
                    </div>
                </div>

                <p class="text-sm text-zinc-600 dark:text-zinc-300">
                    {{ $server->backup_jobs_count }} backup {{ \Illuminate\Support\Str::plural('job', $server->backup_jobs_count) }}
                    &middot; last report {{ $lastReport?->diffForHumans() ?? 'never' }}
                </p>

                <x-copy-field label="Reporting URL" :value="$server->backup_server_url" />
                <x-copy-field label="Duplicati options (paste into Default options → Edit as text)" :value="$options" />

                <div class="flex justify-end">
                    <flux:button size="xs" variant="ghost" icon="arrow-path" wire:click="confirmRegenerate({{ \Illuminate\Support\Js::from($server->id) }})">Regenerate API key</flux:button>
                </div>
            </div>
        @endforeach
    </div>

    <flux:modal name="delete-server" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Delete backup server?</flux:heading>
                <flux:subheading>The server and all of its stored backup results will be removed. This cannot be undone.</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="deleteServer">Delete</flux:button>
            </div>
        </div>
    </flux:modal>

    <flux:modal name="regenerate-key" class="max-w-md">
        <div class="space-y-6">
            <div>
                <flux:heading size="lg">Regenerate API key?</flux:heading>
                <flux:subheading>The current reporting URL stops working immediately. You will need to update it in Duplicati.</flux:subheading>
            </div>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="primary" wire:click="regenerateKey">Regenerate</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
