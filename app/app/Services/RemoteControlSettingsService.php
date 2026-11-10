<?php

namespace App\Services;

use App\Models\RemoteControlRegistration;

class RemoteControlSettingsService
{
    /**
     * Build a normalized settings payload for Duplicati remote control updates.
     * Values are stored as strings to match the upstream dictionary contract.
     */
    public function build(RemoteControlRegistration $registration, string $dashboardUrl, ?string $reportingUrl = null): array
    {
        $settings = is_array($registration->settings) ? $registration->settings : [];

        $settings['dashboardurl'] = (string) ($settings['dashboardurl'] ?? $dashboardUrl);

        if ($reportingUrl !== null && $reportingUrl !== '') {
            $settings['reportingurl'] = $reportingUrl;
        }

        $currentVersion = (int) ($settings['settingsversion'] ?? 0);
        $settings['settingsversion'] = (string) max(1, $currentVersion + 1);

        return $settings;
    }
}
