<?php

use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\NotificationChannel;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| Upgrade paths: databases as they exist in the wild (tests/Fixtures/database-*.sql)
| are migrated to the current schema. The fixtures are frozen; never regenerate them
| from today's migrations, or this test stops catching edits to old migrations.
*/

beforeEach(function () {
    $this->upgradeFile = tempnam(sys_get_temp_dir(), 'dc-upgrade-');
    $this->originalConnection = config('database.default');

    config(['database.connections.upgrade' => [
        'driver' => 'sqlite',
        'database' => $this->upgradeFile,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
});

afterEach(function () {
    // Back to the in-memory test database before RefreshDatabase rolls back.
    config(['database.default' => $this->originalConnection]);
    DB::disconnect('upgrade');
    DB::purge('upgrade');
    @unlink($this->upgradeFile);
});

function upgradeFrom(string $fixture): void
{
    DB::connection('upgrade')->unprepared(file_get_contents(base_path("tests/Fixtures/database-{$fixture}.sql")));

    // Migrations and models use the default connection, exactly like a real upgrade.
    config(['database.default' => 'upgrade']);

    expect(Artisan::call('migrate', ['--force' => true]))->toBe(0);
}

function expectAllMigrationsRan(): void
{
    $files = collect(glob(database_path('migrations/*.php')))
        ->map(fn (string $path) => str_replace('.php', '', basename($path)))
        ->sort()->values()->all();

    expect(DB::table('migrations')->orderBy('migration')->pluck('migration')->all())->toBe($files);
}

test('a legacy database (before v0.1.0) upgrades to the current schema', function () {
    upgradeFrom('legacy');

    expectAllMigrationsRan();

    $user = User::where('email', 'upgrade@example.com')->firstOrFail();
    $server = BackupServer::ownedBy($user)->firstOrFail();
    expect($server->name)->toBe('Legacy NAS');

    // Log lines moved from backup_results.log_lines to backup_result_logs.
    $results = BackupResult::forUser($user)->orderBy('id')->get();
    expect($results)->toHaveCount(3)
        ->and($results[0]->log_lines)->toBe(['Backup started day 1', 'Backup finished: Success'])
        ->and($results[2]->status->isFailure())->toBeTrue()
        ->and($results[0]->source)->toBe('report');

    // Plaintext channel config is now encrypted and still readable, with defaults for new options.
    $channel = NotificationChannel::where('user_id', $user->id)->firstOrFail();
    expect($channel->config)->toBe(['webhook_url' => 'https://discord.example/hook'])
        ->and(DB::table('notification_channels')->value('config'))->not->toContain('discord.example')
        ->and($channel->notify_on)->toBe('warning')
        ->and($channel->digest)->toBe('off');

    $this->actingAs($user)->get('/')->assertOk()->assertSee('Legacy NAS');
    $this->actingAs($user)->get(route('backup-detail', $results[0]->id))->assertOk()->assertSee('Backup started day 1');
});

test('a v0.1.0 database upgrades to the current schema', function () {
    upgradeFrom('v0.1.0');

    expectAllMigrationsRan();

    $user = User::where('email', 'upgrade@example.com')->firstOrFail();
    $server = BackupServer::ownedBy($user)->firstOrFail();

    expect(BackupResult::forUser($user)->count())->toBe(3)
        ->and($server->jobSettings()->count())->toBe(0);

    $this->actingAs($user)->get('/')->assertOk()->assertSee('Legacy NAS');
    $this->actingAs($user)->get(route('backups'))->assertOk();
});
