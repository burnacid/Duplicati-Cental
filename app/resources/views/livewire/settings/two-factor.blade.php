<div class="space-y-4">
    <div class="flex items-center gap-2">
        <flux:heading size="lg">{{ __('Authenticator app') }}</flux:heading>
        @if ($enabled)
            <flux:badge size="sm" color="green">{{ __('On') }}</flux:badge>
        @else
            <flux:badge size="sm" color="zinc">{{ __('Off') }}</flux:badge>
        @endif
    </div>
    <flux:subheading>{{ __('After your password, ask for a 6-digit code from an app such as Google Authenticator, Microsoft Authenticator, 1Password or Bitwarden.') }}</flux:subheading>

    @if ($recoveryCodes)
        <flux:callout icon="key" variant="warning">
            <flux:callout.heading>{{ __('Save your recovery codes') }}</flux:callout.heading>
            <flux:callout.text>{{ __('Each code works once, if you lose access to your authenticator app. They are shown only now.') }}</flux:callout.text>
        </flux:callout>
        <div x-data class="rounded-lg bg-zinc-100 p-4 font-mono text-sm dark:bg-zinc-800">
            <div class="grid grid-cols-2 gap-1" x-ref="codes">
                @foreach ($recoveryCodes as $recoveryCode)
                    <span>{{ $recoveryCode }}</span>
                @endforeach
            </div>
            <div class="mt-3 flex gap-2">
                <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText(@js(implode(PHP_EOL, $recoveryCodes)))">{{ __('Copy') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="hideRecoveryCodes">{{ __('I have saved them') }}</flux:button>
            </div>
        </div>
    @endif

    @if ($enabled)
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm text-zinc-500">{{ trans_choice(':count recovery code left|:count recovery codes left', $remainingCodes, ['count' => $remainingCodes]) }}</span>
            <flux:button size="sm" wire:click="regenerateRecoveryCodes" wire:confirm="{{ __('Create new recovery codes? The old ones stop working.') }}">{{ __('New recovery codes') }}</flux:button>
            <flux:button size="sm" variant="danger" wire:click="disable" wire:confirm="{{ __('Turn off the authenticator app? Your password alone will be enough to sign in.') }}">{{ __('Turn off') }}</flux:button>
        </div>
    @elseif ($pendingSecret)
        <div class="space-y-4 rounded-lg border border-zinc-200 p-4 dark:border-zinc-700">
            <p class="text-sm">{{ __('1. Scan this QR code with your authenticator app.') }}</p>
            <div class="inline-block rounded-lg bg-white p-2">{!! $qrCode !!}</div>
            <p class="text-xs text-zinc-500">
                {{ __('Cannot scan? Enter this key manually:') }}
                <span class="font-mono break-all select-all">{{ trim(chunk_split($pendingSecret, 4, ' ')) }}</span>
            </p>

            <form wire:submit="confirmSetup" class="space-y-3">
                <flux:input wire:model="code" :label="__('2. Enter the 6-digit code it shows')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="123456" required />
                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">{{ __('Turn on') }}</flux:button>
                    <flux:button type="button" variant="ghost" wire:click="cancelSetup">{{ __('Cancel') }}</flux:button>
                </div>
            </form>
        </div>
    @else
        <flux:button wire:click="startSetup" icon="device-phone-mobile">{{ __('Set up authenticator app') }}</flux:button>
    @endif
</div>
