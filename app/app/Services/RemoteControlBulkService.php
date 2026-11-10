<?php

namespace App\Services;

use App\Models\RemoteControlRegistration;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Actions across all of a user's connected instances.
 */
class RemoteControlBulkService
{
    public const OPERATIONS = [
        'run' => '/api/v1/backup/%s/run?skipQueue=false',
        'repair' => '/api/v1/backup/%s/repair',
    ];

    public function __construct(private BackupHealthService $health, private RemoteControlCommandService $commands) {}

    /**
     * Jobs whose last run failed, on connected instances linked to their backup server.
     * Snoozed jobs are skipped.
     *
     * @return Collection<int, array{registration: RemoteControlRegistration, backup_id: string, name: string}>
     */
    public function failedJobs(User $user): Collection
    {
        $registrations = RemoteControlRegistration::query()
            ->with('agentState')
            ->where('claimed_by_user_id', $user->id)
            ->where('status', RemoteControlRegistration::STATUS_CLAIMED)
            ->whereNotNull('backup_server_id')
            ->get()
            ->filter(fn (RemoteControlRegistration $r) => $r->agentState?->is_connected)
            ->keyBy('backup_server_id');

        if ($registrations->isEmpty()) {
            return collect();
        }

        return $this->health->jobs($user)
            ->filter(fn (array $job) => $job['last_status']->isFailure() && ! $job['is_muted'] && $registrations->has($job['server_id']))
            ->map(fn (array $job) => [
                'registration' => $registrations[$job['server_id']],
                // Reports use "DB-<id>", Duplicati's API the plain id.
                'backup_id' => preg_replace('/^DB-/', '', (string) $job['backup_id']),
                'name' => $job['server_name'].' - '.$job['backup_name'],
            ])
            ->filter(fn (array $job) => $job['backup_id'] !== '')
            ->values();
    }

    /**
     * Start an operation for every failed job. Returns how many were sent.
     */
    public function runOnFailedJobs(User $user, string $operation): int
    {
        if (! isset(self::OPERATIONS[$operation])) {
            return 0;
        }

        $jobs = $this->failedJobs($user);
        foreach ($jobs as $job) {
            $this->commands->dispatch(
                $job['registration'],
                'POST',
                sprintf(self::OPERATIONS[$operation], rawurlencode($job['backup_id'])),
                requestedByUserId: $user->id,
            );
        }

        return $jobs->count();
    }
}
