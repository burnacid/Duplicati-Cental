<?php

namespace App\Services;

use App\Models\RemoteControlAgentState;
use App\Models\RemoteControlRegistration;

class RemoteControlAgentSyncService
{
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

        $control = null;
        if ($targetVersion > $state->last_applied_settings_version) {
            $control = [
                'type' => 'control',
                'command' => 'updatesettings',
                'parameters' => $settings,
            ];
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

