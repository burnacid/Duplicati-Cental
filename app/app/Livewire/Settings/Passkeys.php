<?php

namespace App\Livewire\Settings;

use Spatie\LaravelPasskeys\Livewire\PasskeysComponent;

/**
 * Passkey management using spatie/laravel-passkeys for the WebAuthn
 * ceremony, with this app's own (Flux) view and error handling.
 */
class Passkeys extends PasskeysComponent
{
    public ?string $browserError = null;

    public function render(): \Illuminate\View\View
    {
        return view('livewire.settings.passkeys', [
            'passkeys' => $this->currentUser()->passkeys()->latest()->get(),
        ]);
    }

    public function validatePasskeyProperties(): void
    {
        $this->browserError = null;
        parent::validatePasskeyProperties();
    }

    public function storePasskey(string $passkey): void
    {
        parent::storePasskey($passkey);
        $this->browserError = null;
    }

    /** Called from the browser when the WebAuthn prompt fails or is cancelled. */
    public function registrationFailed(string $reason): void
    {
        session()->forget('passkey-registration-options');
        $this->browserError = $reason === 'NotAllowedError'
            ? __('Creating the passkey was cancelled or timed out.')
            : ($reason === 'InvalidStateError'
                ? __('This device already has a passkey for your account.')
                : __('Creating the passkey failed. Your browser or device may not support passkeys.'));
    }
}
