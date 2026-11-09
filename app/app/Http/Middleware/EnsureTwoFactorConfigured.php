<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * With REQUIRE_2FA=true, users without TOTP or a passkey are sent to the
 * security settings until they set one up.
 */
class EnsureTwoFactorConfigured
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (config('auth.require_two_factor') && $user && ! $user->hasStrongAuthentication()) {
            if ($request->expectsJson()) {
                abort(403, 'Two-factor authentication must be set up first.');
            }

            return redirect()->route('settings.security')->with('two_factor_required', true);
        }

        return $next($request);
    }
}
