<?php

use Livewire\Volt\Component;

new class extends Component {
    //
}; ?>

<section class="w-full">
    @include('partials.settings-heading')

    <x-settings.layout :heading="__('Security')" :subheading="__('Protect your account with an authenticator app and passkeys')">
        @if (session('two_factor_required'))
            <flux:callout icon="shield-exclamation" variant="warning" class="mb-6">
                <flux:callout.text>{{ __('This installation requires two-factor authentication. Set up an authenticator app or add a passkey to continue.') }}</flux:callout.text>
            </flux:callout>
        @endif

        <div class="space-y-10">
            <livewire:settings.two-factor />
            <flux:separator />
            <livewire:settings.passkeys />
        </div>
    </x-settings.layout>
</section>
