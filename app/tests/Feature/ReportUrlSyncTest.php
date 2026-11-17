<?php

use App\Console\Commands\SyncReportUrlsCommand;
use App\Jobs\SyncReportUrls;
use App\Livewire\BackupServers\Index as BackupServersIndex;
use App\Livewire\RemoteControl\Index as RemoteControlIndex;
use App\Livewire\RemoteControl\Show;
use App\Models\BackupServer;
use App\Models\EventLog;
use App\Models\RemoteControlRegistration;
use App\Models\User;
use App\Services\RemoteControlAgentSyncService;
use App\Services\RemoteControlCommandService;
use App\Services\RemoteControlReportUrlService;
use App\Services\RemoteControlUrlService;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/**
 * Fake Duplicati behind dispatchAndWait. $state holds 'serversettings' and 'jobs' (id => config)
 * and is updated by PATCH/PUT like Duplicati would; every request is appended to $state['requests'].
 */
function fakeDuplicatiApi(array &$state): void
{
    $commands = Mockery::mock(RemoteControlCommandService::class)->makePartial();
    $commands->shouldReceive('dispatchAndWait')->andReturnUsing(function ($registration, $method, $path, $body = null) use (&$state) {
        $state['requests'][] = "{$method} {$path}";

        if ($path === '/api/v1/serversettings') {
            if ($method === 'PATCH') {
                foreach ($body as $key => $value) {
                    if ($value === null) {
                        unset($state['serversettings'][$key]);
                    } else {
                        $state['serversettings'][$key] = $value;
                    }
                }

                return [200, null];
            }

            return [200, $state['serversettings']];
        }

        if ($path === '/api/v1/backups') {
            return [200, array_values(array_map(fn ($config) => ['Backup' => $config['Backup']], $state['jobs']))];
        }

        if (preg_match('#^/api/v1/backup/([^/]+)$#', $path, $m) && in_array($m[1], $state['broken'] ?? [], true)) {
            return [500, ['Error' => 'Database locked']];
        }

        if (preg_match('#^/api/v1/backup/([^/]+)$#', $path, $m) && isset($state['jobs'][$m[1]])) {
            if ($method === 'PUT') {
                $state['put'][$m[1]] = $body;
                $state['jobs'][$m[1]]['Backup']['Settings'] = $body['Backup']['Settings'];

                return [200, null];
            }

            return [200, $state['jobs'][$m[1]]];
        }

        return [404, null];
    });

    app()->instance(RemoteControlCommandService::class, $commands);
}

function duplicatiJob(string $id, string $name, array $settings): array
{
    return [
        'Backup' => [
            'ID' => $id, 'Name' => $name, 'Description' => '', 'Tags' => [], 'TargetURL' => 'file:///backups/'.$id,
            'Sources' => ['/data/'], 'Filters' => [], 'Metadata' => [], 'AdditionalTargetURLs' => [],
            'Settings' => array_map(fn ($value, $name) => ['Name' => $name, 'Value' => $value, 'Filter' => ''], $settings, array_keys($settings)),
        ],
        'Schedule' => ['ID' => 1, 'Tags' => ["ID={$id}"], 'Time' => '2026-11-18T02:00:00Z', 'Repeat' => '1D', 'Rule' => '', 'AllowedDays' => []],
        'DisplayNames' => [],
    ];
}

beforeEach(function () {
    Queue::fake();
    config(['remote_control.enabled' => true]);

    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'Office NAS', 'user_id' => $this->user->id]);
    $this->registration = RemoteControlRegistration::query()->create([
        'machine_id' => 'machine-42',
        'machine_name' => 'Office PC',
        'registration_token' => 'reg-'.bin2hex(random_bytes(8)),
        'claim_token' => 'claim-'.bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'claimed_by_user_id' => $this->user->id,
        'backup_server_id' => $this->server->id,
        'registration_base_url' => 'https://central.example',
        'claimed_at' => now(),
        'expires_at' => now()->addHour(),
        'settings' => ['dashboardurl' => 'https://central.example', 'reportingurl' => 'https://old.example/api/backup-servers/x/y/backup-result', 'settingsversion' => '3'],
    ]);
    $this->registration->agentState()->create(['last_seen_at' => now(), 'connected_at' => now(), 'last_applied_settings_version' => 3]);
    $this->expected = "https://central.example/api/backup-servers/{$this->server->id}/{$this->server->api_key}/backup-result";
    $this->duplicati = ['serversettings' => [], 'jobs' => [], 'requests' => []];
});

test('central endpoints are removed from URL lists and other services are kept', function () {
    expect(RemoteControlReportUrlService::withoutCentralUrls('https://old.example/api/backup-servers/a/b/backup-result; https://hc.example/hook'))
        ->toBe(['https://hc.example/hook', 1])
        ->and(RemoteControlReportUrlService::withoutCentralUrls('http://192.168.1.5:8181/api/backup-servers/a/b/backup-result'))
        ->toBe(['', 1])
        ->and(RemoteControlReportUrlService::withoutCentralUrls('https://hc.example/api/backup-servers'))
        ->toBe(['https://hc.example/api/backup-servers', 0]);
});

test('an outdated managed URL is replaced with a new settings version', function () {
    $service = app(RemoteControlReportUrlService::class);
    expect($service->status($this->registration)['state'])->toBe('wrong');

    expect($service->pushManagedUrl($this->registration))->toBeTrue();

    $settings = $this->registration->fresh()->settings;
    expect($settings['reportingurl'])->toBe($this->expected)
        ->and($settings['settingsversion'])->toBe('4')
        ->and($service->status($this->registration->fresh(['backupServer', 'agentState']))['state'])->toBe('pending');

    // Already correct: nothing changes.
    expect($service->pushManagedUrl($this->registration->fresh()))->toBeFalse()
        ->and($this->registration->fresh()->settings['settingsversion'])->toBe('4');
});

test('sync removes hand-set central URLs from default options and jobs only', function () {
    $this->duplicati['serversettings'] = [
        '--send-http-url' => 'https://old.example/api/backup-servers/x/y/backup-result',
        '--send-http-json-urls' => 'https://other.example/hook;http://central.example/api/backup-servers/a/b/backup-result',
        '--send-http-result-output-format' => 'Json',
        'server-port-changed' => 'True',
    ];
    $this->duplicati['jobs'] = [
        '1' => duplicatiJob('1', 'Docs', ['--send-http-url' => 'https://old.example/api/backup-servers/x/y/backup-result', 'passphrase' => '**********', 'keep-versions' => '5']),
        '2' => duplicatiJob('2', 'Photos', ['keep-versions' => '3']),
    ];
    fakeDuplicatiApi($this->duplicati);

    $summary = app(RemoteControlReportUrlService::class)->sync($this->registration);

    expect($summary['pushed'])->toBeTrue()
        ->and($summary['changed'])->toBe(['Default options', 'Job "Docs"'])
        ->and($summary['failed'])->toBe([]);

    expect($this->duplicati['serversettings'])->toBe([
        '--send-http-json-urls' => 'https://other.example/hook',
        '--send-http-result-output-format' => 'Json',
        'server-port-changed' => 'True',
    ]);

    // Only the changed job is saved, with its other settings (masked password included) and schedule intact.
    expect(array_keys($this->duplicati['put']))->toEqual(['1'])
        ->and(array_column($this->duplicati['put']['1']['Backup']['Settings'], 'Value', 'Name'))->toBe(['passphrase' => '**********', 'keep-versions' => '5'])
        ->and($this->duplicati['put']['1']['Schedule']['Repeat'])->toBe('1D');

    $messages = EventLog::query()->where('category', EventLog::CATEGORY_REMOTE)->orderBy('id')->pluck('message')->all();
    expect($messages[0])->toBe('Reporting URL updated on Office NAS.')
        ->and($messages[1])->toContain('Default options, Job "Docs"');
});

test('a job that fails to save is reported and the others are still checked', function () {
    $this->registration->update(['settings' => ['reportingurl' => $this->expected, 'settingsversion' => '3']]);
    $this->duplicati['jobs'] = [
        '1' => duplicatiJob('1', 'Docs', ['--send-http-url' => 'https://old.example/api/backup-servers/x/y/backup-result']),
        '9' => ['Backup' => ['ID' => '9', 'Name' => 'Broken']],
    ];
    $this->duplicati['broken'] = ['9'];
    fakeDuplicatiApi($this->duplicati);

    $summary = app(RemoteControlReportUrlService::class)->sync($this->registration);

    expect($summary['pushed'])->toBeFalse()
        ->and($summary['changed'])->toBe(['Job "Docs"'])
        ->and($summary['failed'][0])->toContain('Job "Broken"');
    expect(EventLog::query()->where('level', EventLog::LEVEL_WARNING)->sole()->message)->toContain('Could not check all report options');
});

test('a disconnected instance gets the managed URL but no cleanup', function () {
    $this->registration->agentState->update(['last_seen_at' => now()->subHour()]);
    fakeDuplicatiApi($this->duplicati);

    $summary = app(RemoteControlReportUrlService::class)->sync($this->registration);

    expect($summary['pushed'])->toBeTrue()
        ->and($summary['connected'])->toBeFalse()
        ->and($this->duplicati['requests'])->toBe([])
        ->and(EventLog::query()->sole()->message)->toContain('when the instance reconnects');
});

test('a connected instance gets new settings once per version', function () {
    $sync = app(RemoteControlAgentSyncService::class);
    $registration = $this->registration->fresh('agentState');

    expect($sync->pendingSettingsControl($registration, null))->toBeNull(); // version 3 already applied

    app(RemoteControlReportUrlService::class)->pushManagedUrl($registration);
    $registration = $registration->fresh('agentState');

    $control = $sync->pendingSettingsControl($registration, null);
    expect($control['command'])->toBe('updatesettings')
        ->and($control['parameters']['reportingurl'])->toBe($this->expected);
    expect($sync->pendingSettingsControl($registration, 4))->toBeNull();
});

test('a reconnecting instance with a different report URL gets its settings again', function () {
    $this->registration->update(['settings' => ['reportingurl' => $this->expected, 'settingsversion' => '3']]);
    $sync = app(RemoteControlAgentSyncService::class);

    $same = $sync->touchAndBuildControl($this->registration->fresh(), [RemoteControlAgentSyncService::REPORT_URL_METADATA => $this->expected]);
    expect($same['control'])->toBeNull();

    $drifted = $sync->touchAndBuildControl($this->registration->fresh(), [RemoteControlAgentSyncService::REPORT_URL_METADATA => '']);
    expect($drifted['control']['parameters']['reportingurl'])->toBe($this->expected);
});

test('regenerating the API key updates the managed URL of the linked instance', function () {
    $this->registration->update(['settings' => ['reportingurl' => $this->expected, 'settingsversion' => '3']]);

    Livewire::actingAs($this->user)->test(BackupServersIndex::class)
        ->call('confirmRegenerate', $this->server->id)
        ->call('regenerateKey');

    $newKey = $this->server->fresh()->api_key;
    expect($newKey)->not->toBe($this->server->api_key)
        ->and($this->registration->fresh()->settings['reportingurl'])->toBe(
            app(RemoteControlUrlService::class)->reportingUrlFor($this->registration, $this->server->fresh())
        )
        ->and($this->registration->fresh()->settings['reportingurl'])->toContain($newKey);
});

test('the machine page shows the reporting state and queues a fix', function () {
    Livewire::actingAs($this->user)->test(Show::class, ['id' => $this->registration->id])
        ->assertSee('Outdated')
        ->assertSee($this->expected)
        ->call('fixReportingUrl')
        ->assertSee('Checking the reporting URL');

    Queue::assertPushed(SyncReportUrls::class, fn ($job) => $job->registrationId === $this->registration->id);
});

test('the remote control page queues a fix for every own instance only', function () {
    $other = User::factory()->create();
    RemoteControlRegistration::query()->create([
        'machine_id' => 'm-other', 'registration_token' => 'r-o', 'claim_token' => 'c-o',
        'status' => RemoteControlRegistration::STATUS_CLAIMED, 'claimed_by_user_id' => $other->id, 'expires_at' => now()->addHour(),
    ]);

    Livewire::actingAs($this->user)->test(RemoteControlIndex::class)->call('fixReportingUrls');

    Queue::assertPushed(SyncReportUrls::class, 1);
    Queue::assertPushed(SyncReportUrls::class, fn ($job) => $job->registrationId === $this->registration->id);
});

test('the daily command syncs claimed instances and does nothing when remote control is off', function () {
    fakeDuplicatiApi($this->duplicati);

    $this->artisan(SyncReportUrlsCommand::class)->assertSuccessful();
    expect($this->registration->fresh()->settings['reportingurl'])->toBe($this->expected);

    config(['remote_control.enabled' => false]);
    $this->artisan(SyncReportUrlsCommand::class)->assertFailed();
});

test('the queued job records an unchanged result for manual checks', function () {
    $this->registration->update(['settings' => ['reportingurl' => $this->expected, 'settingsversion' => '3']]);
    fakeDuplicatiApi($this->duplicati);

    (new SyncReportUrls($this->registration->id))->handle(app(RemoteControlReportUrlService::class));

    expect(EventLog::query()->sole()->message)->toBe('Reporting URL on Office NAS is correct.');
});
