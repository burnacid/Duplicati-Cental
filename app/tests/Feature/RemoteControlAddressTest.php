<?php

use App\Models\RemoteControlRegistration;
use App\Models\User;
use Illuminate\Http\Middleware\TrustProxies;

const PUBLIC_URL = 'https://central.lenders-it.nl';
const LAN_URL = 'http://10.2.21.252:8181';

beforeEach(function () {
    config(['app.url' => PUBLIC_URL, 'remote_control.bridge_url' => null]);
    $this->user = User::factory()->create();
});

afterEach(function () {
    TrustProxies::flushState();
});

function registerFrom(string $url, array $headers = []): RemoteControlRegistration
{
    test()->withHeaders($headers)
        ->postJson($url . '/api/remote-control/register', ['machineId' => 'm-' . md5($url), 'machineName' => 'Host'])
        ->assertOk();

    return RemoteControlRegistration::query()->where('machine_id', 'm-' . md5($url))->firstOrFail();
}

function claimFrom(string $browserUrl, RemoteControlRegistration $registration): RemoteControlRegistration
{
    test()->actingAs(test()->user)
        ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
        ->post($browserUrl . '/remote-control/claim/' . $registration->claim_token)
        ->assertRedirect();

    return $registration->fresh();
}

test('an instance registered on the LAN address gets LAN URLs, even when claimed via the public address', function () {
    $registration = registerFrom(LAN_URL);
    expect($registration->registration_base_url)->toBe(LAN_URL);

    $claimed = claimFrom(PUBLIC_URL, $registration);

    expect($claimed->server_url)->toBe('ws://10.2.21.252:8181/api/remote-control/ws')
        ->and($claimed->certificate_url)->toBe(LAN_URL . '/api/remote-control/certificates')
        ->and($claimed->settings['reportingurl'])->toStartWith(LAN_URL . '/api/backup-servers/')
        ->and($claimed->settings['dashboardurl'])->toBe(LAN_URL);
});

test('an instance registered through the reverse proxy gets public wss URLs, even when claimed from the LAN', function () {
    TrustProxies::at('*');

    $registration = registerFrom('http://central.lenders-it.nl', [
        'X-Forwarded-Proto' => 'https',
        'X-Forwarded-Host' => 'central.lenders-it.nl',
        'X-Forwarded-Port' => '443',
    ]);
    expect($registration->registration_base_url)->toBe(PUBLIC_URL);

    $claimed = claimFrom(LAN_URL, $registration);

    expect($claimed->server_url)->toBe('wss://central.lenders-it.nl/api/remote-control/ws')
        ->and($claimed->certificate_url)->toBe(PUBLIC_URL . '/api/remote-control/certificates')
        ->and($claimed->settings['reportingurl'])->toStartWith(PUBLIC_URL . '/api/backup-servers/');
});

test('the claim link points to the address the instance registered with', function () {
    $response = test()->postJson(LAN_URL . '/api/remote-control/register', ['machineId' => 'lan-1'])->assertOk();

    expect($response->json('claimLink'))->toStartWith(LAN_URL . '/remote-control/claim/');
});

test('REMOTE_CONTROL_BRIDGE_URL overrides the websocket address for every instance', function () {
    config(['remote_control.bridge_url' => 'wss://ws.example.com']);

    $claimed = claimFrom(LAN_URL, registerFrom(LAN_URL));

    expect($claimed->server_url)->toBe('wss://ws.example.com/api/remote-control/ws')
        ->and($claimed->settings['reportingurl'])->toStartWith(LAN_URL);
});

test('registrations from before this change fall back to APP_URL', function () {
    $registration = registerFrom(LAN_URL);
    $registration->update(['registration_base_url' => null]);

    $claimed = claimFrom(LAN_URL, $registration);

    expect($claimed->server_url)->toBe('wss://central.lenders-it.nl/api/remote-control/ws');
});

test('the remote control page shows the public and the current registration URL', function () {
    $this->actingAs($this->user)
        ->get(LAN_URL . '/remote-control')
        ->assertOk()
        ->assertSee('value="https://central.lenders-it.nl/api/remote-control/register"', false)
        ->assertSee('value="http://10.2.21.252:8181/api/remote-control/register"', false)
        ->assertSee('Each instance keeps using the address it registered with');

    $this->actingAs($this->user)
        ->get(PUBLIC_URL . '/remote-control')
        ->assertOk()
        ->assertDontSee('10.2.21.252');
});
