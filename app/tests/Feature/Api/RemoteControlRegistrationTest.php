<?php

use App\Models\RemoteControlRegistration;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;

function validRemoteRegistrationPayload(array $overrides = []): array
{
    $payload = [
        'instanceId' => 'instance-123',
        'machineId' => 'machine-123',
        'machineName' => 'Test Machine',
        'installId' => 'install-123',
        'version' => '2.1.0.5',
        'operatingSystem' => 'linux',
    ];

    return array_replace_recursive($payload, $overrides);
}

test('it creates a pending registration and returns claim and status links', function () {
    $response = $this->postJson('/api/remote-control/register', validRemoteRegistrationPayload());

    $response->assertOk()
        ->assertJsonStructure([
            'claimLink',
            'statusLink',
            'retrySeconds',
            'maxRetries',
            'maxLifetimeSeconds',
        ]);

    $registration = RemoteControlRegistration::query()->first();

    expect($registration)
        ->not->toBeNull()
        ->status->toBe(RemoteControlRegistration::STATUS_PENDING)
        ->machine_id->toBe('machine-123');
});

test('status returns waiting for claim before registration is claimed', function () {
    $this->postJson('/api/remote-control/register', validRemoteRegistrationPayload());

    $registration = RemoteControlRegistration::query()->firstOrFail();

    $statusResponse = $this->postJson("/api/remote-control/register/{$registration->registration_token}/status", validRemoteRegistrationPayload());

    $statusResponse->assertOk();
    $statusResponse->assertJsonPath('success', false);
    $statusResponse->assertJsonPath('statusMessage', 'Waiting for claim');
});

test('after a web claim, status returns the claimed payload', function () {
    config()->set('app.url', 'https://central.example.test');
    config()->set('remote_control.bridge_url', 'wss://bridge.example.test:8090');

    // The instance registers on the public address; its URLs are based on that address.
    $this->postJson('https://central.example.test/api/remote-control/register', validRemoteRegistrationPayload());
    $registration = RemoteControlRegistration::query()->firstOrFail();

    $this->actingAs(User::factory()->create())
        ->withoutMiddleware(ValidateCsrfToken::class)
        ->post("/remote-control/claim/{$registration->claim_token}")
        ->assertRedirect(route('remote-control.index', absolute: false));

    $this->postJson("/api/remote-control/register/{$registration->registration_token}/status", validRemoteRegistrationPayload())
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('statusMessage', 'Machine claimed')
        ->assertJsonPath('serverUrl', 'wss://bridge.example.test:8090/api/remote-control/ws')
        ->assertJsonPath('certificateUrl', 'https://central.example.test/api/remote-control/certificates');
});

test('the unauthenticated api claim endpoint no longer exists', function () {
    $this->postJson('/api/remote-control/register', validRemoteRegistrationPayload());
    $registration = RemoteControlRegistration::query()->firstOrFail();

    $this->postJson("/api/remote-control/register/{$registration->claim_token}/claim", [
        'serverUrl' => 'wss://attacker.example/relay',
    ])->assertNotFound();

    expect($registration->fresh()->status)->toBe(RemoteControlRegistration::STATUS_PENDING);
});
