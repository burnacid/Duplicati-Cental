<div class="space-y-4" x-data="{ secure: window.isSecureContext && typeof window.PublicKeyCredential !== 'undefined' }">
    <flux:heading size="lg">{{ __('Passkeys') }}</flux:heading>
    <flux:subheading>{{ __('Sign in without a password using your fingerprint, face, device PIN or a security key. A passkey replaces both your password and the authenticator code.') }}</flux:subheading>

    <flux:callout x-show="!secure" x-cloak icon="exclamation-triangle" variant="warning">
        <flux:callout.text>{{ __('Passkeys only work over HTTPS (or on localhost). Open this site over HTTPS to add one.') }}</flux:callout.text>
    </flux:callout>

    <form wire:submit="validatePasskeyProperties" class="flex items-end gap-2">
        <div class="flex-1">
            <flux:input wire:model="name" :label="__('Name')" :placeholder="__('e.g. Work laptop, iPhone')" autocomplete="off" />
        </div>
        <flux:button type="submit" icon="plus" x-bind:disabled="!secure">{{ __('Add passkey') }}</flux:button>
    </form>
    @error('name', 'passkeyForm') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    @error('name') <p class="text-sm text-red-600 dark:text-red-400">{{ $message }}</p> @enderror
    @if ($browserError)
        <p class="text-sm text-red-600 dark:text-red-400">{{ $browserError }}</p>
    @endif

    @if ($passkeys->isNotEmpty())
        <ul class="divide-y divide-zinc-200 rounded-lg border border-zinc-200 dark:divide-zinc-700 dark:border-zinc-700">
            @foreach ($passkeys as $passkey)
                <li class="flex items-center justify-between gap-3 px-4 py-3" wire:key="passkey-{{ $passkey->id }}">
                    <div class="min-w-0">
                        <div class="truncate font-medium">{{ $passkey->name }}</div>
                        <div class="text-xs text-zinc-500">
                            {{ __('Added :date', ['date' => $passkey->created_at?->diffForHumans()]) }}
                            &middot;
                            {{ $passkey->last_used_at ? __('last used :date', ['date' => $passkey->last_used_at->diffForHumans()]) : __('not used yet') }}
                        </div>
                    </div>
                    <flux:button size="sm" variant="ghost" icon="trash" class="text-red-600 dark:text-red-400"
                                 wire:click="deletePasskey({{ $passkey->id }})"
                                 wire:confirm="{{ __('Remove the passkey :name?', ['name' => $passkey->name]) }}"
                                 aria-label="{{ __('Remove') }} {{ $passkey->name }}" />
                </li>
            @endforeach
        </ul>
    @endif
</div>

@script
<script>
    $wire.on('passkeyPropertiesValidated', async (eventData) => {
        try {
            const passkey = await window.startRegistration({ optionsJSON: eventData[0].passkeyOptions });
            await $wire.storePasskey(JSON.stringify(passkey));
        } catch (e) {
            await $wire.registrationFailed(e && e.name ? e.name : 'Error');
        }
    });
</script>
@endscript
