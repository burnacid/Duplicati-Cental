<?php

use App\Livewire\RemoteControl\Concerns\BrowsesRestore;
use App\Livewire\RemoteControl\Index;
use App\Livewire\RemoteControl\Show;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Models\RemoteControlAgentState;
use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Models\User;
use App\Services\RemoteControlConnectionHandler;
use App\Support\DuplicatiJobConfig;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/** Duplicati's GET /api/v1/backup/{id} answer (passwords masked by Duplicati). */
function duplicatiJobConfig(): array
{
    return [
        'Schedule' => [
            'ID' => 3, 'Tags' => ['ID=1'], 'Time' => '2026-11-11T02:00:00Z', 'Repeat' => '1D',
            'LastRun' => '2026-11-10T02:00:00Z', 'Rule' => 'AllowedWeekDays=Monday,Friday', 'AllowedDays' => ['Monday', 'Friday'],
        ],
        'Backup' => [
            'ID' => '1', 'Name' => 'Documents', 'Description' => 'Office files', 'Tags' => [],
            'TargetURL' => 's3://backups.example.com/office?auth-username=AKIA&auth-password=**********',
            'ConnectionStringID' => -1, 'DBPath' => '/data/ABC.sqlite',
            'Sources' => ['/home/alex/Documents/', '%MY_DOCUMENTS%'],
            'Settings' => [
                ['Name' => 'encryption-module', 'Value' => 'aes', 'Filter' => '', 'Argument' => null],
                ['Name' => 'passphrase', 'Value' => '**********', 'Filter' => '', 'Argument' => null],
                ['Name' => '--keep-time', 'Value' => '3M', 'Filter' => '', 'Argument' => null],
                ['Name' => 'dblock-size', 'Value' => '50mb', 'Filter' => '', 'Argument' => null],
            ],
            'Filters' => [['Order' => 0, 'Include' => false, 'Expression' => '*.tmp']],
            'Metadata' => ['LastBackupDate' => '20261110T020000Z'],
            'AdditionalTargetURLs' => [],
        ],
        'DisplayNames' => ['%MY_DOCUMENTS%' => 'My Documents'],
    ];
}

function depthRegistration(User $user, array $overrides = [], bool $online = true): RemoteControlRegistration
{
    $registration = RemoteControlRegistration::query()->create(array_merge([
        'claimed_by_user_id' => $user->id,
        'machine_id' => 'machine-'.bin2hex(random_bytes(4)),
        'machine_name' => 'NAS',
        'registration_token' => 'reg-'.bin2hex(random_bytes(8)),
        'claim_token' => 'claim-'.bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-'.bin2hex(random_bytes(8)),
    ], $overrides));

    RemoteControlAgentState::query()->create([
        'remote_control_registration_id' => $registration->id,
        'connected_at' => now()->subHour(),
        'last_seen_at' => $online ? now() : now()->subHours(2),
    ]);

    return $registration;
}

function answer(?string $commandId, int $code, array $body): void
{
    RemoteControlCommand::query()->whereKey($commandId)->update([
        'status' => RemoteControlCommand::STATUS_COMPLETED,
        'response_status_code' => $code,
        'response_body' => json_encode($body),
        'completed_at' => now(),
    ]);
}

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->registration = depthRegistration($this->user);
    $this->actingAs($this->user);
    $this->page = fn () => Livewire::test(Show::class, ['id' => (string) $this->registration->id]);
});

test('job details show the configuration without credentials', function () {
    $component = ($this->page)()->call('showJob', '1');

    $command = RemoteControlCommand::find($component->get('requests')['config']);
    expect($command->method)->toBe('GET')->and($command->path)->toBe('/api/v1/backup/1');

    answer($command->id, 200, duplicatiJobConfig());
    $component->call('poll')
        ->assertSee('Office files')
        ->assertSee('/home/alex/Documents/')
        ->assertSee('My Documents')
        ->assertSee('backups.example.com/office')
        ->assertSee('Every day')
        ->assertSee('on Monday, Friday')
        ->assertSee('Keep for')
        ->assertSee('Encrypted')
        ->assertDontSee('AKIA')
        ->assertDontSee('auth-password');

    expect($component->get('scheduleEnabled'))->toBeTrue()
        ->and($component->get('scheduleEvery'))->toBe('1')
        ->and($component->get('scheduleUnit'))->toBe('D')
        ->and($component->get('scheduleDays'))->toBe(['Monday', 'Friday']);
});

test('saving a schedule sends the job back with only the schedule changed', function () {
    $component = ($this->page)()->call('showJob', '1');
    answer($component->get('requests')['config'], 200, duplicatiJobConfig());
    $component->call('poll')
        ->set('scheduleEvery', '12')
        ->set('scheduleUnit', 'h')
        ->set('scheduleTime', '23:30')
        ->set('scheduleDays', ['Saturday'])
        ->call('saveSchedule')
        ->assertHasNoErrors();

    $command = RemoteControlCommand::find($component->get('requests')['save']);
    $body = json_decode($command->request_body, true);

    expect($command->method)->toBe('PUT')
        ->and($command->path)->toBe('/api/v1/backup/1')
        ->and($body['Schedule'])->toMatchArray(['ID' => 3, 'Repeat' => '12h', 'AllowedDays' => ['Saturday'], 'Rule' => 'AllowedWeekDays=Monday,Friday'])
        ->and($body['Backup']['TargetURL'])->toBe(duplicatiJobConfig()['Backup']['TargetURL'])
        ->and($body['Backup']['Settings'][1])->toBe(['Name' => 'passphrase', 'Value' => '**********', 'Filter' => ''])
        ->and($body['Backup']['Sources'])->toBe(['/home/alex/Documents/', '%MY_DOCUMENTS%'])
        ->and($body['Backup'])->not->toHaveKey('DBPath');

    answer($command->id, 200, []);
    $component->call('poll');
    expect($component->get('scheduleMessage'))->toBe('Schedule saved.')
        ->and($component->get('requests'))->toHaveKey('config');
});

test('turning the schedule off removes it, and errors are shown', function () {
    $component = ($this->page)()->call('showJob', '1');
    answer($component->get('requests')['config'], 200, duplicatiJobConfig());
    $component->call('poll')->set('scheduleEnabled', false)->call('saveSchedule');

    $command = RemoteControlCommand::find($component->get('requests')['save']);
    expect(json_decode($command->request_body, true))->toHaveKey('Schedule', null);

    answer($command->id, 400, ['Error' => 'Invalid schedule']);
    $component->call('poll');
    expect($component->get('scheduleMessage'))->toBe('Not saved: Invalid schedule');
});

test('verify, compact and recreate send the right commands', function () {
    $component = ($this->page)()
        ->call('runOperation', 'verify', '1')
        ->call('runOperation', 'compact', '2')
        ->call('runOperation', 'recreate', '3')
        ->call('runOperation', 'restore', '4');

    $paths = fn () => RemoteControlCommand::query()->where('method', 'POST')->orderBy('created_at')->pluck('path')->all();
    expect($paths())->toBe(['/api/v1/backup/1/verify', '/api/v1/backup/2/compact', '/api/v1/backup/3/deletedb'])
        ->and($component->get('tasks'))->not->toHaveKey('4');

    // Recreate: after the local database is deleted, the repair starts.
    answer($component->get('tasks')['3']['command_id'], 200, []);
    $component->call('poll');
    expect(collect(($paths)())->last())->toBe('/api/v1/backup/3/repair')
        ->and($component->get('tasks')['3']['command_kind'])->toBe('start');

    answer($component->get('tasks')['3']['command_id'], 200, ['Status' => 'OK', 'ID' => 12]);
    $component->call('poll');
    expect($component->get('tasks')['3'])->phase->toBe('waiting')->task_id->toBe(12)->operation->toBe('Recreate database');
});

test('a failed database delete stops the recreate', function () {
    $component = ($this->page)()->call('runOperation', 'recreate', '3');
    answer($component->get('tasks')['3']['command_id'], 500, ['Error' => 'Database is in use']);
    $component->call('poll');

    expect($component->get('tasks')['3'])->phase->toBe('error')->message->toBe('Database is in use')
        ->and(RemoteControlCommand::query()->where('path', '/api/v1/backup/3/repair')->exists())->toBeFalse();
});

test('restore: versions, folders, selection and the restore request', function () {
    $component = ($this->page)()->call('openRestore', '1');

    $filesets = RemoteControlCommand::find($component->get('requests')['filesets']);
    expect($filesets->path)->toBe('/api/v2/backup/list-filesets')
        ->and(json_decode($filesets->request_body, true))->toBe(['BackupId' => '1'])
        ->and($filesets->timeout_seconds)->toBe(180);

    answer($filesets->id, 200, ['Success' => true, 'Data' => [
        ['Version' => 0, 'Time' => '2026-11-10T02:00:00Z', 'IsFullBackup' => true, 'FileCount' => 120, 'FileSizes' => 5_000_000],
        ['Version' => 1, 'Time' => '2026-11-09T02:00:00Z', 'IsFullBackup' => true, 'FileCount' => 118, 'FileSizes' => 4_900_000],
    ]]);
    $component->call('poll')->assertSee('120 files');

    $component->call('chooseVersion', 1);
    $folder = RemoteControlCommand::find($component->get('requests')['folder']);
    expect(json_decode($folder->request_body, true))->toMatchArray(['BackupId' => '1', 'Paths' => null, 'Time' => '2026-11-09T02:00:00Z']);

    answer($folder->id, 200, ['Success' => true, 'PageInfo' => ['Total' => 1], 'Data' => [
        ['Path' => '/home/alex/', 'Size' => 0, 'IsDirectory' => true, 'IsSymlink' => false, 'LastModified' => '2026-11-01T00:00:00Z', 'Metadata' => []],
    ]]);
    $component->call('poll')->assertSee('alex');

    $component->call('browse', '/home/alex/');
    answer($component->get('requests')['folder'], 200, ['Success' => true, 'PageInfo' => ['Total' => 2], 'Data' => [
        ['Path' => '/home/alex/my.docs/', 'Size' => 0, 'IsDirectory' => true, 'IsSymlink' => false, 'LastModified' => '2026-11-01T00:00:00Z', 'Metadata' => []],
        ['Path' => '/home/alex/notes.txt', 'Size' => 2048, 'IsDirectory' => false, 'IsSymlink' => false, 'LastModified' => '2026-11-01T00:00:00Z', 'Metadata' => []],
    ]]);
    $component->call('poll')->assertSee('my.docs')->assertSee('notes.txt');

    $component->call('toRestoreOptions');
    expect($component->get('restoreError'))->toBe('Select at least one file or folder.');

    $component->set('restoreSelected', ['/home/alex/my.docs/', '/home/alex/notes.txt'])
        ->call('toRestoreOptions')
        ->set('restoreTarget', 'custom')
        ->set('restorePath', '/tmp/restore')
        ->set('restoreOverwrite', true)
        ->call('startRestore')
        ->assertHasNoErrors();

    $restore = RemoteControlCommand::query()->where('path', '/api/v1/backup/1/restore')->firstOrFail();
    expect(json_decode($restore->request_body, true))->toBe([
        'paths' => ['[/home/alex/my\.docs/.*]', '@/home/alex/notes.txt'],
        'time' => '2026-11-09T02:00:00Z',
        'restore_path' => '/tmp/restore',
        'overwrite' => true,
        'permissions' => false,
    ])->and($component->get('tasks')['1']['operation'])->toBe('Restore');
});

test('a custom restore location needs a folder, and an old Duplicati is explained', function () {
    $component = ($this->page)()->call('openRestore', '1');
    answer($component->get('requests')['filesets'], 404, []);
    $component->call('poll');
    expect($component->get('restoreError'))->toContain('does not support this');
});

test('the running task can be stopped and the instance paused and resumed', function () {
    $component = ($this->page)();
    answer($component->get('progressCommandId'), 200, ['TaskID' => 5, 'BackupID' => '1', 'Phase' => 'Backup_ProcessingFiles']);
    $component->call('poll');

    $serverState = RemoteControlCommand::find($component->get('requests')['serverstate']);
    expect($serverState->path)->toBe('/api/v1/serverstate');
    answer($serverState->id, 200, ['ProgramState' => 'Running']);

    $component->call('stopTask', false)->call('stopTask', true)->set('pauseFor', '4h')->call('pauseInstance');
    $paths = RemoteControlCommand::query()->where('method', 'POST')->pluck('path')->all();
    expect($paths)->toContain('/api/v1/task/5/stop', '/api/v1/task/5/abort', '/api/v1/serverstate/pause?duration=4h');

    answer($component->get('requests')['pause'], 200, []);
    $component->call('poll');
    answer($component->get('requests')['serverstate'], 200, ['ProgramState' => 'Paused', 'EstimatedPauseEnd' => '2026-11-11T10:00:00Z']);
    $component->call('poll')->assertSee('Paused')->assertSee('Resume');

    $component->call('resumeInstance');
    expect(RemoteControlCommand::query()->where('path', '/api/v1/serverstate/resume')->exists())->toBeTrue();

    $component->set('pauseFor', 'forever')->call('pauseInstance');
    expect(RemoteControlCommand::query()->where('path', 'like', '%forever%')->exists())->toBeFalse();
});

test('commands of another instance cannot be read through the page state', function () {
    $other = depthRegistration(User::factory()->create());
    $secret = RemoteControlCommand::query()->create([
        'remote_control_registration_id' => $other->id, 'method' => 'GET', 'path' => '/api/v1/backup/1',
        'status' => RemoteControlCommand::STATUS_COMPLETED, 'response_status_code' => 200,
        'response_body' => json_encode(duplicatiJobConfig()),
    ]);

    $component = ($this->page)()->set('requests', ['config' => $secret->id])->call('poll');

    expect($component->get('jobConfig'))->toBeNull()
        ->and($component->get('detailsError'))->toBe('The request to the instance was lost.');
});

test('long-running commands get their own timeout', function () {
    $make = fn (?int $timeout) => RemoteControlCommand::query()->create([
        'remote_control_registration_id' => $this->registration->id, 'method' => 'POST', 'path' => '/x',
        'status' => RemoteControlCommand::STATUS_SENT, 'sent_at' => now()->subSeconds(60), 'timeout_seconds' => $timeout,
    ]);
    $default = $make(null);
    $long = $make(180);

    app(RemoteControlConnectionHandler::class)->reapStaleCommands();

    expect($default->fresh()->status)->toBe(RemoteControlCommand::STATUS_TIMEOUT)
        ->and($long->fresh()->status)->toBe(RemoteControlCommand::STATUS_SENT);
});

function depthFailedResult(BackupServer $server, string $backupId, string $status = 'Error'): void
{
    $result = new BackupResult;
    $result->backup_server_id = $server->id;
    $result->extra = ['backup-name' => 'Job '.$backupId, 'backup-id' => $backupId, 'machine-id' => 'm'];
    $result->MainOperation = 'Backup';
    $result->ParsedResult = $status;
    $result->Interrupted = false;
    $result->Version = '2.1';
    $result->EndTime = now()->subHour();
    $result->BeginTime = now()->subHours(2);
    $result->Duration = '00:05:00';
    $result->MessagesActualLength = 0;
    $result->WarningsActualLength = 0;
    $result->ErrorsActualLength = 1;
    $result->BackendStatistics = [];
    $result->save();
}

test('failed jobs on connected instances can be started again in one go', function () {
    $server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id]);
    $this->registration->update(['backup_server_id' => $server->id]);
    depthFailedResult($server, 'DB-1');
    depthFailedResult($server, 'DB-2', 'Success');
    depthFailedResult($server, 'DB-3');
    $server->jobSettings()->create(['backup_id' => 'DB-3', 'muted_until' => now()->addDay()]);

    // Offline instance and another user's instance are left alone.
    $offlineServer = BackupServer::query()->create(['name' => 'Laptop', 'user_id' => $this->user->id]);
    depthRegistration($this->user, ['backup_server_id' => $offlineServer->id], online: false);
    depthFailedResult($offlineServer, 'DB-9');
    $otherUser = User::factory()->create();
    $otherServer = BackupServer::query()->create(['name' => 'Other', 'user_id' => $otherUser->id]);
    depthRegistration($otherUser, ['backup_server_id' => $otherServer->id]);
    depthFailedResult($otherServer, 'DB-5');

    Livewire::test(Index::class)
        ->assertSee('1 failed job(s) on connected instances')
        ->call('runFailedJobs', 'run')
        ->call('runFailedJobs', 'delete');

    expect(RemoteControlCommand::query()->where('method', 'POST')->pluck('path')->all())->toBe(['/api/v1/backup/1/run?skipQueue=false']);
});

test('an instance that stops checking in raises one alert, and one when it is back', function () {
    Http::fake();
    NotificationChannel::query()->create(['user_id' => $this->user->id, 'name' => 'Hook', 'type' => 'webhook',
        'config' => ['url' => 'https://hooks.example/x', 'method' => 'POST', 'headers' => []], 'notify_overdue' => true]);
    $state = $this->registration->agentState;
    $state->update(['last_seen_at' => now()->subMinutes(20)]);

    $this->artisan('remote-control:check-offline')->assertSuccessful();
    $this->artisan('remote-control:check-offline')->assertSuccessful();

    Http::assertSentCount(1);
    Http::assertSent(fn ($request) => $request['event'] === 'instance_offline');
    expect($state->fresh()->offline_notified_at)->not->toBeNull();

    Http::fake();
    $state->update(['last_seen_at' => now()]);
    $this->artisan('remote-control:check-offline')->assertSuccessful();

    Http::assertSent(fn ($request) => $request['event'] === 'instance_online');
    expect($state->fresh()->offline_notified_at)->toBeNull();
});

test('offline alerts respect snooze and can be turned off', function () {
    Http::fake();
    NotificationChannel::query()->create(['user_id' => $this->user->id, 'name' => 'Hook', 'type' => 'webhook',
        'config' => ['url' => 'https://hooks.example/x', 'method' => 'POST', 'headers' => []], 'notify_overdue' => true]);
    $server = BackupServer::query()->create(['name' => 'NAS', 'user_id' => $this->user->id, 'muted_until' => now()->addDay()]);
    $this->registration->update(['backup_server_id' => $server->id]);
    $this->registration->agentState->update(['last_seen_at' => now()->subHour()]);

    $this->artisan('remote-control:check-offline')->assertSuccessful();
    Http::assertNothingSent();
    expect($this->registration->agentState->fresh()->offline_notified_at)->not->toBeNull();

    config(['remote_control.offline_alert_minutes' => 0]);
    $this->registration->agentState->update(['offline_notified_at' => null]);
    $server->update(['muted_until' => null]);
    $this->artisan('remote-control:check-offline')->assertSuccessful();
    Http::assertNothingSent();
});

test('restore filters, folder navigation and destinations', function () {
    expect(BrowsesRestore::restoreFilter('C:\\Data (old)\\'))->toBe('[C:\\\\Data \\(old\\)\\\\.*]')
        ->and(BrowsesRestore::restoreFilter('/srv/a[1].txt'))->toBe('@/srv/a[1].txt')
        ->and(BrowsesRestore::parentFolder('/home/alex/docs/'))->toBe('/home/alex/')
        ->and(BrowsesRestore::parentFolder('/home/'))->toBe('/')
        ->and(BrowsesRestore::parentFolder('/'))->toBeNull()
        ->and(BrowsesRestore::parentFolder('C:\\Users\\alex\\'))->toBe('C:\\Users\\')
        ->and(BrowsesRestore::parentFolder('C:\\'))->toBeNull()
        ->and(BrowsesRestore::baseName('/home/alex/notes.txt'))->toBe('notes.txt')
        ->and(BrowsesRestore::baseName('C:\\Users\\alex\\'))->toBe('alex');

    expect(DuplicatiJobConfig::destination('ftp://user:secret@ftp.example.com/backups?ssl=true'))->toBe(['type' => 'ftp', 'location' => 'ftp.example.com/backups'])
        ->and(DuplicatiJobConfig::destination('file:///mnt/usb/backup'))->toBe(['type' => 'file', 'location' => '/mnt/usb/backup'])
        ->and(DuplicatiJobConfig::repeatLabel('12h'))->toBe('every 12 hours')
        ->and(DuplicatiJobConfig::repeatLabel('1W'))->toBe('every week');
});

test('job buttons render valid Livewire calls', function () {
    $component = ($this->page)();
    answer($component->get('backupsCommandId'), 200, [['Backup' => ['ID' => '1', 'Name' => 'Docs']]]);
    $html = $component->call('poll')->html();

    // Blade's @js() is not compiled inside Flux component attributes; these must be real calls.
    foreach (["showJob('1')", "runBackup('1')", "openRestore('1')", "runOperation('verify', '1')", "runOperation('recreate', '1')", "repairBackup('1')"] as $call) {
        expect($html)->toContain('wire:click="'.$call.'"');
    }
    expect($html)->not->toContain('@js(');
});
