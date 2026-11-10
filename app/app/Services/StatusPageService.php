<?php

namespace App\Services;

use App\Models\BackupJobSetting;
use App\Models\BackupServer;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Data for a user's public status page: only jobs marked "show on status page",
 * and only their public name, state and backup times. No servers, sizes, paths or logs.
 */
class StatusPageService
{
    public const OPERATIONAL = 'operational';

    public const DEGRADED = 'degraded';

    public const DOWN = 'down';

    public function __construct(private BackupHealthService $health) {}

    /**
     * @return Collection<int, array{name: string, state: string, last_backup_at: ?CarbonInterface, recent: list<string>}>
     */
    public function jobs(User $user): Collection
    {
        $visible = BackupJobSetting::query()
            ->whereIn('backup_server_id', BackupServer::ownedBy($user)->select('id'))
            ->where('on_status_page', true)
            ->get()
            ->keyBy(fn (BackupJobSetting $s) => $s->backup_server_id.'|'.$s->backup_id);

        if ($visible->isEmpty()) {
            return collect();
        }

        return $this->health->jobs($user)
            ->filter(fn (array $job) => $visible->has($job['server_id'].'|'.$job['backup_id']))
            ->map(function (array $job) use ($visible) {
                $setting = $visible[$job['server_id'].'|'.$job['backup_id']];

                return [
                    'name' => $setting->status_page_name ?: (string) $job['backup_name'],
                    'state' => match (true) {
                        $job['last_status']->isFailure() || $job['is_overdue'] => self::DOWN,
                        $job['last_status']->value === 'Success' => self::OPERATIONAL,
                        default => self::DEGRADED,
                    },
                    'last_backup_at' => $job['last_backup_at'],
                    'recent' => array_map(fn ($r) => $r['status']->color(), $job['recent']),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();
    }

    public function overall(Collection $jobs): string
    {
        return match (true) {
            $jobs->contains('state', self::DOWN) => self::DOWN,
            $jobs->contains('state', self::DEGRADED) => self::DEGRADED,
            default => self::OPERATIONAL,
        };
    }
}
