<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RemoteControlRegistration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class RemoteControlRegistrationController extends Controller
{
    private const RETRY_SECONDS = 5;

    private const MAX_RETRIES = 100;

    private const MAX_LIFETIME_SECONDS = 3600;

    public function register(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'instanceId' => 'nullable|string|max:255',
            'machineId' => 'nullable|string|max:255',
            'machineName' => 'nullable|string|max:255',
            'installId' => 'nullable|string|max:255',
            'version' => 'nullable|string|max:120',
            'operatingSystem' => 'nullable|string|max:120',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $existing = null;

        if ($request->filled('machineId')) {
            $existing = RemoteControlRegistration::query()
                ->where('machine_id', $request->string('machineId')->toString())
                ->where('status', RemoteControlRegistration::STATUS_PENDING)
                ->where('expires_at', '>', now())
                ->latest('created_at')
                ->first();
        }

        // The address this instance used to reach us (honours TRUSTED_PROXIES), so it
        // gets websocket and reporting URLs on that same address when claimed.
        $baseUrl = rtrim($request->getSchemeAndHttpHost(), '/');

        $existing?->update(['registration_base_url' => $baseUrl]);

        $registration = $existing ?? RemoteControlRegistration::query()->create([
            'registration_base_url' => $baseUrl,
            'instance_id' => $request->input('instanceId'),
            'machine_id' => $request->input('machineId'),
            'machine_name' => $request->input('machineName'),
            'install_id' => $request->input('installId'),
            'version' => $request->input('version'),
            'operating_system' => $request->input('operatingSystem'),
            'registration_token' => Str::random(64),
            'claim_token' => Str::random(64),
            'status' => RemoteControlRegistration::STATUS_PENDING,
            'request_payload' => $request->all(),
            'expires_at' => now()->addSeconds(self::MAX_LIFETIME_SECONDS),
        ]);

        return response()->json([
            'claimLink' => route('remote-control.claim.show', ['claimToken' => $registration->claim_token]),
            'statusLink' => route('api.remote-control.status', ['registrationToken' => $registration->registration_token]),
            'retrySeconds' => self::RETRY_SECONDS,
            'maxRetries' => self::MAX_RETRIES,
            'maxLifetimeSeconds' => self::MAX_LIFETIME_SECONDS,
        ]);
    }

    public function status(Request $request, string $registrationToken): JsonResponse
    {
        $registration = RemoteControlRegistration::query()
            ->where('registration_token', $registrationToken)
            ->first();

        if (! $registration) {
            return response()->json([
                'success' => false,
                'statusMessage' => 'Invalid registration token',
            ], 404);
        }

        if ($registration->expires_at instanceof Carbon && $registration->expires_at->isPast()) {
            return response()->json([
                'success' => false,
                'statusMessage' => 'Registration expired',
            ]);
        }

        if ($registration->status !== RemoteControlRegistration::STATUS_CLAIMED) {
            return response()->json([
                'success' => false,
                'statusMessage' => 'Waiting for claim',
            ]);
        }

        return response()->json([
            'success' => true,
            'statusMessage' => 'Machine claimed',
            'jwt' => $registration->jwt,
            'serverUrl' => $registration->server_url,
            'certificateUrl' => $registration->certificate_url,
            'serverCertificates' => $registration->server_certificates ?? [],
            'localEncryptionKey' => $registration->local_encryption_key,
            'settings' => $registration->settings,
        ]);
    }
}
