<?php

use App\Models\BackupServer;
use App\Models\RemoteControlRegistration;
use App\Models\User;

function backupPayloadForMachine(string $machineId): array
{
    return [
        'Data' => [
            'MainOperation' => 'Backup',
            'ParsedResult' => 'Success',
            'Interrupted' => false,
            'Version' => '2.1.0.5',
            'EndTime' => '2026-05-04T10:05:00',
            'BeginTime' => '2026-05-04T10:00:00',
            'Duration' => '00:05:00',
            'MessagesActualLength' => 1,
            'WarningsActualLength' => 0,
            'ErrorsActualLength' => 0,
            'BackendStatistics' => [
                'KnownFileSize' => 1024,
                'KnownFileCount' => 2,
            ],
            'DeletedFiles' => 0,
            'DeletedFolders' => 0,
            'ModifiedFiles' => 0,
            'ExaminedFiles' => 2,
            'OpenedFiles' => 2,
            'AddedFiles' => 0,
            'SizeOfModifiedFiles' => 0,
            'SizeOfAddedFiles' => 0,
            'SizeOfExaminedFiles' => 1024,
            'SizeOfOpenedFiles' => 1024,
            'NotProcessedFiles' => 0,
            'AddedFolders' => 0,
            'TooLargeFiles' => 0,
            'FilesWithError' => 0,
            'ModifiedFolders' => 0,
            'ModifiedSymlinks' => 0,
            'AddedSymlinks' => 0,
            'DeletedSymlinks' => 0,
            'PartialBackup' => false,
            'Dryrun' => false,
        ],
        'Extra' => [
            'OperationName' => 'Backup',
            'machine-id' => $machineId,
            'machine-name' => 'Machine '.$machineId,
            'backup-name' => 'Daily backup',
            'backup-id' => 'backup-1',
        ],
        'LogLines' => ['Backup started'],
    ];
}

test('backup results page shows claimed instance when machine id matches current user claim', function () {
    $user = User::factory()->create();
    $server = BackupServer::query()->create([
        'name' => 'Primary Server',
        'description' => 'Test server',
        'user_id' => $user->id,
    ]);

    $this->postJson("/api/backup-servers/{$server->id}/{$server->api_key}/backup-result", backupPayloadForMachine('machine-match'))
        ->assertCreated();

    RemoteControlRegistration::query()->create([
        'claimed_by_user_id' => $user->id,
        'machine_id' => 'machine-match',
        'machine_name' => 'Claimed Host',
        'registration_token' => 'reg-'.bin2hex(random_bytes(6)),
        'claim_token' => 'claim-'.bin2hex(random_bytes(6)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-token',
        'server_url' => 'wss://central.test/api/remote-control/ws',
        'certificate_url' => 'https://central.test/api/remote-control/certificates',
    ]);

    $this->actingAs($user)
        ->get('/backup-results')
        ->assertOk()
        ->assertSee('Claimed instance: Claimed Host');
});

test('backup results page does not show claimed instance for other user claim', function () {
    $user = User::factory()->create();
    $otherUser = User::factory()->create();

    $server = BackupServer::query()->create([
        'name' => 'Primary Server',
        'description' => 'Test server',
        'user_id' => $user->id,
    ]);

    $this->postJson("/api/backup-servers/{$server->id}/{$server->api_key}/backup-result", backupPayloadForMachine('machine-other'))
        ->assertCreated();

    RemoteControlRegistration::query()->create([
        'claimed_by_user_id' => $otherUser->id,
        'machine_id' => 'machine-other',
        'machine_name' => 'Other User Host',
        'registration_token' => 'reg-'.bin2hex(random_bytes(6)),
        'claim_token' => 'claim-'.bin2hex(random_bytes(6)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-token',
        'server_url' => 'wss://central.test/api/remote-control/ws',
        'certificate_url' => 'https://central.test/api/remote-control/certificates',
    ]);

    $this->actingAs($user)
        ->get('/backup-results')
        ->assertOk()
        ->assertDontSee('Claimed instance: Other User Host');
});
