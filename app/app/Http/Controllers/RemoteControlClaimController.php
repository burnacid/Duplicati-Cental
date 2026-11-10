<?php

namespace App\Http\Controllers;

use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlCertificateService;
use App\Services\RemoteControlProvisioningService;
use App\Services\RemoteControlSettingsService;
use App\Services\RemoteControlUrlService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class RemoteControlClaimController extends Controller
{
    public function show(string $claimToken): View
    {
        $registration = RemoteControlRegistration::query()
            ->where('claim_token', $claimToken)
            ->firstOrFail();

        return view('remote-control.claim', [
            'registration' => $registration,
            'isExpired' => $registration->expires_at?->isPast() ?? false,
        ]);
    }

    public function store(
        Request $request,
        string $claimToken,
        RemoteControlCertificateService $certificateService,
        RemoteControlProvisioningService $provisioningService,
        RemoteControlSettingsService $settingsService,
        RemoteControlUrlService $urlService
    ): RedirectResponse {
        $registration = RemoteControlRegistration::query()
            ->where('claim_token', $claimToken)
            ->firstOrFail();

        if (($registration->expires_at?->isPast() ?? false) && $registration->status !== RemoteControlRegistration::STATUS_CLAIMED) {
            return redirect()->route('remote-control.claim.show', ['claimToken' => $claimToken])
                ->with('error', 'This registration link has expired. Please register the instance again.');
        }

        if ($registration->status === RemoteControlRegistration::STATUS_CLAIMED) {
            if ($registration->claimed_by_user_id === $request->user()->id) {
                $backupServer = $provisioningService->ensureBackupServer($registration, $request->user());
                $settings = $settingsService->build(
                    $registration,
                    $urlService->baseUrlFor($registration),
                    $urlService->reportingUrlFor($registration, $backupServer)
                );
                $registration->update(['settings' => $settings]);

                return redirect()->route('remote-control.index')
                    ->with('status', 'This instance is already claimed to your account.');
            }

            return redirect()->route('remote-control.claim.show', ['claimToken' => $claimToken])
                ->with('error', 'This instance has already been claimed by another account.');
        }

        // All addresses the instance gets are based on how *it* reached us when registering,
        // not on how the user opened this claim page.
        $backupServer = $provisioningService->ensureBackupServer($registration, $request->user());
        $settings = $settingsService->build(
            $registration,
            $urlService->baseUrlFor($registration),
            $urlService->reportingUrlFor($registration, $backupServer)
        );

        $registration->update([
            'status' => RemoteControlRegistration::STATUS_CLAIMED,
            'claimed_at' => now(),
            'claimed_by_user_id' => $request->user()->id,
            'backup_server_id' => $backupServer->id,
            'jwt' => $registration->jwt ?: bin2hex(random_bytes(48)),
            'server_url' => $registration->server_url ?: $urlService->websocketUrlFor($registration),
            'certificate_url' => $registration->certificate_url ?: $urlService->certificatesUrlFor($registration),
            'server_certificates' => (is_array($registration->server_certificates) && count($registration->server_certificates) > 0)
                ? $registration->server_certificates
                : $certificateService->listCertificates(),
            'settings' => $settings,
        ]);

        return redirect()->route('remote-control.index')
            ->with('status', 'Instance claimed successfully.');
    }
}
