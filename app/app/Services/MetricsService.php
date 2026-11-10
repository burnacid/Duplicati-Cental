<?php

namespace App\Services;

use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;

/**
 * Prometheus text exposition (version 0.0.4) of a user's backup jobs.
 * One series per job, labelled server / job / backup_id.
 */
class MetricsService
{
    private const PREFIX = 'duplicati_central_';

    public function __construct(private BackupHealthService $health) {}

    public function render(User $user): string
    {
        $jobs = $this->health->jobs($user);
        $serverIds = BackupServer::ownedBy($user)->pluck('id')->all();
        $utc = new DateTimeZone('UTC');

        // Last successful (or warning) backup per job.
        $lastSuccess = $serverIds === [] ? collect() : DB::table('backup_results')
            ->whereIn('backup_server_id', $serverIds)
            ->where('MainOperation', 'Backup')
            ->whereIn('ParsedResult', ['Success', 'Warning'])
            ->groupBy('backup_server_id', 'backup_id')
            ->get(['backup_server_id', 'backup_id', DB::raw('MAX(EndTime) as last_success')])
            ->keyBy(fn ($row) => $row->backup_server_id.'|'.$row->backup_id);

        $lastResults = BackupResult::query()
            ->whereIn('id', $jobs->map(fn ($j) => $j['last_result']->id)->all())
            ->get(['id', 'BeginTime', 'EndTime', 'SizeOfExaminedFiles', 'BackendStatistics'])
            ->keyBy('id');

        $metrics = [
            'job_last_success_timestamp_seconds' => ['gauge', 'Unix time of the last successful backup (warnings count as success).', []],
            'job_last_run_timestamp_seconds' => ['gauge', 'Unix time of the last reported operation.', []],
            'job_status' => ['gauge', 'Status of the last run: 0 success, 1 warning, 2 error, 3 unknown.', []],
            'job_failing' => ['gauge', '1 when the last run failed (Error or Fatal).', []],
            'job_overdue' => ['gauge', '1 when the job missed its schedule.', []],
            'job_snoozed' => ['gauge', '1 while alerts for the job are snoozed.', []],
            'job_last_duration_seconds' => ['gauge', 'Duration of the last run.', []],
            'job_source_size_bytes' => ['gauge', 'Size of the source files in the last run.', []],
            'job_backup_size_bytes' => ['gauge', 'Size of the backup at the destination.', []],
            'job_backup_versions' => ['gauge', 'Number of backup versions at the destination.', []],
            'job_destination_free_bytes' => ['gauge', 'Free space reported by the destination.', []],
        ];

        foreach ($jobs as $job) {
            $labels = ['server' => $job['server_name'], 'job' => (string) $job['backup_name'], 'backup_id' => (string) $job['backup_id']];
            $key = $job['server_id'].'|'.$job['backup_id'];
            $last = $lastResults[$job['last_result']->id] ?? null;
            $stats = (array) $last?->BackendStatistics;

            if (isset($lastSuccess[$key])) {
                $metrics['job_last_success_timestamp_seconds'][2][] = [$labels, (new DateTimeImmutable($lastSuccess[$key]->last_success, $utc))->getTimestamp()];
            }
            if ($last?->EndTime) {
                $metrics['job_last_run_timestamp_seconds'][2][] = [$labels, $last->EndTime->getTimestamp()];
            }
            $metrics['job_status'][2][] = [$labels, match (true) {
                $job['last_status']->isFailure() => 2,
                $job['last_status']->value === 'Warning' => 1,
                $job['last_status']->value === 'Success' => 0,
                default => 3,
            }];
            $metrics['job_failing'][2][] = [$labels, $job['last_status']->isFailure() ? 1 : 0];
            $metrics['job_overdue'][2][] = [$labels, $job['is_overdue'] ? 1 : 0];
            $metrics['job_snoozed'][2][] = [$labels, $job['is_muted'] ? 1 : 0];
            if ($last?->BeginTime && $last->EndTime) {
                $metrics['job_last_duration_seconds'][2][] = [$labels, max(0, $last->EndTime->getTimestamp() - $last->BeginTime->getTimestamp())];
            }
            foreach ([
                'job_source_size_bytes' => $last?->SizeOfExaminedFiles,
                'job_backup_size_bytes' => $stats['KnownFileSize'] ?? null,
                'job_backup_versions' => $stats['BackupListCount'] ?? null,
                'job_destination_free_bytes' => isset($stats['FreeQuotaSpace']) && $stats['FreeQuotaSpace'] >= 0 ? $stats['FreeQuotaSpace'] : null,
            ] as $name => $value) {
                if (is_numeric($value)) {
                    $metrics[$name][2][] = [$labels, $value];
                }
            }
        }

        $lines = [
            '# HELP '.self::PREFIX.'info Duplicati Central version.',
            '# TYPE '.self::PREFIX.'info gauge',
            self::PREFIX.'info'.$this->labels(['version' => (string) config('app.version')]).' 1',
            '# HELP '.self::PREFIX.'jobs Number of backup jobs.',
            '# TYPE '.self::PREFIX.'jobs gauge',
            self::PREFIX.'jobs '.$jobs->count(),
        ];

        foreach ($metrics as $name => [$type, $help, $samples]) {
            $lines[] = '# HELP '.self::PREFIX.$name.' '.$help;
            $lines[] = '# TYPE '.self::PREFIX.$name.' '.$type;
            foreach ($samples as [$labels, $value]) {
                $lines[] = self::PREFIX.$name.$this->labels($labels).' '.$this->number($value);
            }
        }

        return implode("\n", $lines)."\n";
    }

    /** {a="b",...} with Prometheus escaping (backslash, quote, newline). */
    private function labels(array $labels): string
    {
        $pairs = [];
        foreach ($labels as $key => $value) {
            $pairs[] = $key.'="'.str_replace(['\\', '"', "\n"], ['\\\\', '\\"', '\\n'], $value).'"';
        }

        return '{'.implode(',', $pairs).'}';
    }

    private function number(int|float|string $value): string
    {
        $value = $value + 0;

        return is_float($value) && floor($value) !== $value ? (string) $value : (string) (int) $value;
    }
}
