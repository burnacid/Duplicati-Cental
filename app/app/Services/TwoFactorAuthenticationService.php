<?php

namespace App\Services;

use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/**
 * Time-based one-time passwords (RFC 6238, as used by authenticator apps)
 * and one-time recovery codes.
 */
class TwoFactorAuthenticationService
{
    public const RECOVERY_CODE_COUNT = 8;

    /** Accept codes from one 30-second step before/after now (clock drift). */
    private const WINDOW = 1;

    public function __construct(private readonly Google2FA $google2fa = new Google2FA) {}

    public function generateSecret(): string
    {
        return $this->google2fa->generateSecretKey(32);
    }

    public function otpauthUrl(User $user, string $secret): string
    {
        return $this->google2fa->getQRCodeUrl((string) config('app.name'), $user->email, $secret);
    }

    public function qrCodeSvg(User $user, string $secret): string
    {
        $writer = new Writer(new ImageRenderer(new RendererStyle(192, 1), new SvgImageBackEnd));

        // Inline in HTML: drop the XML declaration.
        return (string) preg_replace('/^<\?xml[^>]*\?>\s*/', '', $writer->writeString($this->otpauthUrl($user, $secret)));
    }

    /**
     * Check a code against the user's secret (or a pending one during setup).
     * A code (time step) that was already accepted is rejected, so it cannot be replayed.
     */
    public function verify(User $user, string $code, ?string $secret = null): bool
    {
        $secret ??= $user->two_factor_secret;
        $code = preg_replace('/\s+/', '', $code);

        if ($secret === null || ! preg_match('/^\d{6}$/', (string) $code)) {
            return false;
        }

        $timestep = $this->google2fa->verifyKeyNewer($secret, $code, (int) ($user->two_factor_last_timestep ?? 0), self::WINDOW);

        if ($timestep === false || $timestep === true) {
            return false;
        }

        $user->forceFill(['two_factor_last_timestep' => (int) $timestep])->save();

        return true;
    }

    /** @return list<string> */
    public function generateRecoveryCodes(): array
    {
        return array_map(
            fn () => Str::lower(Str::random(5).'-'.Str::random(5)),
            range(1, self::RECOVERY_CODE_COUNT),
        );
    }

    /**
     * Use a recovery code once. Returns false when it is not valid.
     */
    public function useRecoveryCode(User $user, string $code): bool
    {
        $code = Str::lower(trim($code));
        $codes = $user->two_factor_recovery_codes ?? [];

        foreach ($codes as $index => $stored) {
            if (hash_equals($stored, $code)) {
                unset($codes[$index]);
                $user->forceFill(['two_factor_recovery_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    public function enable(User $user, string $secret): array
    {
        $codes = $this->generateRecoveryCodes();

        $user->forceFill([
            'two_factor_secret' => $secret,
            'two_factor_recovery_codes' => $codes,
            'two_factor_confirmed_at' => now(),
        ])->save();

        return $codes;
    }

    public function disable(User $user): void
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_timestep' => null,
        ])->save();
    }
}
