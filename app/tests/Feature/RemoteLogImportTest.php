<?php

use App\Jobs\ImportRemoteControlLogs;
use App\Jobs\SendBackupResultNotification;
use App\Livewire\RemoteControl\Show;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\RemoteControlRegistration;
use App\Models\User;
use App\Services\BackupResultFactory;
use App\Services\RemoteControlCommandService;
use App\Services\RemoteControlProvisioningService;
use App\Services\RemoteLogImportService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

/** A log row as Duplicati's /api/v1/backup/{id}/log returns it. */
function logRow(int $id, Carbon $begin, string $status = 'Success', string $operation = 'Backup', string $type = 'Result'): array
{
    return [
        'ID' => $id,
        'OperationID' => $id,
        'Timestamp' => $begin->copy()->addMinutes(5)->timestamp,
        'Type' => $type,
        'Message' => json_encode([
            'MainOperation' => $operation,
            'ParsedResult' => $status,
            'Interrupted' => false,
            'Version' => '2.1.0.5',
            'BeginTime' => $begin->copy()->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'EndTime' => $begin->copy()->addMinutes(5)->utc()->format('Y-m-d\TH:i:s.u\Z'),
            'Duration' => '00:05:00',
            'MessagesActualLength' => 1,
            'WarningsActualLength' => 0,
            'ErrorsActualLength' => $status === 'Fatal' ? 1 : 0,
            'BackendStatistics' => ['KnownFileSize' => 4096],
            'ExaminedFiles' => 10,
            'Messages' => ["Run {$id}"],
        ]),
        'Exception' => null,
    ];
}

/**
 * Importer whose transport is a fake Duplicati: $logs maps job id => all log rows (any order).
 */
function fakeImporter(array $jobs, array $logs, array &$requests = []): RemoteLogImportService
{
    $importer = Mockery::mock(RemoteLogImportService::class, [
        app(RemoteControlCommandService::class),
        app(BackupResultFactory::class),
        app(RemoteControlProvisioningService::class),
    ])->makePartial()->shouldAllowMockingProtectedMethods();

    $importer->shouldReceive('request')->andReturnUsing(function ($registration, $method, $path) use ($jobs, $logs, &$requests) {
        $requests[] = $path;

        if ($path === '/api/v1/backups') {
            return [200, array_map(fn ($id, $name) => ['Backup' => ['ID' => (string) $id, 'Name' => $name]], array_keys($jobs), $jobs)];
        }

        if (preg_match('#^/api/v1/backup/([^/]+)/log\?pagesize=(\d+)(?:&offset=(\d+))?$#', $path, $m)) {
            if (($logs[$m[1]] ?? null) === 'fail') {
                return [500, ['Error' => 'Database locked']];
            }

            $rows = collect($logs[$m[1]] ?? [])
                ->when(isset($m[3]), fn ($c) => $c->filter(fn ($r) => $r['ID'] < (int) $m[3]))
                ->sortByDesc('ID')
                ->take((int) $m[2])
                ->values()
                ->all();

            return [200, $rows];
        }

        return [404, ['Error' => 'Not found']];
    });

    return $importer;
}

beforeEach(function () {
    Queue::fake();

    $this->user = User::factory()->create();
    $this->server = BackupServer::query()->create(['name' => 'Office NAS', 'user_id' => $this->user->id]);
    $this->registration = RemoteControlRegistration::query()->create([
        'machine_id' => 'machine-42',
        'machine_name' => 'Office PC',
        'registration_token' => 'reg-' . bin2hex(random_bytes(8)),
        'claim_token' => 'claim-' . bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'claimed_by_user_id' => $this->user->id,
        'backup_server_id' => $this->server->id,
        'claimed_at' => now(),
        'expires_at' => now()->addHour(),
    ]);
    $this->registration->agentState()->create(['last_seen_at' => now(), 'connected_at' => now()]);
});

test('it imports results of the last 30 days and skips ones already stored', function () {
    $existingBegin = now()->subDays(2)->startOfMinute();

    // An existing pushed report for the same run (Duplicati sends sub-second precision too).
    $factory = app(BackupResultFactory::class);
    $existing = $factory->make($this->server, json_decode(logRow(1, $existingBegin)['Message'], true), [
        'backup-name' => 'Documents', 'backup-id' => 'DB-1', 'machine-id' => 'machine-42',
    ]);
    $existing->save();

    $logs = ['1' => [
        logRow(10, now()->subDays(1)->startOfMinute(), 'Success'),
        logRow(9, $existingBegin, 'Success'),                                   // duplicate of the report
        logRow(8, now()->subDays(3)->startOfMinute(), 'Fatal'),
        logRow(7, now()->subDays(3)->startOfMinute(), 'Success', 'Backup', 'Information'), // not a result
        logRow(6, now()->subDays(4)->startOfMinute(), 'Success', 'List'),       // ignored operation
        logRow(5, now()->subDays(40)->startOfMinute(), 'Success'),              // too old
    ]];

    $stats = fakeImporter(['1' => 'Documents'], $logs)->import($this->registration, 30);

    expect($stats)->toMatchArray(['jobs' => 1, 'imported' => 2, 'skipped' => 1, 'failed_jobs' => []]);

    $imported = BackupResult::where('source', 'remote-log')->orderBy('BeginTime')->get();
    expect($imported)->toHaveCount(2)
        ->and($imported->pluck('ParsedResult')->all())->toBe(['Fatal', 'Success'])
        ->and($imported->first()->backup_server_id)->toBe($this->server->id)
        ->and($imported->first()->backup_id)->toBe('DB-1')
        ->and($imported->first()->backup_name)->toBe('Documents')
        ->and($imported->first()->machine_id)->toBe('machine-42')
        ->and($imported->first()->log_lines)->toBe(['Run 8'])
        ->and($imported->first()->remote_log_id)->toBe(8);

    Queue::assertNotPushed(SendBackupResultNotification::class);
});

test('running the import twice does not create duplicates', function () {
    $logs = ['1' => [logRow(3, now()->subDay()), logRow(2, now()->subDays(2))]];

    fakeImporter(['1' => 'Documents'], $logs)->import($this->registration);
    $second = fakeImporter(['1' => 'Documents'], $logs)->import($this->registration);

    expect(BackupResult::count())->toBe(2)
        ->and($second['imported'])->toBe(0)
        ->and($second['skipped'])->toBe(2);
});

test('it pages through long logs using the lowest id as offset', function () {
    $rows = [];
    for ($i = 60; $i >= 1; $i--) {
        $rows[] = logRow($i, now()->subHours(61 - $i)->startOfMinute());
    }

    $requests = [];
    $stats = fakeImporter(['7' => 'Photos'], ['7' => $rows], $requests)->import($this->registration);

    expect($stats['imported'])->toBe(60)
        ->and($requests)->toContain('/api/v1/backup/7/log?pagesize=25')
        ->and($requests)->toContain('/api/v1/backup/7/log?pagesize=25&offset=36')
        ->and($requests)->toContain('/api/v1/backup/7/log?pagesize=25&offset=11');
});

test('a failing job is reported without stopping the others', function () {
    $importer = fakeImporter(['1' => 'Good', '2' => 'Broken'], ['1' => [logRow(1, now()->subDay())], '2' => 'fail']);

    $stats = $importer->import($this->registration);

    expect($stats['imported'])->toBe(1)
        ->and($stats['failed_jobs'])->toHaveCount(1)
        ->and($stats['failed_jobs'][0])->toContain('Broken');
});

test('it refuses when the instance is offline', function () {
    $this->registration->agentState()->update(['last_seen_at' => now()->subHour()]);

    expect(fn () => fakeImporter([], [])->import($this->registration->fresh()))
        ->toThrow(RuntimeException::class, 'not connected');
});

test('the machine page queues an import and shows its status', function () {
    $this->actingAs($this->user);

    Livewire::test(Show::class, ['id' => (string) $this->registration->id])
        ->call('importHistory')
        ->assertSee('Import queued')
        ->call('importHistory');

    Queue::assertPushed(ImportRemoteControlLogs::class, 1);
});
