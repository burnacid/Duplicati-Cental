<?php

use App\Livewire\BackupResults;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\BackupServers\Index as BackupServersIndex;
use App\Livewire\NotificationChannels\Index as NotificationChannelsIndex;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use Livewire\Livewire;

function storeResultFor(BackupServer $server, string $backupName = 'Docs', string $status = 'Success'): BackupResult
{
    $result = new BackupResult();
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => $backupName, 'backup-id' => 'id-' . $backupName, 'machine-id' => 'm'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $status;
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now()->subHour();
    $result->BeginTime = now()->subHours(2);
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 0;
    $result->BackendStatistics = ['KnownFileSize' => 1024];
    $result->save();

    return $result;
}

beforeEach(function () {
    $this->alice = User::factory()->create();
    $this->bob = User::factory()->create();
    $this->aliceServer = BackupServer::query()->create(['name' => 'Alice NAS', 'user_id' => $this->alice->id]);
    $this->bobServer = BackupServer::query()->create(['name' => 'Bob NAS', 'user_id' => $this->bob->id]);
    $this->bobResult = storeResultFor($this->bobServer, 'BobSecret');
    storeResultFor($this->aliceServer, 'AliceDocs');
});

test('server list only shows own servers and keys', function () {
    $this->actingAs($this->alice)
        ->get(route('backup-servers.index'))
        ->assertOk()
        ->assertSee('Alice NAS')
        ->assertDontSee('Bob NAS')
        ->assertDontSee($this->bobServer->api_key);
});

test('editing another user\'s server is forbidden', function () {
    $this->actingAs($this->alice)
        ->get(route('backup-servers.edit', $this->bobServer))
        ->assertForbidden();
});

test('deleting another user\'s server does nothing', function () {
    $this->actingAs($this->alice);

    Livewire::test(BackupServersIndex::class)
        ->set('deleteId', $this->bobServer->id)
        ->call('deleteServer');

    expect(BackupServer::find($this->bobServer->id))->not->toBeNull();
});

test('backup detail of another user is not found', function () {
    $this->actingAs($this->alice)
        ->get(route('backup-detail', $this->bobResult->id))
        ->assertNotFound();
});

test('results, backups and dashboard only show own data', function () {
    $this->actingAs($this->alice);

    Livewire::test(BackupResults::class)->assertSee('AliceDocs')->assertDontSee('BobSecret');
    Livewire::test(BackupsIndex::class)->assertSee('AliceDocs')->assertDontSee('BobSecret');

    $this->get(route('home'))->assertOk()->assertSee('AliceDocs')->assertDontSee('BobSecret');
});

test('deleting another user\'s backup history does nothing', function () {
    $this->actingAs($this->alice);

    Livewire::test(BackupsIndex::class)
        ->call('confirmBackupDeletion', $this->bobServer->id, 'id-BobSecret')
        ->call('deleteBackupHistory');

    expect(BackupResult::find($this->bobResult->id))->not->toBeNull();
});

test('notification channels of another user cannot be edited', function () {
    $channel = $this->bob->notificationChannels()->create([
        'name' => 'Bob mail', 'type' => 'email', 'config' => ['to' => 'bob@example.com'],
    ]);

    $this->actingAs($this->alice);

    expect(fn () => Livewire::test(NotificationChannelsIndex::class)->call('edit', $channel->id))
        ->toThrow(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('registration closes once an account exists', function () {
    config(['app.registration_enabled' => false]);

    auth()->logout();
    $this->get('/register')->assertForbidden();
});

test('registration can be re-enabled', function () {
    config(['app.registration_enabled' => true]);

    $this->get('/register')->assertOk();
});

test('owner can delete a backup job history', function () {
    $this->actingAs($this->alice);

    Livewire::test(BackupsIndex::class)
        ->call('confirmBackupDeletion', $this->aliceServer->id, 'id-AliceDocs')
        ->assertSet('confirmingBackupDeletion', true)
        ->call('deleteBackupHistory')
        ->assertSet('confirmingBackupDeletion', false)
        ->assertDontSee('AliceDocs');

    expect(BackupResult::where('backup_id', 'id-AliceDocs')->count())->toBe(0);
});
