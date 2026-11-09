<?php

use App\Models\RemoteControlAgentState;
use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlAgentSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function claimedRegistrationForAgentSync(array $overrides = []): RemoteControlRegistration
{
    return RemoteControlRegistration::query()->create(array_merge([
        'instance_id' => 'agent-sync-instance',
        'machine_id' => 'agent-sync-machine',
        'machine_name' => 'Agent Sync Machine',
        'registration_token' => 'reg-' . bin2hex(random_bytes(8)),
        'claim_token' => 'claim-' . bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-' . bin2hex(random_bytes(8)),
        'settings' => [
            'settingsversion' => '2',
            'reportingurl' => 'https://central.example.test/api/backup-servers/test/key/backup-result',
        ],
    ], $overrides));
}

test('touchAndBuildControl returns updatesettings control when settings are not yet applied', function () {
    $registration = claimedRegistrationForAgentSync();
    $service = new RemoteControlAgentSyncService();

    $result = $service->touchAndBuildControl($registration, ['client-version' => '2.1.0.5']);

    expect($result['control']['type'])->toBe('control')
        ->and($result['control']['command'])->toBe('updatesettings')
        ->and($result['control']['parameters']['settingsversion'])->toBe('2')
        ->and($result['control']['parameters']['reportingurl'])->toBe('https://central.example.test/api/backup-servers/test/key/backup-result')
        ->and($result['state']->metadata)->toBe(['client-version' => '2.1.0.5']);
});

test('acknowledgeSettings suppresses repeated updatesettings payload', function () {
    $registration = claimedRegistrationForAgentSync([
        'settings' => [
            'settingsversion' => '3',
            'reportingurl' => 'https://central.example.test/api/backup-servers/test/key/backup-result',
        ],
    ]);
    $service = new RemoteControlAgentSyncService();

    $state = $service->acknowledgeSettings($registration, 3);
    expect($state->last_applied_settings_version)->toBe(3);

    $result = $service->touchAndBuildControl($registration);
    expect($result['control'])->toBeNull();
});

test('findClaimedRegistrationByJwt returns null for an unknown token', function () {
    $service = new RemoteControlAgentSyncService();

    expect($service->findClaimedRegistrationByJwt('invalid-token'))->toBeNull();
});

test('touchLastSeen updates last_seen_at without touching settings acknowledgement', function () {
    $registration = claimedRegistrationForAgentSync();
    $service = new RemoteControlAgentSyncService();

    $service->acknowledgeSettings($registration, 2);
    $before = RemoteControlAgentState::query()->where('remote_control_registration_id', $registration->id)->first();

    $this->travel(5)->minutes();
    $state = $service->touchLastSeen($registration);

    expect($state->last_applied_settings_version)->toBe(2)
        ->and($state->last_seen_at->gt($before->last_seen_at))->toBeTrue();
});

test('markDisconnected sets disconnected_at on an existing state', function () {
    $registration = claimedRegistrationForAgentSync();
    $service = new RemoteControlAgentSyncService();

    $service->touchAndBuildControl($registration);
    $service->markDisconnected($registration);

    $state = RemoteControlAgentState::query()->where('remote_control_registration_id', $registration->id)->first();

    expect($state->disconnected_at)->not->toBeNull();
});

test('markDisconnected is a no-op when no agent state exists yet', function () {
    $registration = claimedRegistrationForAgentSync();
    $service = new RemoteControlAgentSyncService();

    $service->markDisconnected($registration);

    expect(RemoteControlAgentState::query()->where('remote_control_registration_id', $registration->id)->exists())->toBeFalse();
});
