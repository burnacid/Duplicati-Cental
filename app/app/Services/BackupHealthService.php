<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use App\Models\BackupServer;
use App\Models\User;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Builds the dashboard overview for a user: per-job health and recent runs,
 * per-server day statuses, and overdue detection.
 *
 * Performance notes: recent runs are read as plain rows (no Eloquent models),
 * and the result is computed once per user per request (the service is a
 * singleton), so the dashboard components share it.
 */
class BackupHealthService
{
    public const HISTORY_DAYS = 60;

    public const RECENT_RUNS = 30;

    /** @var array<string, array{jobs: Collection, daily: array}> */
    private array $memo = [];

    /**
     * Every backup job of the user, with its health.
     *
     * @return Collection<int, array{
     *     server_id: string, server_name: string, backup_id: string, backup_name: string,
     *     last_result: BackupResult, last_status: BackupStatus, last_backup_at: ?Carbon,
     *     expected_interval: ?int, learned_interval: ?int, interval_source: string, is_overdue: bool,
     *     monitored: bool, muted_until: ?Carbon, is_muted: bool,
     *     recent: list<array{id: int, status: BackupStatus, title: string, url: string}>
     * }>
     */
    public function jobs(User|string $user): Collection
    {
        return $this->compute($user)['jobs'];
    }

    /**
     * Worst status per local calendar day, for the last $days days, per server.
     *
     * @return array<string, list<array{date: string, status: BackupStatus}>>
     */
    public function dailyStatusByServer(User|string $user, int $days = 30): array
    {
        return array_map(fn ($row) => array_slice($row, -$days), $this->compute($user)['daily']);
    }

    /** Forget memoized data (e.g. in long-running processes). */
    public function flush(): void
    {
        $this->memo = [];
    }

    private function compute(User|string $user): array
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $this->memo[$userId] ??= $this->build($userId);
    }

    private function build(string $userId): array
    {
        $servers = BackupServer::ownedBy($userId)->get(['id', 'name', 'muted_until'])->keyBy('id');
        if ($servers->isEmpty()) {
            return ['jobs' => collect(), 'daily' => []];
        }

        $serverIds = $servers->keys()->all();
        $timezone = new DateTimeZone(config('app.timezone'));
        $utc = new DateTimeZone('UTC');
        $detailUrl = route('backup-detail', ['id' => '__ID__']);
        $settings = BackupJobSetting::query()
            ->whereIn('backup_server_id', $serverIds)
            ->get()
            ->keyBy(fn (BackupJobSetting $s) => $s->backup_server_id.'|'.$s->backup_id);

        // Lightweight rows of the last HISTORY_DAYS days, oldest first.
        $rows = DB::table('backup_results')
            ->whereIn('backup_server_id', $serverIds)
            ->where('EndTime', '>=', now()->utc()->subDays(self::HISTORY_DAYS)->format('Y-m-d H:i:s'))
            ->orderBy('EndTime')
            ->get(['id', 'backup_server_id', 'backup_id', 'MainOperation', 'ParsedResult', 'EndTime']);

        $byJob = [];
        $byServerDay = [];
        foreach ($rows as $row) {
            $local = (new DateTimeImmutable($row->EndTime, $utc))->setTimezone($timezone);
            $status = BackupStatus::fromResult($row->ParsedResult);

            $byJob[$row->backup_server_id.'|'.$row->backup_id][] = [
                'id' => (int) $row->id,
                'status' => $status,
                'operation' => $row->MainOperation,
                'timestamp' => $local->getTimestamp(),
                'title' => $local->format('Y-m-d H:i').' — '.$row->MainOperation.' '.$row->ParsedResult,
            ];
            $day = $local->format('Y-m-d');
            $current = $byServerDay[$row->backup_server_id][$day] ?? BackupStatus::None;
            if ($status->severity() > $current->severity()) {
                $byServerDay[$row->backup_server_id][$day] = $status;
            }
        }

        $jobs = $this->latestResults($serverIds)->map(function (BackupResult $last) use ($servers, $byJob, $detailUrl, $settings) {
            $key = $last->backup_server_id.'|'.$last->backup_id;
            $history = $byJob[$key] ?? [];
            $setting = $settings[$key] ?? null;
            $server = $servers[$last->backup_server_id] ?? null;
            $backupTimes = array_column(array_filter($history, fn ($r) => $r['operation'] === 'Backup'), 'timestamp');

            $lastBackupAt = $backupTimes !== []
                ? Carbon::createFromTimestamp(end($backupTimes))->setTimezone(config('app.timezone'))
                : ($last->MainOperation === 'Backup' ? $last->EndTime : null);
            $learned = $this->expectedInterval(collect($backupTimes));
            $manual = $setting?->expected_interval_hours ? $setting->expected_interval_hours * 3600 : null;
            $monitored = $setting?->monitored ?? true;
            // Snoozed until the later of the server snooze and the job snooze.
            $mutedUntil = collect([
                $server?->isMuted() ? $server->muted_until : null,
                $setting?->isMuted() ? $setting->muted_until : null,
            ])->filter()->sortDesc()->first();

            return [
                'server_id' => $last->backup_server_id,
                'server_name' => $server->name ?? 'Unknown',
                'backup_id' => $last->backup_id,
                'backup_name' => $last->backup_name,
                'last_result' => $last,
                'last_status' => $last->status,
                'last_backup_at' => $lastBackupAt,
                'expected_interval' => $manual ?? $learned,
                'learned_interval' => $learned,
                'interval_source' => $manual ? 'manual' : ($learned ? 'learned' : 'fallback'),
                'is_overdue' => $monitored && ($manual
                    ? $this->isOverdueManual($lastBackupAt, $manual)
                    : $this->isOverdue($lastBackupAt, $learned)),
                'monitored' => $monitored,
                'muted_until' => $mutedUntil,
                'is_muted' => $mutedUntil !== null,
                'recent' => array_map(fn ($r) => [
                    'id' => $r['id'],
                    'status' => $r['status'],
                    'title' => $r['title'],
                    'url' => str_replace('__ID__', (string) $r['id'], $detailUrl),
                ], array_slice($history, -self::RECENT_RUNS)),
            ];
        })->sortBy(['server_name', 'backup_name'])->values();

        return ['jobs' => $jobs, 'daily' => $this->dailyRows($serverIds, $byServerDay, $timezone)];
    }

    /**
     * Latest result per job by EndTime (not by id: imported history can have
     * higher ids than newer runs). Ties are broken by the highest id.
     *
     * @return Collection<int, BackupResult>
     */
    private function latestResults(array $serverIds): Collection
    {
        $latestEnd = DB::table('backup_results')
            ->whereIn('backup_server_id', $serverIds)
            ->groupBy('backup_server_id', 'backup_id')
            ->select('backup_server_id', 'backup_id', DB::raw('MAX(EndTime) as max_end'));

        return BackupResult::query()
            ->joinSub($latestEnd, 'latest', function ($join) {
                $join->on('backup_results.backup_server_id', '=', 'latest.backup_server_id')
                    ->on('backup_results.backup_id', '=', 'latest.backup_id')
                    ->on('backup_results.EndTime', '=', 'latest.max_end');
            })
            ->orderByDesc('backup_results.id')
            ->get([
                'backup_results.id', 'backup_results.backup_server_id', 'backup_results.backup_id', 'backup_results.backup_name',
                'backup_results.MainOperation', 'backup_results.ParsedResult', 'backup_results.EndTime',
                'backup_results.Version', 'backup_results.BackendStatistics',
            ])
            ->unique(fn ($r) => $r->backup_server_id.'|'.$r->backup_id)
            ->values();
    }

    private function dailyRows(array $serverIds, array $byServerDay, DateTimeZone $timezone): array
    {
        $today = new DateTimeImmutable('today', $timezone);
        $output = [];

        foreach ($serverIds as $serverId) {
            $row = [];
            for ($i = self::HISTORY_DAYS - 1; $i >= 0; $i--) {
                $date = $today->modify("-{$i} days")->format('Y-m-d');
                $row[] = ['date' => $date, 'status' => $byServerDay[$serverId][$date] ?? BackupStatus::None];
            }
            $output[$serverId] = $row;
        }

        return $output;
    }

    /**
     * Median gap (in seconds) between successive backups, or null when there
     * is too little history to guess a schedule.
     *
     * @param  Collection<int, \DateTimeInterface|int>  $endTimes  ordered oldest first
     */
    public function expectedInterval(Collection $endTimes): ?int
    {
        $times = $endTimes->map(fn ($t) => $t instanceof \DateTimeInterface ? $t->getTimestamp() : (int) $t)->values()->all();

        if (count($times) < 3) {
            return null;
        }

        $gaps = [];
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $gaps[] = abs($times[$i] - $times[$i - 1]);
        }

        sort($gaps);
        $middle = intdiv(count($gaps), 2);
        $median = count($gaps) % 2 ? $gaps[$middle] : ($gaps[$middle - 1] + $gaps[$middle]) / 2;

        return $median > 0 ? (int) $median : null;
    }

    /**
     * A job is overdue when it has missed roughly two scheduled runs
     * (plus a grace period), or, without a known schedule, after the fallback.
     */
    public function isOverdue(?Carbon $lastBackupAt, ?int $expectedInterval): bool
    {
        if ($lastBackupAt === null) {
            return false;
        }

        $grace = (int) config('backups.overdue_grace_minutes', 60) * 60;
        $allowed = $expectedInterval !== null
            ? ($expectedInterval * 2) + $grace
            : (int) config('backups.overdue_fallback_hours', 168) * 3600;

        return $lastBackupAt->diffInSeconds(now(), true) > $allowed;
    }

    /**
     * With a schedule set by hand the interval is known, so one missed run
     * (plus the grace period) is enough.
     */
    public function isOverdueManual(?Carbon $lastBackupAt, int $interval): bool
    {
        if ($lastBackupAt === null) {
            return false;
        }

        $grace = (int) config('backups.overdue_grace_minutes', 60) * 60;

        return $lastBackupAt->diffInSeconds(now(), true) > $interval + $grace;
    }
}
