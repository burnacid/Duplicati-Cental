<?php

use App\Livewire\Dashboard\BackupStatus;
use App\Models\BackupServer;
use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Models\User;
use Livewire\Livewire;

function connectedRegistrationFor(User $user, BackupServer $server): RemoteControlRegistration
{
    $registration = RemoteControlRegistration::query()->create([
        'machine_id' => 'switch-machine',
        'machine_name' => 'Switch Host',
        'registration_token' => 'reg-' . bin2hex(random_bytes(8)),
        'claim_token' => 'claim-' . bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'claimed_by_user_id' => $user->id,
        'backup_server_id' => $server->id,
        'claimed_at' => now(),
        'expires_at' => now()->addHour(),
    ]);
    $registration->agentState()->create(['last_seen_at' => now(), 'connected_at' => now()]);

    return $registration;
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id]);
    $this->registration = connectedRegistrationFor($this->user, $this->server);
});

test('when disabled, remote control pages and api return 404', function () {
    config(['remote_control.enabled' => false]);
    $this->actingAs($this->user);

    $this->get('/remote-control')->assertNotFound();
    $this->get('/remote-control/' . $this->registration->id)->assertNotFound();
    $this->get('/remote-control/claim/' . $this->registration->claim_token)->assertNotFound();
    $this->postJson('/api/remote-control/register', ['machineId' => 'x'])->assertNotFound();
    $this->postJson('/api/remote-control/register/' . $this->registration->registration_token . '/status')->assertNotFound();
    $this->getJson('/api/remote-control/certificates')->assertNotFound();

    expect(RemoteControlRegistration::count())->toBe(1);
});

test('when disabled, the menu item and dashboard controls are hidden', function () {
    config(['remote_control.enabled' => false]);
    $this->actingAs($this->user);

    $this->get(route('home'))
        ->assertOk()
        ->assertDontSee('Remote Control')
        ->assertDontSee('Online');

    Livewire::test(BackupStatus::class)
        ->call('runNow', $this->server->id, '1')
        ->assertSet('flash', 'This server is not connected for remote control.');

    expect(RemoteControlCommand::count())->toBe(0);
});

test('when enabled, remote control is available', function () {
    config(['remote_control.enabled' => true]);
    $this->actingAs($this->user);

    $this->get('/remote-control')->assertOk();
    $this->get(route('home'))->assertSee('Remote Control')->assertSee('Online');
    $this->postJson('/api/remote-control/register', ['machineId' => 'new-machine'])->assertOk();
});

test('the websocket server does not start when disabled', function () {
    config(['remote_control.enabled' => false]);

    $this->artisan('remote-control:serve')
        ->expectsOutputToContain('Remote control is disabled')
        ->assertSuccessful();
});

test('the remote control page shows the registration url with a copy button', function () {
    config(['remote_control.enabled' => true, 'app.url' => 'https://central.example.test']);
    $this->actingAs($this->user);

    $this->get('/remote-control')
        ->assertOk()
        ->assertSee('Registration URL')
        ->assertSee('value="https://central.example.test/api/remote-control/register"', false)
        ->assertSee('Switch Host')
        ->assertSee('Remove NAS from remote control?');
});
