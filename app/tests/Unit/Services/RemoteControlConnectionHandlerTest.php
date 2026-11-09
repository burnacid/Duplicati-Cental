<?php

use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlConnectionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function claimedRegistrationForConnectionHandler(array $overrides = []): RemoteControlRegistration
{
    return RemoteControlRegistration::query()->create(array_merge([
        'instance_id' => 'connection-handler-instance',
        'machine_id' => 'connection-handler-machine',
        'machine_name' => 'Connection Handler Machine',
        'registration_token' => 'reg-' . bin2hex(random_bytes(8)),
        'claim_token' => 'claim-' . bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-' . bin2hex(random_bytes(8)),
    ], $overrides));
}

test('reapStaleCommands times out a pending command with no connection available in time', function () {
    $registration = claimedRegistrationForConnectionHandler();

    $command = RemoteControlCommand::query()->create([
        'remote_control_registration_id' => $registration->id,
        'method' => 'GET',
        'path' => '/api/v1/backups',
        'status' => RemoteControlCommand::STATUS_PENDING,
    ]);
    RemoteControlCommand::query()->whereKey($command->id)->update(['created_at' => now()->subSeconds(31)]);

    app(RemoteControlConnectionHandler::class)->reapStaleCommands();

    $command->refresh();

    expect($command->status)->toBe(RemoteControlCommand::STATUS_TIMEOUT)
        ->and($command->error_message)->not->toBeNull();
});

test('reapStaleCommands times out a sent command that never received a response', function () {
    $registration = claimedRegistrationForConnectionHandler();

    $command = RemoteControlCommand::query()->create([
        'remote_control_registration_id' => $registration->id,
        'method' => 'GET',
        'path' => '/api/v1/progressstate',
        'status' => RemoteControlCommand::STATUS_SENT,
        'message_id' => 'msg-1',
        'sent_at' => now(),
    ]);
    RemoteControlCommand::query()->whereKey($command->id)->update(['sent_at' => now()->subSeconds(31)]);

    app(RemoteControlConnectionHandler::class)->reapStaleCommands();

    $command->refresh();

    expect($command->status)->toBe(RemoteControlCommand::STATUS_TIMEOUT)
        ->and($command->error_message)->not->toBeNull();
});

test('reapStaleCommands leaves fresh pending and sent commands untouched', function () {
    $registration = claimedRegistrationForConnectionHandler();

    $pending = RemoteControlCommand::query()->create([
        'remote_control_registration_id' => $registration->id,
        'method' => 'GET',
        'path' => '/api/v1/backups',
        'status' => RemoteControlCommand::STATUS_PENDING,
    ]);

    $sent = RemoteControlCommand::query()->create([
        'remote_control_registration_id' => $registration->id,
        'method' => 'GET',
        'path' => '/api/v1/progressstate',
        'status' => RemoteControlCommand::STATUS_SENT,
        'message_id' => 'msg-2',
        'sent_at' => now(),
    ]);

    app(RemoteControlConnectionHandler::class)->reapStaleCommands();

    expect($pending->refresh()->status)->toBe(RemoteControlCommand::STATUS_PENDING)
        ->and($sent->refresh()->status)->toBe(RemoteControlCommand::STATUS_SENT);
});
