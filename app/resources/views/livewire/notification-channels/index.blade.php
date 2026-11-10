<div class="p-6 bg-white dark:bg-zinc-800 rounded-lg shadow-md">
    <div class="flex items-center justify-between mb-4">
        <div>
            <flux:heading size="xl">Notification Channels</flux:heading>
            <flux:subheading>Where to send alerts about backup results and overdue backups.</flux:subheading>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="create">Add channel</flux:button>
    </div>

    @if($flash)
        <flux:callout :variant="$flashVariant" class="mb-4" :icon="$flashVariant === 'danger' ? 'exclamation-triangle' : 'check-circle'">
            <flux:callout.text>{{ $flash }}</flux:callout.text>
        </flux:callout>
    @endif

    @if($channels->isEmpty())
        <div class="text-center py-10">
            <flux:heading>No notification channels yet</flux:heading>
            <flux:subheading class="mt-1">Add an email address, Discord webhook or generic webhook to get alerted when a backup fails.</flux:subheading>
        </div>
    @else
        <ul class="divide-y divide-zinc-200 dark:divide-zinc-700">
            @foreach($channels as $channel)
                <li class="py-3 flex flex-wrap items-center justify-between gap-3" wire:key="channel-{{ $channel->id }}">
                    <div>
                        <div class="flex items-center gap-2">
                            <span class="font-medium">{{ $channel->name }}</span>
                            <flux:badge size="sm">{{ $types[$channel->type] ?? $channel->type }}</flux:badge>
                            @unless($channel->is_active)
                                <flux:badge size="sm" color="zinc">Paused</flux:badge>
                            @endunless
                        </div>
                        <p class="text-sm text-zinc-500">
                            {{ match($channel->notify_on) { 'all' => 'Every result', 'error' => 'Errors only', default => 'Warnings and errors' } }}
                            @if($channel->notify_overdue) &middot; overdue backups @endif
                            @if(in_array($channel->digest, ['daily', 'weekly'], true)) &middot; {{ $channel->digest }} summary @endif
                        </p>
                    </div>
                    <div class="flex items-center gap-1">
                        <flux:switch :checked="$channel->is_active" wire:click="toggleActive({{ $channel->id }})" aria-label="Active" />
                        <flux:button size="sm" variant="ghost" icon="paper-airplane" wire:click="sendTest({{ $channel->id }})">Test</flux:button>
                        <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="edit({{ $channel->id }})" aria-label="Edit {{ $channel->name }}" />
                        <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600 dark:text-red-400" wire:click="confirmDelete({{ $channel->id }})" aria-label="Delete {{ $channel->name }}" />
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <flux:modal name="channel-form" class="w-full max-w-xl">
        <form wire:submit="save" class="space-y-5">
            <flux:heading size="lg">{{ $editingId ? 'Edit channel' : 'New channel' }}</flux:heading>

            <flux:input wire:model="name" label="Name" required />

            <flux:select wire:model.live="type" label="Type">
                @foreach($types as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>

            <flux:select wire:model="notify_on" label="Send results">
                <flux:select.option value="all">Every result</flux:select.option>
                <flux:select.option value="warning">Warnings and errors</flux:select.option>
                <flux:select.option value="error">Errors only</flux:select.option>
            </flux:select>

            <flux:checkbox wire:model="notify_overdue" label="Alert when a backup stops reporting (overdue)" />

            <flux:select wire:model="digest" label="Summary" description="An overview of all jobs: runs, failures, overdue and snoozed jobs. Sent at {{ config('backups.digest_time') }}; weekly on Mondays.">
                <flux:select.option value="off">No summary</flux:select.option>
                <flux:select.option value="daily">Daily summary</flux:select.option>
                <flux:select.option value="weekly">Weekly summary</flux:select.option>
            </flux:select>

            <flux:checkbox wire:model="is_active" label="Active" />

            @if($type === 'email')
                <flux:input wire:model="config.to" label="To" placeholder="ops@example.com, admin@example.com" description="Separate multiple addresses with commas." />
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:input wire:model="config.cc" label="CC" />
                    <flux:input wire:model="config.bcc" label="BCC" />
                </div>
                <details class="rounded-lg border border-zinc-200 dark:border-zinc-700 p-3">
                    <summary class="cursor-pointer text-sm font-medium">Custom SMTP server (optional)</summary>
                    <p class="text-xs text-zinc-500 mt-1 mb-3">Leave the hostname empty to use the application's mail settings.</p>
                    <div class="space-y-4">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div class="sm:col-span-2"><flux:input wire:model="config.hostname" label="Hostname" placeholder="smtp.example.com" /></div>
                            <flux:input wire:model="config.port" type="number" label="Port" min="1" max="65535" />
                        </div>
                        <flux:select wire:model="config.security" label="Security">
                            <flux:select.option value="starttls">STARTTLS</flux:select.option>
                            <flux:select.option value="tls">TLS (SMTPS)</flux:select.option>
                            <flux:select.option value="none">None</flux:select.option>
                        </flux:select>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <flux:input wire:model="config.username" label="Username" autocomplete="off" />
                            <flux:input wire:model="config.password" type="password" label="Password" autocomplete="new-password" :placeholder="$editingId ? 'Leave empty to keep current' : ''" />
                        </div>
                        <flux:input wire:model="config.from_email" type="email" label="From address" />
                    </div>
                </details>
            @elseif($type === 'discord')
                <flux:input wire:model="config.webhook_url" label="Discord webhook URL" placeholder="https://discord.com/api/webhooks/..." />
            @elseif($type === 'slack')
                <flux:input wire:model="config.webhook_url" label="Slack webhook URL" placeholder="https://hooks.slack.com/services/..."
                            description="Create an incoming webhook in your Slack app settings." />
            @elseif($type === 'teams')
                <flux:input wire:model="config.webhook_url" label="Teams workflow URL" placeholder="https://...logic.azure.com/workflows/..."
                            description="In Teams, add the workflow &quot;Post to a channel when a webhook request is received&quot; and paste its URL." />
            @elseif($type === 'telegram')
                <flux:input wire:model="config.bot_token" type="password" label="Bot token" autocomplete="off" placeholder="{{ $editingId ? 'Leave empty to keep current' : '123456789:AA...' }}"
                            description="Create a bot with @BotFather." />
                <flux:input wire:model="config.chat_id" label="Chat ID" placeholder="-1001234567890 or @channelname"
                            description="The user, group or channel the bot posts to. Send the bot a message first." />
            @elseif($type === 'ntfy')
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <flux:input wire:model="config.server_url" label="Server" placeholder="https://ntfy.sh" />
                    <flux:input wire:model="config.topic" label="Topic" placeholder="my-backups" />
                </div>
                <flux:input wire:model="config.token" type="password" label="Access token (optional)" autocomplete="off" :placeholder="$editingId ? 'Leave empty to keep current' : 'tk_...'"
                            description="Only needed for protected topics. On ntfy.sh, anyone who knows the topic name can read it." />
            @elseif($type === 'pushover')
                <flux:input wire:model="config.user_key" label="User or group key" />
                <flux:input wire:model="config.app_token" type="password" label="Application token" autocomplete="off" :placeholder="$editingId ? 'Leave empty to keep current' : ''"
                            description="Create an application on pushover.net to get a token." />
            @elseif($type === 'webhook')
                <div class="grid grid-cols-1 sm:grid-cols-4 gap-4">
                    <flux:select wire:model="config.method" label="Method">
                        @foreach(['POST', 'PUT', 'PATCH', 'GET'] as $m)
                            <flux:select.option value="{{ $m }}">{{ $m }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="sm:col-span-3"><flux:input wire:model="config.url" label="URL" placeholder="https://example.com/hook" /></div>
                </div>
                <flux:textarea wire:model="config.headers" label="Headers (JSON, optional)" rows="3" placeholder='{"Authorization": "Bearer ..."}' />
                <p class="text-xs text-zinc-500">The body is JSON with <code>event</code>, <code>status</code>, <code>server</code>, <code>backup</code>, <code>subject</code> and <code>message</code>.</p>
            @endif

            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button type="submit" variant="primary">Save</flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal name="delete-channel" class="max-w-md">
        <div class="space-y-6">
            <flux:heading size="lg">Delete notification channel?</flux:heading>
            <div class="flex justify-end gap-2">
                <flux:modal.close><flux:button variant="ghost">Cancel</flux:button></flux:modal.close>
                <flux:button variant="danger" wire:click="delete">Delete</flux:button>
            </div>
        </div>
    </flux:modal>
</div>
