<?php

use App\Models\User;
use App\Services\TwoFactorAuthenticationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('components.layouts.auth')] class extends Component {
    public string $code = '';
    public bool $useRecoveryCode = false;

    public function mount(): void
    {
        if (! $this->pendingUser()) {
            Session::forget('login.two_factor');
            $this->redirect(route('login', absolute: false), navigate: true);
        }
    }

    public function toggleRecovery(): void
    {
        $this->useRecoveryCode = ! $this->useRecoveryCode;
        $this->code = '';
        $this->resetValidation();
    }

    public function verify(TwoFactorAuthenticationService $twoFactor): void
    {
        $user = $this->pendingUser();

        if (! $user) {
            Session::forget('login.two_factor');
            $this->redirect(route('login', absolute: false), navigate: true);

            return;
        }

        $this->validate(['code' => 'required|string|max:32']);

        $key = 'two-factor:' . $user->getKey() . '|' . request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Try again in :seconds seconds.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $valid = $this->useRecoveryCode
            ? $twoFactor->useRecoveryCode($user, $this->code)
            : $twoFactor->verify($user, $this->code);

        if (! $valid) {
            RateLimiter::hit($key);
            $this->code = '';

            throw ValidationException::withMessages([
                'code' => $this->useRecoveryCode ? __('That recovery code is not valid.') : __('That code is not valid.'),
            ]);
        }

        RateLimiter::clear($key);
        $remember = (bool) (Session::get('login.two_factor')['remember'] ?? false);
        Session::forget('login.two_factor');

        Auth::login($user, $remember);
        Session::regenerate();

        $this->redirectIntended(default: route('home', absolute: false), navigate: true);
    }

    private function pendingUser(): ?User
    {
        $pending = Session::get('login.two_factor');

        if (! is_array($pending) || ($pending['expires_at'] ?? 0) < now()->timestamp) {
            return null;
        }

        $user = User::find($pending['id'] ?? null);

        return $user?->hasTwoFactorEnabled() ? $user : null;
    }
}; ?>

<div class="flex flex-col gap-6">
    <div class="text-center">
        <flux:heading size="lg">{{ __('Two-factor authentication') }}</flux:heading>
        <flux:subheading>
            {{ $useRecoveryCode
                ? __('Enter one of your recovery codes.')
                : __('Enter the 6-digit code from your authenticator app.') }}
        </flux:subheading>
    </div>

    <form wire:submit="verify" class="flex flex-col gap-6">
        @if ($useRecoveryCode)
            <flux:input wire:model="code" :label="__('Recovery code')" autocomplete="off" autofocus required placeholder="abcde-fghij" />
        @else
            <flux:input wire:model="code" :label="__('Code')" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus required placeholder="123456" />
        @endif

        <flux:button variant="primary" type="submit" class="w-full">{{ __('Verify') }}</flux:button>
    </form>

    <div class="flex flex-col items-center gap-1 text-sm">
        <flux:link href="#" wire:click.prevent="toggleRecovery">
            {{ $useRecoveryCode ? __('Use an authentication code') : __('Use a recovery code') }}
        </flux:link>
        <flux:link :href="route('login')" wire:navigate>{{ __('Back to login') }}</flux:link>
    </div>
</div>
