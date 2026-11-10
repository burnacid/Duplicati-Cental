<?php

use App\Models\BackupServer;
use App\Models\User;
use App\Services\CentralBackupService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/*
| app:backup / app:restore against a real SQLite file (VACUUM INTO cannot run on the
| in-memory test database inside its transaction) and a temporary storage folder.
*/

beforeEach(function () {
    $this->root = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dc-central-backup-'.bin2hex(random_bytes(4));
    mkdir($this->root.'/storage/app/remote-control', 0777, true);
    $this->originalStorage = $this->app->storagePath();
    $this->originalConnection = config('database.default');
    $this->app->useStoragePath($this->root.'/storage');

    file_put_contents(storage_path('app/remote-control/server_private.pem'), "PRIVATE-ORIGINAL\n");
    file_put_contents(storage_path('app/remote-control/server_public.pem'), "PUBLIC-ORIGINAL\n");

    $this->database = $this->root.'/database.sqlite';
    touch($this->database);
    config([
        'database.connections.backup_test' => ['driver' => 'sqlite', 'database' => $this->database, 'prefix' => '', 'foreign_key_constraints' => true],
        'database.default' => 'backup_test',
        'backups.central.path' => $this->root.'/backups',
        'backups.central.password' => null,
        'backups.central.keep' => 7,
    ]);
    Artisan::call('migrate', ['--force' => true]);

    $this->user = User::factory()->create(['email' => 'owner@example.com']);
    BackupServer::query()->create(['name' => 'Original NAS', 'user_id' => $this->user->id]);
});

afterEach(function () {
    config(['database.default' => $this->originalConnection]);
    DB::disconnect('backup_test');
    DB::purge('backup_test');
    $this->app->useStoragePath($this->originalStorage);

    $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($items as $item) {
        $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }
    @rmdir($this->root);
});

function onlyBackup(): string
{
    $all = app(CentralBackupService::class)->all();
    expect($all)->toHaveCount(1);

    return $all[0]['path'];
}

test('app:backup writes database, APP_KEY, keys and a manifest', function () {
    $this->artisan('app:backup')->assertSuccessful();

    $zip = new ZipArchive;
    expect($zip->open(onlyBackup()))->toBeTrue();

    $manifest = json_decode($zip->getFromName('manifest.json'), true);
    expect($manifest)->toMatchArray(['format' => 1, 'database_included' => true, 'encrypted' => false, 'database_driver' => 'sqlite'])
        ->and($zip->getFromName('app_key'))->toBe(config('app.key'))
        ->and($zip->getFromName('remote-control/server_private.pem'))->toBe("PRIVATE-ORIGINAL\n")
        ->and(substr($zip->getFromName('database.sqlite'), 0, 15))->toBe('SQLite format 3');
    $zip->close();

    // No snapshot or partial files are left behind.
    expect(array_values(array_diff(scandir($this->root.'/backups'), ['.', '..'])))->toBe([basename(onlyBackup())]);
});

test('app:restore brings back the database and keys and keeps the previous database', function () {
    $this->artisan('app:backup')->assertSuccessful();
    $backup = onlyBackup();

    // Things change (or go wrong) after the backup.
    BackupServer::query()->update(['name' => 'Changed NAS']);
    User::factory()->create(['email' => 'later@example.com']);
    file_put_contents(storage_path('app/remote-control/server_private.pem'), "PRIVATE-NEW\n");

    $this->artisan('app:restore', ['file' => $backup, '--force' => true])->assertSuccessful();

    expect(BackupServer::query()->pluck('name')->all())->toBe(['Original NAS'])
        ->and(User::query()->where('email', 'later@example.com')->exists())->toBeFalse()
        ->and(file_get_contents(storage_path('app/remote-control/server_private.pem')))->toBe("PRIVATE-ORIGINAL\n")
        ->and(file_get_contents(storage_path('.app_key')))->toBe(config('app.key'))
        ->and(glob($this->database.'.before-restore-*'))->toHaveCount(1);
});

test('an encrypted backup needs the right password and a wrong one changes nothing', function () {
    if (! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) {
        $this->markTestSkipped('zip extension without AES support');
    }

    $this->artisan('app:backup', ['--password' => 'correct horse'])->assertSuccessful();
    $backup = onlyBackup();

    $zip = new ZipArchive;
    $zip->open($backup);
    expect($zip->getFromName('app_key'))->toBeFalse(); // unreadable without the password
    $zip->close();

    BackupServer::query()->update(['name' => 'Changed NAS']);

    $this->artisan('app:restore', ['file' => $backup, '--force' => true, '--password' => 'wrong'])->assertFailed();
    expect(BackupServer::query()->value('name'))->toBe('Changed NAS')
        ->and(glob($this->database.'.before-restore-*'))->toBe([]);

    $this->artisan('app:restore', ['file' => $backup, '--force' => true, '--no-interaction' => true])->assertFailed();

    $this->artisan('app:restore', ['file' => $backup, '--force' => true, '--password' => 'correct horse'])->assertSuccessful();
    expect(BackupServer::query()->value('name'))->toBe('Original NAS');
});

test('app:restore refuses files that are not a Central backup', function () {
    $file = $this->root.'/other.zip';
    $zip = new ZipArchive;
    $zip->open($file, ZipArchive::CREATE);
    $zip->addFromString('readme.txt', 'hello');
    $zip->close();

    $this->artisan('app:restore', ['file' => $file, '--force' => true])->assertFailed();
    $this->artisan('app:restore', ['file' => $this->root.'/missing.zip', '--force' => true])->assertFailed();

    expect(BackupServer::query()->value('name'))->toBe('Original NAS');
});

test('app:backup keeps only the newest backups', function () {
    mkdir($this->root.'/backups', 0700, true);
    foreach (['20260101-000000', '20260102-000000', '20260103-000000'] as $stamp) {
        file_put_contents($this->root."/backups/duplicati-central-{$stamp}.zip", 'old');
    }
    file_put_contents($this->root.'/backups/unrelated.zip', 'keep me');

    $this->artisan('app:backup', ['--keep' => 2])->assertSuccessful();

    $names = array_column(app(CentralBackupService::class)->all(), 'name');
    expect($names)->toHaveCount(2)
        ->and($names[1])->toBe('duplicati-central-20260103-000000.zip')
        ->and(is_file($this->root.'/backups/unrelated.zip'))->toBeTrue();
});
