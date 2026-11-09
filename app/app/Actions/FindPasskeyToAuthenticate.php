<?php

namespace App\Actions;

use Illuminate\Support\Facades\Log;
use Spatie\LaravelPasskeys\Actions\FindPasskeyToAuthenticateAction;
use Spatie\LaravelPasskeys\Models\Passkey;
use Throwable;

/**
 * The package throws (HTTP 500) on malformed passkey responses.
 * Treat any invalid input as a failed sign-in instead.
 */
class FindPasskeyToAuthenticate extends FindPasskeyToAuthenticateAction
{
    public function execute(string $publicKeyCredentialJson, string $passkeyOptionsJson): ?Passkey
    {
        try {
            return parent::execute($publicKeyCredentialJson, $passkeyOptionsJson);
        } catch (Throwable $e) {
            Log::info('Passkey sign-in rejected', ['reason' => $e->getMessage()]);

            return null;
        }
    }
}
