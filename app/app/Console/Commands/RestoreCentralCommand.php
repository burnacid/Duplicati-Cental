<?php

namespace App\Console\Commands;

use App\Services\CentralBackupService;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;

/**
 * Restores a backup made with app:backup, then brings the schema up to date:
 *
 *   docker cp duplicati-central-20261110-030000.zip duplicati-central:/tmp/
 *   docker exec -it duplicati-central php artisan app:restore /tmp/duplicati-central-20261110-030000.zip
 *   docker restart duplicati-central
 */
class RestoreCentralCommand extends Command
{
    protected $signature = 'app:restore
        {file : Backup file made with app:backup}
        {--password= : Password of an encrypted backup (asked for when omitted)}
        {--force : Do not ask for confirmation}';

    protected $description = 'Restore a Duplicati Central backup (replaces the database and keys)';

    public function handle(CentralBackupService $backups): int
    {
        $file = (string) $this->argument('file');

        try {
            $manifest = $backups->inspect($file);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf(
            'Backup from %s, Duplicati Central v%s, %s.',
            $manifest['created_at'] ?? '?',
            $manifest['app_version'] ?? '?',
            ($manifest['database_included'] ?? false) ? 'with database' : 'without database',
        ));

        if (version_compare((string) ($manifest['app_version'] ?? '0'), (string) config('app.version'), '>')) {
            $this->warn('This backup was made by a newer version. Upgrade Duplicati Central first for the best result.');
        }

        if (! $this->option('force') && ! $this->confirm('This replaces the current database, APP_KEY and remote-control keys. Continue?')) {
            $this->line('Nothing changed.');

            return self::FAILURE;
        }

        $password = $this->option('password');
        if (($manifest['encrypted'] ?? false) && ($password === null || $password === '')) {
            if (! $this->input->isInteractive()) {
                $this->error('This backup is password protected; pass --password.');

                return self::FAILURE;
            }
            $password = password('Backup password', required: true);
        }

        try {
            $result = $backups->restore($file, $password);
        } catch (\Throwable $e) {
            $this->error('Restore failed, nothing was changed: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($result['database_restored']) {
            $this->info('Database restored.'.($result['previous_database'] ? " The previous one is kept as {$result['previous_database']}." : ''));
            $this->call('migrate', ['--force' => true]);
        } else {
            $this->warn('The backup has no database (it was not SQLite). Restore the database with its own tools.');
        }

        $this->info('APP_KEY and remote-control keys restored.');

        if ($result['app_key_changed']) {
            // Docker reads storage/.app_key on start unless APP_KEY is set explicitly.
            $this->warn('The backup has a different APP_KEY (now in storage/.app_key). If you set APP_KEY yourself (docker-compose, .env), replace it with that key or remove it.');
        }

        $this->newLine();
        $this->warn('Restart Duplicati Central now (e.g. docker restart duplicati-central) so every process uses the restored data.');

        return self::SUCCESS;
    }
}
