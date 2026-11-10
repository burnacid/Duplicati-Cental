<?php

namespace App\Console\Commands;

use App\Services\CentralBackupService;
use Illuminate\Console\Command;
use Illuminate\Support\Number;

/**
 * Backup of Duplicati Central itself (database, APP_KEY, remote-control keys):
 *
 *   docker exec duplicati-central php artisan app:backup
 *   docker cp duplicati-central:/app/storage/backups ./central-backups
 *
 * Runs daily when CENTRAL_BACKUP_ENABLED=true. Restore with app:restore.
 */
class BackupCentralCommand extends Command
{
    protected $signature = 'app:backup
        {--keep= : Number of backups to keep (default CENTRAL_BACKUP_KEEP, 0 = all)}
        {--password= : Encrypt the backup with this password (default CENTRAL_BACKUP_PASSWORD)}';

    protected $description = 'Back up the Duplicati Central database, encryption key and remote-control keys';

    public function handle(CentralBackupService $backups): int
    {
        $password = $this->option('password') ?? config('backups.central.password');
        $keep = $this->option('keep') !== null ? (int) $this->option('keep') : (int) config('backups.central.keep');

        try {
            $backup = $backups->create($password);
        } catch (\Throwable $e) {
            $this->error('Backup failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Backup written to %s (%s).', $backup['path'], Number::fileSize($backup['size'], precision: 1)));

        if (! $backup['database_included']) {
            $this->warn('The database is not SQLite and is not included. Back it up with its own tools (e.g. mysqldump).');
        }

        if ($password === null || $password === '') {
            $this->warn('The backup is not encrypted and contains your APP_KEY and private keys. Store it somewhere safe, or use --password.');
        }

        $deleted = $backups->prune($keep);
        if ($deleted > 0) {
            $this->line("Removed {$deleted} older backup(s); keeping {$keep}.");
        }

        return self::SUCCESS;
    }
}
