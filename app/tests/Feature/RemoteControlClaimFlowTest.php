<?php

use App\Models\BackupServer;
use App\Models\RemoteControlRegistration;
use App\Models\User;

function createPendingRemoteRegistration(array $overrides = []): RemoteControlRegistration
{
    return RemoteControlRegistration::query()->create(array_merge([
        'instance_id' => 'instance-claim-test',
        'machine_id' => 'machine-claim-test',
        'machine_name' => 'Claim Test Machine',
        'registration_token' => 'reg-token-' . bin2hex(random_bytes(8)),
        'claim_token' => 'claim-token-' . bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_PENDING,
        'expires_at' => now()->addHour(),
    ], $overrides));
}

test('guest users are redirected to login for claim links', function () {
    $registration = createPendingRemoteRegistration();

    $response = $this->get("/remote-control/claim/{$registration->claim_token}");

    $response->assertRedirect('/login');
});

test('authenticated user can claim a pending registration via web flow', function () {
    config()->set('app.url', 'https://central.example.test');
    config()->set('remote_control.bridge_url', 'wss://bridge.example.test:8090');

    $this->withoutMiddleware();

    $user = User::factory()->create();
    $registration = createPendingRemoteRegistration();

    $response = $this->actingAs($user)
        ->post("/remote-control/claim/{$registration->claim_token}");

    $response->assertRedirect(route('remote-control.index', absolute: false));

    $registration->refresh();

    expect($registration->status)->toBe(RemoteControlRegistration::STATUS_CLAIMED)
        ->and($registration->claimed_by_user_id)->toBe((string) $user->id)
        ->and($registration->backup_server_id)->not->toBeNull()
        ->and($registration->jwt)->not->toBeNull()
        ->and($registration->server_url)->toBe('wss://bridge.example.test:8090/api/remote-control/ws')
        ->and($registration->certificate_url)->toBe('https://central.example.test/api/remote-control/certificates')
        ->and(is_array($registration->server_certificates))->toBeTrue()
        ->and(count($registration->server_certificates))->toBeGreaterThan(0);

    $backupServer = BackupServer::query()->findOrFail($registration->backup_server_id);
    // No registration address stored: falls back to APP_URL (not the browser's address).
    expect($registration->settings['reportingurl'] ?? null)
        ->toBe("https://central.example.test/api/backup-servers/{$backupServer->id}/{$backupServer->api_key}/backup-result")
        ->and((int) ($registration->settings['settingsversion'] ?? 0))->toBeGreaterThan(0);
});

test('claimed instance appears in remote control index for owning user only', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $owned = createPendingRemoteRegistration([
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'claimed_by_user_id' => $owner->id,
        'claimed_at' => now(),
        'jwt' => 'jwt-owner',
        'server_url' => 'wss://central.example.test/api/remote-control/ws',
        'certificate_url' => 'https://central.example.test/api/remote-control/certificates',
    ]);

    createPendingRemoteRegistration([
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'claimed_by_user_id' => $otherUser->id,
        'claimed_at' => now(),
        'jwt' => 'jwt-other',
        'server_url' => 'wss://central.example.test/api/remote-control/ws',
        'certificate_url' => 'https://central.example.test/api/remote-control/certificates',
        'machine_name' => 'Other machine',
    ]);

    $response = $this->actingAs($owner)->get('/remote-control');

    $response->assertOk();
    $response->assertSee($owned->machine_name);
    $response->assertDontSee('Other machine');
});

