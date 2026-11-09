<div class="py-6">
    <div class="mx-auto max-w-5xl px-4 sm:px-6 lg:px-8 space-y-4">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <flux:heading size="xl">Remote Control</flux:heading>
                <flux:subheading>Duplicati instances claimed by your account.</flux:subheading>
            </div>
            <div class="w-full sm:w-72">
                <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" placeholder="Search machine, id, version…" aria-label="Search" />
            </div>
        </div>

        @if (session('status'))
            <flux:callout icon="check-circle" variant="success">
                <flux:callout.text>{{ session('status') }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="rounded-xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <x-copy-field label="Registration URL" :value="$registrationUrl" />
            <p class="mt-2 text-xs text-zinc-500">
                In Duplicati: Settings &rarr; Remote control &rarr; enter this URL and register, or start the server with
                <span class="font-mono break-all">--register-remote-control={{ $registrationUrl }}</span>.
                Then open the claim link Duplicati shows while you are logged in here.
            </p>
        </div>

        <ul class="space-y-3">
            @forelse ($registrations as $registration)
                @php($online = $registration->agentState?->is_connected)
                <li wire:key="rc-{{ $registration->id }}" class="rounded-xl border border-zinc-200 bg-white shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
                    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 p-4">
                        <span class="h-2.5 w-2.5 shrink-0 rounded-full {{ $online ? 'bg-emerald-500' : 'bg-zinc-400' }}" aria-hidden="true"></span>

                        <a href="{{ route('remote-control.show', $registration->id) }}" wire:navigate class="min-w-0 flex-1 group">
                            <div class="truncate font-medium text-zinc-900 group-hover:underline dark:text-white">{{ $registration->machine_name ?: 'Unknown machine' }}</div>
                            <div class="truncate text-xs text-zinc-500">
                                {{ $online ? 'Online' : 'Offline' }}
                                @if ($registration->agentState?->last_seen_at) &middot; seen {{ $registration->agentState->last_seen_at->diffForHumans() }} @endif
                                &middot; Duplicati {{ $registration->version ?: 'n/a' }}
                                &middot; claimed {{ $registration->claimed_at?->diffForHumans() ?: 'not yet' }}
                            </div>
                        </a>

                        <div class="flex shrink-0 items-center gap-1">
                            <flux:button size="sm" variant="primary" :href="route('remote-control.show', $registration->id)" wire:navigate>Open</flux:button>
                            <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600 dark:text-red-400"
                                         wire:click="removeRegistration({{ \Illuminate\Support\Js::from($registration->id) }})"
                                         wire:confirm="Remove {{ $registration->machine_name ?: 'this machine' }} from remote control? Its backup history stays."
                                         aria-label="Remove {{ $registration->machine_name }}" />
                        </div>
                    </div>

                    <details class="border-t border-zinc-100 px-4 py-2 text-xs text-zinc-500 dark:border-zinc-800">
                        <summary class="cursor-pointer select-none">Connection details</summary>
                        <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-1 sm:grid-cols-[8rem_1fr]">
                            <dt class="font-medium text-zinc-600 dark:text-zinc-400">Machine id</dt>
                            <dd class="break-all font-mono">{{ $registration->machine_id ?: '—' }}</dd>
                            <dt class="font-medium text-zinc-600 dark:text-zinc-400">Reporting URL</dt>
                            <dd class="break-all font-mono">{{ data_get($registration->settings, 'reportingurl', 'not assigned yet') }}</dd>
                            <dt class="font-medium text-zinc-600 dark:text-zinc-400">Relay URL</dt>
                            <dd class="break-all font-mono">{{ $registration->server_url ?: 'not assigned yet' }}</dd>
                        </dl>
                    </details>
                </li>
            @empty
                <li class="rounded-xl border border-dashed border-zinc-300 p-8 text-center text-sm text-zinc-500 dark:border-zinc-700">
                    No claimed instances yet. Register a Duplicati instance with the URL above.
                </li>
            @endforelse
        </ul>

        {{ $registrations->links() }}

        <details class="text-xs text-zinc-500">
            <summary class="cursor-pointer select-none">Server settings</summary>
            <p class="mt-2">Websocket endpoint handed to instances: <span class="font-mono break-all">{{ $bridgeWebsocketUrl }}</span></p>
        </details>
    </div>
</div>
