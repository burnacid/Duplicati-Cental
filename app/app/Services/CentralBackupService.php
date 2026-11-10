<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Backups of Duplicati Central itself: one zip with the SQLite database, the
 * encryption key (APP_KEY, needed for channel secrets and 2FA) and the
 * remote-control key pair (instances trust that key).
 */
class CentralBackupService
{
    public const FORMAT = 1;

    private const PREFIX = 'duplicati-central-';

    private const DATABASE_ENTRY = 'database.sqlite';

    private const APP_KEY_ENTRY = 'app_key';

    private const KEYS_ENTRY = 'remote-control/';

    private const MANIFEST_ENTRY = 'manifest.json';

    /**
     * @return array{path: string, name: string, size: int, created_at: CarbonImmutable, database_included: bool}
     */
    public function create(?string $password = null): array
    {
        $directory = $this->directory();
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the backup folder {$directory}.");
        }

        if ($password !== null && $password !== '' && ! ZipArchive::isEncryptionMethodSupported(ZipArchive::EM_AES_256)) {
            throw new RuntimeException('This PHP zip extension cannot encrypt archives (AES-256).');
        }

        $createdAt = CarbonImmutable::now('UTC');
        $name = self::PREFIX.$createdAt->format('Ymd-His').'.zip';
        $path = $directory.DIRECTORY_SEPARATOR.$name;
        $partial = $directory.DIRECTORY_SEPARATOR.'.partial-'.$name;
        $snapshot = null;

        try {
            $snapshot = $this->snapshotDatabase($directory);

            $zip = new ZipArchive;
            if ($zip->open($partial, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException("Cannot write {$partial}.");
            }

            $entries = [];
            if ($snapshot !== null) {
                $zip->addFile($snapshot, self::DATABASE_ENTRY);
                $entries[] = self::DATABASE_ENTRY;
            }

            $zip->addFromString(self::APP_KEY_ENTRY, (string) config('app.key'));
            $entries[] = self::APP_KEY_ENTRY;

            foreach (glob($this->keysDirectory().DIRECTORY_SEPARATOR.'*.pem') ?: [] as $keyFile) {
                $zip->addFile($keyFile, self::KEYS_ENTRY.basename($keyFile));
                $entries[] = self::KEYS_ENTRY.basename($keyFile);
            }

            $encrypted = $password !== null && $password !== '';
            if ($encrypted) {
                foreach ($entries as $entry) {
                    $zip->setEncryptionName($entry, ZipArchive::EM_AES_256, $password);
                }
            }

            // The manifest holds no secrets and stays readable, so restore can ask for a password.
            $zip->addFromString(self::MANIFEST_ENTRY, json_encode([
                'format' => self::FORMAT,
                'app_version' => (string) config('app.version'),
                'created_at' => $createdAt->toIso8601String(),
                'database_driver' => DB::connection()->getDriverName(),
                'database_included' => $snapshot !== null,
                'encrypted' => $encrypted,
                'entries' => $entries,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if (! $zip->close()) {
                throw new RuntimeException("Cannot write {$partial}.");
            }

            @chmod($partial, 0600);
            if (! rename($partial, $path)) {
                throw new RuntimeException("Cannot move the backup to {$path}.");
            }
        } finally {
            if ($snapshot !== null) {
                @unlink($snapshot);
            }
            if (is_file($partial)) {
                @unlink($partial);
            }
        }

        return [
            'path' => $path,
            'name' => $name,
            'size' => (int) filesize($path),
            'created_at' => $createdAt,
            'database_included' => $snapshot !== null,
        ];
    }

    /**
     * Deletes the oldest backups so that $keep remain (0 keeps all). Returns the number deleted.
     */
    public function prune(int $keep): int
    {
        if ($keep <= 0) {
            return 0;
        }

        $deleted = 0;
        foreach (array_slice($this->all(), $keep) as $backup) {
            if (@unlink($backup['path'])) {
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * @return array{path: string, name: string, size: int, created_at: CarbonImmutable}|null
     */
    public function latest(): ?array
    {
        return $this->all()[0] ?? null;
    }

    /**
     * Backups in the backup folder, newest first.
     *
     * @return list<array{path: string, name: string, size: int, created_at: CarbonImmutable}>
     */
    public function all(): array
    {
        $files = glob($this->directory().DIRECTORY_SEPARATOR.self::PREFIX.'*.zip') ?: [];
        rsort($files); // names start with a sortable UTC timestamp

        return array_map(fn (string $file) => [
            'path' => $file,
            'name' => basename($file),
            'size' => (int) filesize($file),
            'created_at' => CarbonImmutable::createFromTimestamp((int) filemtime($file)),
        ], $files);
    }

    /**
     * Reads the manifest without needing the password.
     */
    public function inspect(string $file): array
    {
        $zip = $this->open($file);
        $manifest = json_decode((string) $zip->getFromName(self::MANIFEST_ENTRY), true);
        $zip->close();

        if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT) {
            throw new RuntimeException('This file is not a Duplicati Central backup (or was made by a newer version).');
        }

        return $manifest;
    }

    /**
     * Puts the backup in place: database (the current one is kept next to it), APP_KEY file
     * and remote-control keys. Everything is checked before anything is overwritten.
     *
     * @return array{database_restored: bool, previous_database: ?string, app_key_changed: bool}
     */
    public function restore(string $file, ?string $password = null): array
    {
        $manifest = $this->inspect($file);

        if (($manifest['encrypted'] ?? false) && ($password === null || $password === '')) {
            throw new RuntimeException('This backup is password protected; pass the password.');
        }

        $includesDatabase = (bool) ($manifest['database_included'] ?? false);
        $connection = DB::connection();
        if ($includesDatabase && $connection->getDriverName() !== 'sqlite') {
            throw new RuntimeException('This backup contains an SQLite database, but this installation uses '.$connection->getDriverName().'.');
        }

        $work = $this->directory().DIRECTORY_SEPARATOR.'.restore-'.Str::random(8);
        if (! mkdir($work, 0700, true)) {
            throw new RuntimeException("Cannot create {$work}.");
        }

        try {
            $zip = $this->open($file);
            if ($manifest['encrypted'] ?? false) {
                $zip->setPassword($password);
            }

            $entries = array_values(array_filter((array) ($manifest['entries'] ?? []), 'is_string'));
            foreach ($entries as $entry) {
                if (str_contains($entry, '..') || ! ($entry === self::DATABASE_ENTRY || $entry === self::APP_KEY_ENTRY || preg_match('#^remote-control/[\w.-]+\.pem$#', $entry))) {
                    throw new RuntimeException("Unexpected file in backup: {$entry}");
                }
            }

            if (! $zip->extractTo($work, $entries)) {
                $zip->close();
                throw new RuntimeException('Cannot read the backup: wrong password or damaged file.');
            }
            $zip->close();

            $appKey = trim((string) @file_get_contents($work.DIRECTORY_SEPARATOR.self::APP_KEY_ENTRY));
            if (! str_starts_with($appKey, 'base64:')) {
                throw new RuntimeException('Cannot read the backup: wrong password or damaged file.');
            }

            $restoredDatabase = $work.DIRECTORY_SEPARATOR.self::DATABASE_ENTRY;
            if ($includesDatabase && (string) @file_get_contents($restoredDatabase, false, null, 0, 16) !== "SQLite format 3\0") {
                throw new RuntimeException('Cannot read the backup: wrong password or damaged file.');
            }

            // Checked; now write.
            $keysDirectory = $this->keysDirectory();
            if (! is_dir($keysDirectory)) {
                mkdir($keysDirectory, 0700, true);
            }
            foreach (glob($work.DIRECTORY_SEPARATOR.'remote-control'.DIRECTORY_SEPARATOR.'*.pem') ?: [] as $keyFile) {
                $target = $keysDirectory.DIRECTORY_SEPARATOR.basename($keyFile);
                copy($keyFile, $target);
                @chmod($target, 0600);
            }

            $appKeyFile = storage_path('.app_key');
            file_put_contents($appKeyFile, $appKey);
            @chmod($appKeyFile, 0600);

            $previous = null;
            if ($includesDatabase) {
                $previous = $this->replaceDatabase($restoredDatabase);
            }

            return [
                'database_restored' => $includesDatabase,
                'previous_database' => $previous,
                'app_key_changed' => $appKey !== (string) config('app.key'),
            ];
        } finally {
            $this->removeDirectory($work);
        }
    }

    public function directory(): string
    {
        return rtrim((string) config('backups.central.path'), '\\/');
    }

    private function keysDirectory(): string
    {
        return storage_path('app'.DIRECTORY_SEPARATOR.'remote-control');
    }

    /**
     * Consistent copy of the live SQLite database (safe while the app is running).
     * Other drivers are backed up with their own tools.
     */
    private function snapshotDatabase(string $directory): ?string
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'sqlite') {
            return null;
        }

        if ($connection->getConfig('database') === ':memory:') {
            throw new RuntimeException('An in-memory database cannot be backed up.');
        }

        $snapshot = $directory.DIRECTORY_SEPARATOR.'.snapshot-'.Str::random(8).'.sqlite';
        $connection->statement('VACUUM INTO ?', [$snapshot]);

        return $snapshot;
    }

    /**
     * Swaps in the restored database file; returns where the previous one was kept.
     */
    private function replaceDatabase(string $restored): ?string
    {
        $connection = DB::connection();
        $target = (string) $connection->getConfig('database');
        $connection->disconnect();

        $previous = null;
        if (is_file($target)) {
            $previous = $target.'.before-restore-'.now()->utc()->format('Ymd-His');
            if (! rename($target, $previous)) {
                throw new RuntimeException("Cannot move the current database aside ({$target}). Stop Duplicati Central and try again.");
            }
            foreach (['-wal', '-shm', '-journal'] as $suffix) {
                if (is_file($target.$suffix)) {
                    @rename($target.$suffix, $previous.$suffix);
                }
            }
        }

        if (! copy($restored, $target)) {
            throw new RuntimeException("Cannot write the database to {$target}.");
        }

        $connection->reconnect();

        return $previous;
    }

    private function open(string $file): ZipArchive
    {
        $zip = new ZipArchive;
        if (! is_file($file) || $zip->open($file, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException("Cannot open {$file}.");
        }

        return $zip;
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($directory);
    }
}
