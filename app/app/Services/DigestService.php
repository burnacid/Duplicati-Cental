<?php

namespace App\Services;

use App\Enums\BackupStatus;
use App\Livewire\Concerns\FormatsBackupValues;
use App\Models\BackupResult;
use App\Models\NotificationChannel;
use App\Models\User;
use App\Support\Snooze;

/**
 * Builds the daily / weekly summary message for a user.
 */
class DigestService
{
    use FormatsBackupValues;

    public function __construct(private BackupHealthService $health) {}

    /**
     * @return array{subject: string, message: string, context: array}|null null when the user has no backup jobs
     */
    public function build(User $user, string $frequency): ?array
    {
        $this->health->flush();
        $jobs = $this->health->jobs($user);
        if ($jobs->isEmpty()) {
            return null;
        }

        $weekly = $frequency === NotificationChannel::DIGEST_WEEKLY;
        $since = $weekly ? now()->subWeek() : now()->subDay();
        $period = $weekly ? 'the last 7 days' : 'the last 24 hours';

        $counts = BackupResult::forUser($user)
            ->where('EndTime', '>=', $since->copy()->utc()->format('Y-m-d H:i:s'))
            ->selectRaw('ParsedResult, COUNT(*) as total')
            ->groupBy('ParsedResult')
            ->pluck('total', 'ParsedResult');
        $runs = (int) $counts->sum();
        $failed = (int) (($counts['Error'] ?? 0) + ($counts['Fatal'] ?? 0));
        $warnings = (int) ($counts['Warning'] ?? 0);
        $successful = (int) ($counts['Success'] ?? 0);

        $active = $jobs->reject(fn ($job) => $job['is_muted']);
        $attention = $active->filter(fn ($job) => $job['last_status']->severity() >= BackupStatus::Warning->severity());
        $overdue = $active->filter(fn ($job) => $job['is_overdue']);
        $snoozed = $jobs->filter(fn ($job) => $job['is_muted']);
        $stored = $jobs->sum(fn ($job) => (int) ($job['last_result']->BackendStatistics['KnownFileSize'] ?? 0));

        $line = fn ($job) => sprintf(
            '- %s / %s: %s, last backup %s',
            $job['server_name'],
            $job['backup_name'],
            $job['last_status']->label(),
            $job['last_backup_at']?->diffForHumans() ?? 'never',
        );

        $lines = [sprintf(
            'Backups in %s: %d %s, %d successful, %d with warnings, %d failed.',
            $period, $runs, $runs === 1 ? 'run' : 'runs', $successful, $warnings, $failed,
        )];

        if ($attention->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "Last run not successful ({$attention->count()}):";
            $attention->each(function ($job) use (&$lines, $line) {
                $lines[] = $line($job);
            });
        }
        if ($overdue->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "Overdue ({$overdue->count()}):";
            $overdue->each(function ($job) use (&$lines, $line) {
                $lines[] = $line($job);
            });
        }
        if ($snoozed->isNotEmpty()) {
            $lines[] = '';
            $lines[] = "Snoozed ({$snoozed->count()}):";
            $snoozed->each(function ($job) use (&$lines) {
                $lines[] = sprintf('- %s / %s, %s', $job['server_name'], $job['backup_name'], Snooze::label($job['muted_until']));
            });
        }

        $lines[] = '';
        $lines[] = sprintf('%d backup %s, %s stored at the destinations.', $jobs->count(), $jobs->count() === 1 ? 'job' : 'jobs', $this->formatSize($stored));
        $lines[] = route('home');

        $problems = array_filter([
            $attention->count() ? $attention->count().' need attention' : null,
            $overdue->count() ? $overdue->count().' overdue' : null,
        ]);

        return [
            'subject' => sprintf('[Summary] %s backup summary: %s', $weekly ? 'Weekly' : 'Daily', $problems ? implode(', ', $problems) : 'all OK'),
            'message' => implode("\n", $lines),
            'context' => [
                'event' => 'digest',
                'frequency' => $frequency,
                'runs' => $runs,
                'successful' => $successful,
                'warnings' => $warnings,
                'failed' => $failed,
                'jobs' => $jobs->count(),
                'attention' => $attention->count(),
                'overdue' => $overdue->count(),
                'snoozed' => $snoozed->count(),
                'stored_bytes' => $stored,
                'url' => route('home'),
            ],
        ];
    }
}
