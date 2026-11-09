<?php

namespace App\Livewire\Settings;

use App\Services\TwoFactorAuthenticationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Enable/disable TOTP and manage recovery codes.
 * The secret being set up is kept in the session, not in the component state.
 */
class TwoFactor extends Component
{
    private const PENDING_KEY = 'two_factor.pending_secret';

    public string $code = '';

    /** Recovery codes are only shown right after they are generated. */
    public array $recoveryCodes = [];

    public function startSetup(TwoFactorAuthenticationService $twoFactor): void
    {
        session()->put(self::PENDING_KEY, $twoFactor->generateSecret());
        $this->code = '';
        $this->recoveryCodes = [];
        $this->resetValidation();
    }

    public function cancelSetup(): void
    {
        session()->forget(self::PENDING_KEY);
        $this->code = '';
        $this->resetValidation();
    }

    public function confirmSetup(TwoFactorAuthenticationService $twoFactor): void
    {
        $secret = session(self::PENDING_KEY);
        $this->validate(['code' => 'required|string|max:10']);

        if (! $secret || ! $twoFactor->verify(Auth::user(), $this->code, $secret)) {
            $this->code = '';
            throw ValidationException::withMessages(['code' => __('That code is not valid. Check the time on your phone and try again.')]);
        }

        $this->recoveryCodes = $twoFactor->enable(Auth::user(), $secret);
        session()->forget(self::PENDING_KEY);
        $this->code = '';
    }

    public function regenerateRecoveryCodes(TwoFactorAuthenticationService $twoFactor): void
    {
        $user = Auth::user();
        abort_unless($user->hasTwoFactorEnabled(), 403);

        $this->recoveryCodes = $twoFactor->generateRecoveryCodes();
        $user->forceFill(['two_factor_recovery_codes' => $this->recoveryCodes])->save();
    }

    public function hideRecoveryCodes(): void
    {
        $this->recoveryCodes = [];
    }

    public function disable(TwoFactorAuthenticationService $twoFactor): void
    {
        $twoFactor->disable(Auth::user());
        $this->recoveryCodes = [];
    }

    public function render(TwoFactorAuthenticationService $twoFactor)
    {
        $user = Auth::user();
        $pending = session(self::PENDING_KEY);

        return view('livewire.settings.two-factor', [
            'enabled' => $user->hasTwoFactorEnabled(),
            'pendingSecret' => $pending,
            'qrCode' => $pending ? $twoFactor->qrCodeSvg($user, $pending) : null,
            'remainingCodes' => count($user->two_factor_recovery_codes ?? []),
        ]);
    }
}
