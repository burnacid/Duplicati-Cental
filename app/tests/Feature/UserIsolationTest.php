<?php

use App\Livewire\BackupResults;
use App\Livewire\Backups\Index as BackupsIndex;
use App\Livewire\BackupServers\Index as BackupServersIndex;
use App\Livewire\NotificationChannels\Index as NotificationChannelsIndex;
use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;

function storeResultFor(BackupServer $server, string $backupName = 'Docs', string $status = 'Success'): BackupResult
{
    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => $backupName, 'backup-id' => 'id-'.$backupName, 'machine-id' => 'm'];
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
        ->toThrow(ModelNotFoundException::class);
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

test('another user\'s job settings cannot be opened or changed', function () {
    $this->actingAs($this->alice);

    expect(fn () => Livewire::test(BackupsIndex::class)->call('openSettings', $this->bobServer->id, 'id-BobSecret'))
        ->toThrow(ModelNotFoundException::class);

    expect(fn () => Livewire::test(BackupsIndex::class)
        ->set('settingsServerId', $this->bobServer->id)
        ->set('settingsBackupId', 'id-BobSecret')
        ->call('snoozeJob'))
        ->toThrow(ModelNotFoundException::class);

    expect(BackupJobSetting::count())->toBe(0);
});

test('another user\'s server cannot be snoozed', function () {
    $this->actingAs($this->alice);

    expect(fn () => Livewire::test(BackupServersIndex::class)->call('snoozeServer', $this->bobServer->id, '1d'))
        ->toThrow(ModelNotFoundException::class);

    expect($this->bobServer->fresh()->muted_until)->toBeNull();
});

test('an API token only sees its owner\'s data', function () {
    $token = $this->alice->createToken('alice', ['read'])->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/results')->assertOk()->assertSee('AliceDocs')->assertDontSee('BobSecret');
    $this->withToken($token)->getJson('/api/v1/jobs')->assertOk()->assertDontSee('Bob NAS');
    $this->withToken($token)->get('/api/v1/metrics')->assertOk()->assertDontSee('Bob NAS');
    $this->withToken($token)->getJson("/api/v1/results/{$this->bobResult->id}")->assertNotFound();
});

test('a status page only shows its owner\'s jobs', function () {
    $this->bobServer->jobSettings()->create(['backup_id' => 'id-BobSecret', 'on_status_page' => true]);
    $this->alice->forceFill(['status_page_token' => str_repeat('a', 48)])->save();

    $this->get('/status/'.str_repeat('a', 48))->assertOk()->assertDontSee('BobSecret');
});
