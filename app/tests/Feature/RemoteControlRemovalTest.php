<?php

use App\Livewire\RemoteControl\Index;
use App\Models\RemoteControlRegistration;
use App\Models\User;
use Livewire\Livewire;

function claimedRegistrationFor(User $user, array $overrides = []): RemoteControlRegistration
{
    return RemoteControlRegistration::query()->create(array_merge([
        'claimed_by_user_id' => $user->id,
        'machine_id' => 'machine-' . bin2hex(random_bytes(4)),
        'machine_name' => 'Claimed Machine',
        'registration_token' => 'reg-' . bin2hex(random_bytes(8)),
        'claim_token' => 'claim-' . bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-' . bin2hex(random_bytes(8)),
    ], $overrides));
}

test('owner can remove a claimed remote controlled server', function () {
    $owner = User::factory()->create();
    $registration = claimedRegistrationFor($owner, [
        'machine_name' => 'Removable Machine',
    ]);

    $this->actingAs($owner);

    Livewire::test(Index::class)
        ->call('removeRegistration', (string) $registration->id);

    $this->assertDatabaseMissing('remote_control_registrations', [
        'id' => (string) $registration->id,
    ]);
});

test('user cannot remove another users claimed remote controlled server', function () {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();

    $ownerRegistration = claimedRegistrationFor($owner);
    $otherRegistration = claimedRegistrationFor($otherUser, [
        'machine_name' => 'Other Users Machine',
    ]);

    $this->actingAs($owner);

    Livewire::test(Index::class)
        ->call('removeRegistration', (string) $otherRegistration->id);

    $this->assertDatabaseHas('remote_control_registrations', [
        'id' => (string) $otherRegistration->id,
    ]);

    $this->assertDatabaseHas('remote_control_registrations', [
        'id' => (string) $ownerRegistration->id,
    ]);
});


test('remote control pages show the backup server name instead of the machine name', function () {
    $owner = User::factory()->create();
    $server = \App\Models\BackupServer::query()->create([
        'name' => 'Office NAS',
        'user_id' => $owner->id,
    ]);
    $registration = claimedRegistrationFor($owner, [
        'machine_name' => 'DESKTOP-4F2K9QX',
        'backup_server_id' => $server->id,
    ]);
    $unlinked = claimedRegistrationFor($owner, ['machine_name' => 'Unlinked Laptop']);

    expect($registration->display_name)->toBe('Office NAS')
        ->and($unlinked->display_name)->toBe('Unlinked Laptop');

    $this->actingAs($owner);

    Livewire::test(Index::class)
        ->assertSee('Office NAS')
        ->assertSee('DESKTOP-4F2K9QX')
        ->assertSee('Unlinked Laptop')
        ->set('search', 'Office')
        ->assertSee('Office NAS')
        ->assertDontSee('Unlinked Laptop');
});
