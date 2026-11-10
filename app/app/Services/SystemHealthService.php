<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

/**
 * Status of the processes and resources Duplicati Central depends on.
 * The scheduler, queue worker and websocket server report in with beat();
 * a component counts as down when its last heartbeat is too old.
 */
class SystemHealthService
{
    public const SCHEDULER = 'scheduler';

    public const QUEUE = 'queue';

    public const WEBSOCKET = 'websocket';

    /** Seconds without a heartbeat before a component counts as down. */
    private const STALE_AFTER = [
        self::SCHEDULER => 180,  // beats every minute
        self::QUEUE => 900,      // heartbeat job queued every 5 minutes
        self::WEBSOCKET => 120,  // beats every 30 seconds
    ];

    private const STATUS_RANK = ['off' => 0, 'ok' => 1, 'warning' => 2, 'error' => 3];

    public function __construct(private CentralBackupService $backups) {}

    public function beat(string $component): void
    {
        Cache::forever($this->key($component), now()->getTimestamp());
    }

    public function lastBeat(string $component): ?CarbonImmutable
    {
        $timestamp = Cache::get($this->key($component));

        return is_numeric($timestamp) ? CarbonImmutable::createFromTimestamp((int) $timestamp) : null;
    }

    /**
     * @return list<array{key: string, label: string, status: string, summary: string, detail: ?string}>
     */
    public function checks(): array
    {
        return [
            $this->versionCheck(),
            $this->databaseCheck(),
            $this->schedulerCheck(),
            $this->queueCheck(),
            $this->websocketCheck(),
            $this->storageCheck(),
            $this->centralBackupCheck(),
        ];
    }

    /**
     * Worst status of the given checks ('off' counts as fine).
     */
    public function overall(array $checks): string
    {
        $worst = 'ok';
        foreach ($checks as $check) {
            if (self::STATUS_RANK[$check['status']] > self::STATUS_RANK[$worst]) {
                $worst = $check['status'];
            }
        }

        return $worst;
    }

    private function versionCheck(): array
    {
        return $this->check('version', __('Version'), 'ok',
            'v'.config('app.version'),
            sprintf('PHP %s · Laravel %s · %s', PHP_VERSION, app()->version(), config('app.timezone')),
        );
    }

    private function databaseCheck(): array
    {
        try {
            $connection = DB::connection();
            $connection->getPdo();
        } catch (\Throwable $e) {
            return $this->check('database', __('Database'), 'error', __('Cannot connect'), $e->getMessage());
        }

        $driver = $connection->getDriverName();
        $detail = $driver;
        if ($driver === 'sqlite') {
            $file = $connection->getConfig('database');
            if (is_string($file) && is_file($file)) {
                $detail .= ' · '.Number::fileSize((int) filesize($file), precision: 1);
            }
        }

        $migrator = app('migrator');
        $pending = $migrator->repositoryExists()
            ? count(array_diff(
                array_keys($migrator->getMigrationFiles(database_path('migrations'))),
                $migrator->getRepository()->getRan(),
            ))
            : null;

        if ($pending === null || $pending > 0) {
            return $this->check('database', __('Database'), 'warning',
                trans_choice(':count migration pending|:count migrations pending', $pending ?? 0),
                __('Run php artisan migrate (the Docker image does this on start).').' '.$detail,
            );
        }

        return $this->check('database', __('Database'), 'ok', __('Connected, schema up to date'), $detail);
    }

    private function schedulerCheck(): array
    {
        return $this->heartbeatCheck(self::SCHEDULER, __('Scheduler'),
            __('Overdue checks, summaries and clean-up do not run. Is php artisan schedule:work running?'));
    }

    private function queueCheck(): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync') {
            return $this->check('queue', __('Queue worker'), 'ok', __('Jobs run immediately (no worker needed)'), 'QUEUE_CONNECTION=sync');
        }

        $check = $this->heartbeatCheck(self::QUEUE, __('Queue worker'),
            __('Notifications and history imports are not sent. Is php artisan queue:work running?'));

        $counts = [];
        try {
            if (config("queue.connections.{$connection}.driver") === 'database') {
                $table = config("queue.connections.{$connection}.table", 'jobs');
                $counts[] = trans_choice(':count job waiting|:count jobs waiting', DB::table($table)->count());
            }

            $failed = DB::table(config('queue.failed.table', 'failed_jobs'))->count();
            $counts[] = trans_choice(':count failed job|:count failed jobs', $failed);

            if ($failed > 0 && $check['status'] === 'ok') {
                $check['status'] = 'warning';
            }
        } catch (\Throwable) {
            // Tables missing (pending migrations): the database check reports that.
        }

        if ($counts !== []) {
            $check['detail'] = trim(implode(' · ', $counts).' · '.$check['detail'], ' ·');
        }

        return $check;
    }

    private function websocketCheck(): array
    {
        if (! config('remote_control.enabled')) {
            return $this->check('websocket', __('Remote-control server'), 'off', __('Remote control is turned off'), 'REMOTE_CONTROL_ENABLED=false');
        }

        return $this->heartbeatCheck(self::WEBSOCKET, __('Remote-control server'),
            __('Duplicati instances cannot connect. Is php artisan remote-control:serve running?'));
    }

    private function storageCheck(): array
    {
        $path = storage_path();

        if (! is_writable($path)) {
            return $this->check('storage', __('Storage'), 'error', __('Storage folder is not writable'), null);
        }

        $free = @disk_free_space($path);
        if ($free === false) {
            return $this->check('storage', __('Storage'), 'ok', __('Writable'), null);
        }

        $summary = __(':size free', ['size' => Number::fileSize($free, precision: 1)]);
        $status = match (true) {
            $free < 100 * 1024 ** 2 => 'error',
            $free < 1024 ** 3 => 'warning',
            default => 'ok',
        };

        return $this->check('storage', __('Storage'), $status, $summary, $status === 'ok' ? null : __('Free up disk space for the database and logs.'));
    }

    private function centralBackupCheck(): array
    {
        $latest = $this->backups->latest();
        $scheduled = (bool) config('backups.central.enabled');

        if ($latest === null) {
            return $this->check('central-backup', __('Backup of Central'), $scheduled ? 'warning' : 'off',
                __('No backup made yet'),
                $scheduled ? __('Scheduled daily at :time.', ['time' => config('backups.central.time')]) : __('Run php artisan app:backup, or set CENTRAL_BACKUP_ENABLED=true.'),
            );
        }

        $stale = $scheduled && $latest['created_at']->lt(now()->subHours(48));

        return $this->check('central-backup', __('Backup of Central'), $stale ? 'warning' : 'ok',
            __('Last backup :time', ['time' => $latest['created_at']->diffForHumans()]),
            $latest['name'].' · '.Number::fileSize($latest['size'], precision: 1).($scheduled ? '' : ' · '.__('not scheduled')),
        );
    }

    private function heartbeatCheck(string $component, string $label, string $hint): array
    {
        $last = $this->lastBeat($component);

        if ($last === null) {
            return $this->check($component, $label, 'warning', __('Not seen yet'), $hint);
        }

        if ($last->lt(now()->subSeconds(self::STALE_AFTER[$component]))) {
            return $this->check($component, $label, 'error', __('Last seen :time', ['time' => $last->diffForHumans()]), $hint);
        }

        return $this->check($component, $label, 'ok', __('Running'), __('Last seen :time', ['time' => $last->diffForHumans()]));
    }

    private function check(string $key, string $label, string $status, string $summary, ?string $detail): array
    {
        return compact('key', 'label', 'status', 'summary', 'detail');
    }

    private function key(string $component): string
    {
        return "health:heartbeat:{$component}";
    }
}
