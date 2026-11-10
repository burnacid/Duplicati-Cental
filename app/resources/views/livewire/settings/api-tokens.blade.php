<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('API tokens')" :subheading="__('Read-only access to your servers, jobs and results for scripts and monitoring')">
        <form wire:submit="createToken" class="space-y-4">
            <flux:input wire:model="name" :label="__('Name')" placeholder="Prometheus, Home Assistant, …" required />
            <flux:select wire:model="expiresIn" :label="__('Expires after')">
                @foreach(\App\Livewire\Settings\ApiTokens::EXPIRY_OPTIONS as $value => $label)
                    <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:button type="submit" variant="primary" icon="key">{{ __('Create token') }}</flux:button>
        </form>

        <div class="my-8"><flux:separator /></div>

        <flux:heading size="sm" class="mb-1">{{ __('Your tokens') }}</flux:heading>
        @forelse($tokens as $token)
            <div class="flex items-center justify-between gap-4 border-b border-zinc-200 py-3 last:border-b-0 dark:border-zinc-700" wire:key="token-{{ $token->id }}">
                <div class="min-w-0">
                    <flux:text class="font-medium text-zinc-800 dark:text-white">{{ $token->name }}</flux:text>
                    <flux:text size="sm">
                        {{ __('Created :date', ['date' => $token->created_at->format('Y-m-d')]) }}
                        · {{ $token->last_used_at ? __('last used :time', ['time' => $token->last_used_at->diffForHumans()]) : __('never used') }}
                        · @if(! $token->expires_at) {{ __('does not expire') }}
                          @elseif($token->expires_at->isPast()) <span class="text-red-600 dark:text-red-400">{{ __('expired') }}</span>
                          @else {{ __('expires :date', ['date' => $token->expires_at->format('Y-m-d')]) }} @endif
                    </flux:text>
                </div>
                <flux:button size="sm" variant="ghost" icon="trash" wire:click="revoke({{ $token->id }})"
                             wire:confirm="{{ __('Revoke this token? Scripts using it stop working.') }}">{{ __('Revoke') }}</flux:button>
            </div>
        @empty
            <flux:text class="mt-2">{{ __('No tokens yet.') }}</flux:text>
        @endforelse

        <flux:callout icon="information-circle" color="zinc" class="mt-8">
            <flux:callout.text>
                {{ __('Send the token as a bearer token:') }}
                <code class="block mt-2 break-all">curl -H "Authorization: Bearer &lt;token&gt;" {{ url('/api/v1/jobs') }}</code>
                <span class="block mt-2">{{ __('Endpoints: /api/v1/summary, /servers, /jobs, /results, /results/{id} and /metrics (Prometheus).') }}</span>
            </flux:callout.text>
        </flux:callout>
    </x-settings.layout>

    <flux:modal name="new-token" class="w-full max-w-lg" @close="forgetPlainToken">
        <div class="space-y-4">
            <flux:heading size="lg">{{ __('Your new token') }}</flux:heading>
            <flux:text>{{ __('Copy it now. It is not shown again.') }}</flux:text>
            <flux:input :value="$plainToken" readonly copyable aria-label="{{ __('API token') }}" />
            <div class="flex justify-end">
                <flux:modal.close>
                    <flux:button variant="primary">{{ __('Done') }}</flux:button>
                </flux:modal.close>
            </div>
        </div>
    </flux:modal>
</section>
