<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Status page')" :subheading="__('A public page with the status of the backups you choose, for colleagues or customers')">
        @if($url)
            <div class="space-y-6">
                <flux:input :value="$url" readonly copyable :label="__('Public link')"
                            :description="__('Anyone with this link can see the page, without signing in.')" />

                <div class="flex flex-wrap gap-2">
                    <flux:button :href="$url" target="_blank" icon="arrow-top-right-on-square">{{ __('Open') }}</flux:button>
                    <flux:button wire:click="regenerate" icon="arrow-path"
                                 wire:confirm="{{ __('Create a new link? The current link stops working.') }}">{{ __('New link') }}</flux:button>
                    <flux:button wire:click="disable" variant="danger" icon="eye-slash"
                                 wire:confirm="{{ __('Turn off the status page? The link stops working.') }}">{{ __('Turn off') }}</flux:button>
                </div>

                <form wire:submit="saveTitle" class="flex items-end gap-2">
                    <div class="flex-1">
                        <flux:input wire:model="title" :label="__('Title')" :placeholder="__('Backup status')" />
                    </div>
                    <flux:button type="submit">{{ __('Save') }}</flux:button>
                </form>
                <x-action-message on="status-page-saved">{{ __('Saved.') }}</x-action-message>

                <div>
                    <flux:heading size="sm">{{ __('On the page') }}</flux:heading>
                    @forelse($jobs as $job)
                        <div class="flex items-center justify-between border-b border-zinc-200 py-2 last:border-b-0 dark:border-zinc-700">
                            <flux:text>{{ $job['name'] }}</flux:text>
                            <flux:text size="sm">{{ ['operational' => __('Operational'), 'degraded' => __('Warnings'), 'down' => __('Problem')][$job['state']] }}</flux:text>
                        </div>
                    @empty
                        <flux:text class="mt-2">{{ __('No jobs yet.') }}</flux:text>
                    @endforelse
                    <flux:text size="sm" class="mt-3">
                        {{ __('Add or remove jobs in') }}
                        <flux:link :href="route('backups')" wire:navigate>{{ __('Backup Management') }}</flux:link>
                        {{ __('→ Settings → "Show on the public status page".') }}
                    </flux:text>
                </div>
            </div>
        @else
            <div class="space-y-4">
                <flux:text>{{ __('The page shows only the jobs you pick, with their status and the time of the last backup. Server names, sizes, paths and logs are never shown.') }}</flux:text>
                <flux:button wire:click="enable" variant="primary" icon="globe-alt">{{ __('Turn on status page') }}</flux:button>
            </div>
        @endif
    </x-settings.layout>
</section>
