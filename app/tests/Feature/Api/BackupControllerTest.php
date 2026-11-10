<?php

use App\Jobs\SendBackupResultNotification;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

function validBackupPayload(array $overrides = []): array
{
    $payload = [
        'Data' => [
            'MainOperation' => 'Backup',
            'ParsedResult' => 'Success',
            'Interrupted' => false,
            'Version' => '2.1.0.5',
            'EndTime' => '2026-04-04T10:05:00',
            'BeginTime' => '2026-04-04T10:00:00',
            'Duration' => '00:05:00',
            'MessagesActualLength' => 2,
            'WarningsActualLength' => 0,
            'ErrorsActualLength' => 0,
            'BackendStatistics' => [
                'KnownFileSize' => 1024,
                'KnownFileCount' => 2,
            ],
            'DeletedFiles' => 0,
            'DeletedFolders' => 0,
            'ModifiedFiles' => 1,
            'ExaminedFiles' => 2,
            'OpenedFiles' => 2,
            'AddedFiles' => 1,
            'SizeOfModifiedFiles' => 64,
            'SizeOfAddedFiles' => 512,
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
            'machine-id' => 'machine-123',
            'machine-name' => 'Test Host',
            'backup-name' => 'Home NAS',
            'backup-id' => 'backup-abc',
        ],
        'LogLines' => [
            ['Message' => 'Backup started', 'Timestamp' => '2026-04-04T10:00:00'],
        ],
    ];

    return array_replace_recursive($payload, $overrides);
}

test('it stores a valid backup result payload', function () {
    $user = User::factory()->create();
    $server = BackupServer::query()->create([
        'name' => 'Primary Server',
        'description' => 'Test server',
        'user_id' => $user->id,
    ]);

    $response = $this->postJson(
        "/api/backup-servers/{$server->id}/{$server->api_key}/backup-result",
        validBackupPayload()
    );

    $response->assertCreated();
    $response->assertJsonPath('message', 'Backup result received successfully');

    expect(BackupResult::query()->count())->toBe(1);

    $result = BackupResult::query()->first();
    expect($result)
        ->backup_server_id->toBe((string) $server->id)
        ->backup_name->toBe('Home NAS')
        ->backup_id->toBe('backup-abc')
        ->MainOperation->toBe('Backup')
        ->ParsedResult->toBe('Success');
});

test('it returns 404 when server credentials are invalid', function () {
    $response = $this->postJson(
        '/api/backup-servers/not-a-real-id/not-a-real-key/backup-result',
        validBackupPayload()
    );

    $response->assertNotFound();
});

test('it rejects payloads that fail validation', function () {
    $user = User::factory()->create();
    $server = BackupServer::query()->create([
        'name' => 'Primary Server',
        'description' => 'Test server',
        'user_id' => $user->id,
    ]);

    $response = $this->postJson(
        "/api/backup-servers/{$server->id}/{$server->api_key}/backup-result",
        validBackupPayload(['Extra' => null])
    );

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['Extra']);
});

test('it returns early for unsupported list operation payloads', function () {
    $user = User::factory()->create();
    $server = BackupServer::query()->create([
        'name' => 'Primary Server',
        'description' => 'Test server',
        'user_id' => $user->id,
    ]);

    $response = $this->postJson(
        "/api/backup-servers/{$server->id}/{$server->api_key}/backup-result",
        validBackupPayload(['Data' => ['MainOperation' => 'List']])
    );

    $response->assertOk();
    $response->assertJsonPath('message', 'List operations are not stored');
    expect(BackupResult::query()->count())->toBe(0);
});

function makeServerFor(?User $user = null): BackupServer
{
    return BackupServer::query()->create([
        'name' => 'Primary Server',
        'user_id' => ($user ?? User::factory()->create())->id,
    ]);
}

test('it returns 422 instead of crashing on an empty body', function () {
    $server = makeServerFor();

    $this->postJson("/api/backup-servers/{$server->id}/{$server->api_key}/backup-result", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['Data', 'Extra']);
});

test('it accepts other Duplicati operations such as Compact and Repair', function (string $operation) {
    Queue::fake();
    $server = makeServerFor();

    $payload = validBackupPayload([
        'Data' => ['MainOperation' => $operation],
        'Extra' => ['OperationName' => $operation],
    ]);

    $this->postJson("/api/backup-servers/{$server->id}/{$server->api_key}/backup-result", $payload)
        ->assertCreated();

    expect(BackupResult::query()->first()->MainOperation)->toBe($operation);
})->with(['Compact', 'Repair', 'Delete', 'Vacuum']);

test('it stores times as UTC and converts offsets', function () {
    Queue::fake();
    $server = makeServerFor();

    $this->postJson("/api/backup-servers/{$server->id}/{$server->api_key}/backup-result", validBackupPayload([
        'Data' => ['EndTime' => '2026-04-04T12:05:00+02:00'],
    ]))->assertCreated();

    expect(DB::table('backup_results')->value('EndTime'))->toBe('2026-04-04 10:05:00');
});

test('it queues a notification and does not echo the stored model', function () {
    Queue::fake();
    $server = makeServerFor();

    $response = $this->postJson("/api/backup-servers/{$server->id}/{$server->api_key}/backup-result", validBackupPayload());

    $response->assertCreated()->assertJsonMissingPath('data');
    Queue::assertPushed(SendBackupResultNotification::class);
});
