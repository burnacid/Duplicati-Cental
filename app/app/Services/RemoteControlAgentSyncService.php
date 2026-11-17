<?php

namespace App\Services;

use App\Models\RemoteControlAgentState;
use App\Models\RemoteControlRegistration;

class RemoteControlAgentSyncService
{
    /** Connect metadata in which Duplicati reports its managed report URL (AdditionalReportUrl). */
    public const REPORT_URL_METADATA = 'feature:additional-report-url';

    /**
     * Control message for settings that changed while the instance is connected,
     * unless that version was already sent on this connection.
     *
     * @return array<string, mixed>|null
     */
    public function pendingSettingsControl(RemoteControlRegistration $registration, ?int $alreadySentVersion): ?array
    {
        $settings = is_array($registration->settings) ? $registration->settings : [];
        $targetVersion = (int) ($settings['settingsversion'] ?? 0);
        $applied = (int) ($registration->agentState?->last_applied_settings_version ?? 0);

        if ($targetVersion <= $applied || $targetVersion === $alreadySentVersion) {
            return null;
        }

        return $this->settingsControl($settings);
    }

    /** @return array{type: string, command: string, parameters: array<string, mixed>} */
    private function settingsControl(array $settings): array
    {
        return [
            'type' => 'control',
            'command' => 'updatesettings',
            'parameters' => $settings,
        ];
    }

    public function findClaimedRegistrationByJwt(string $jwt): ?RemoteControlRegistration
    {
        return RemoteControlRegistration::query()
            ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
            ->where('jwt', $jwt)
            ->first();
    }

    /**
     * @return array{state: RemoteControlAgentState, control: array<string, mixed>|null}
     */
    public function touchAndBuildControl(RemoteControlRegistration $registration, ?array $metadata = null): array
    {
        $state = RemoteControlAgentState::query()->firstOrCreate(
            ['remote_control_registration_id' => $registration->id],
            [
                'connected_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        $state->connected_at = $state->connected_at ?? now();
        $state->last_seen_at = now();
        $state->disconnected_at = null;
        if ($metadata !== null) {
            $state->metadata = $metadata;
        }
        $state->save();

        $settings = is_array($registration->settings) ? $registration->settings : [];
        $targetVersion = (int) ($settings['settingsversion'] ?? 0);

        // Self-heal: the instance reports a different report URL than it should have (e.g. reset locally).
        $reported = is_array($metadata) && array_key_exists(self::REPORT_URL_METADATA, $metadata)
            ? (string) $metadata[self::REPORT_URL_METADATA]
            : null;
        $drifted = $reported !== null && filled($settings['reportingurl'] ?? null) && $reported !== $settings['reportingurl'];

        $control = null;
        if ($targetVersion > $state->last_applied_settings_version || $drifted) {
            $control = $this->settingsControl($settings);
        }

        return [
            'state' => $state,
            'control' => $control,
        ];
    }

    public function acknowledgeSettings(RemoteControlRegistration $registration, int $settingsVersion): RemoteControlAgentState
    {
        $state = RemoteControlAgentState::query()->firstOrCreate(
            ['remote_control_registration_id' => $registration->id],
            [
                'connected_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        $state->last_seen_at = now();
        $state->last_applied_settings_version = max($state->last_applied_settings_version, $settingsVersion);
        $state->save();

        return $state;
    }

    public function touchLastSeen(RemoteControlRegistration $registration): RemoteControlAgentState
    {
        $state = RemoteControlAgentState::query()->firstOrCreate(
            ['remote_control_registration_id' => $registration->id],
            [
                'connected_at' => now(),
                'last_seen_at' => now(),
            ]
        );

        $state->last_seen_at = now();
        $state->save();

        return $state;
    }

    public function markDisconnected(RemoteControlRegistration $registration): void
    {
        $state = RemoteControlAgentState::query()
            ->where('remote_control_registration_id', $registration->id)
            ->first();

        if (! $state) {
            return;
        }

        $state->disconnected_at = now();
        $state->save();
    }
}
