<?php

use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Models\User;
use App\Services\RemoteControlCommandService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

function claimedRegistrationForCommandService(array $overrides = []): RemoteControlRegistration
{
    return RemoteControlRegistration::query()->create(array_merge([
        'instance_id' => 'command-service-instance',
        'machine_id' => 'command-service-machine',
        'machine_name' => 'Command Service Machine',
        'registration_token' => 'reg-'.bin2hex(random_bytes(8)),
        'claim_token' => 'claim-'.bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-'.bin2hex(random_bytes(8)),
    ], $overrides));
}

test('dispatch without a body leaves request_body and request_headers null', function () {
    $registration = claimedRegistrationForCommandService();
    $service = new RemoteControlCommandService;

    $command = $service->dispatch($registration, 'GET', '/api/v1/backups');

    expect($command->status)->toBe(RemoteControlCommand::STATUS_PENDING)
        ->and($command->method)->toBe('GET')
        ->and($command->path)->toBe('/api/v1/backups')
        ->and($command->request_body)->toBeNull()
        ->and($command->request_headers)->toBeNull()
        ->and($command->remote_control_registration_id)->toBe($registration->id);
});

test('dispatch with a json body encodes it and sets the content-type header', function () {
    $registration = claimedRegistrationForCommandService();
    $service = new RemoteControlCommandService;

    $command = $service->dispatch($registration, 'POST', '/api/v1/backup/abc/run', ['skipQueue' => false]);

    expect($command->request_body)->toBe(json_encode(['skipQueue' => false]))
        ->and($command->request_headers)->toBe(['Content-Type' => 'application/json']);
});

test('dispatch persists requested_by_user_id when given, null otherwise', function () {
    $registration = claimedRegistrationForCommandService();
    $user = User::factory()->create();
    $service = new RemoteControlCommandService;

    $withUser = $service->dispatch($registration, 'GET', '/api/v1/backups', requestedByUserId: $user->id);
    $withoutUser = $service->dispatch($registration, 'GET', '/api/v1/backups');

    expect($withUser->requested_by_user_id)->toBe($user->id)
        ->and($withoutUser->requested_by_user_id)->toBeNull();
});
