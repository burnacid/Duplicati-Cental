<?php

namespace App\Services;

use App\Models\BackupJobSetting;
use App\Models\BackupResult;
use Carbon\CarbonInterval;
use Illuminate\Support\Collection;
use Illuminate\Support\Number;

/**
 * Per-job alert rules (BackupJobSetting), checked when a backup reports in:
 * unusual size change, unusually long run, nothing changed for N runs, destination
 * almost full. Rules only fire on the run that crosses the line, so a lasting
 * condition is reported once. Failed runs are skipped: they already get an alert.
 */
class BackupAlertRuleService
{
    /** Previous runs used for the usual duration. */
    private const DURATION_SAMPLE = 10;

    /** Runs shorter than this are too noisy to compare. */
    private const MIN_DURATION_SECONDS = 60;

    /**
     * @return list<string> one message per rule that fired
     */
    public function check(BackupResult $result): array
    {
        if ($result->MainOperation !== 'Backup' || $result->status->isFailure()) {
            return [];
        }

        $setting = BackupJobSetting::query()
            ->where('backup_server_id', $result->backup_server_id)
            ->where('backup_id', $result->backup_id)
            ->first();

        if (! $setting?->hasAlertRules()) {
            return [];
        }

        $previous = $this->previousRuns($result, max(self::DURATION_SAMPLE, (int) $setting->alert_unchanged_runs));

        return array_values(array_filter([
            $setting->alert_size_change_percent ? $this->sizeChange($result, $previous->first(), $setting->alert_size_change_percent) : null,
            $setting->alert_duration_factor ? $this->duration($result, $previous, $setting->alert_duration_factor) : null,
            $setting->alert_unchanged_runs ? $this->unchanged($result, $previous, $setting->alert_unchanged_runs) : null,
            $setting->alert_quota_free_percent ? $this->quota($result, $previous->first(), $setting->alert_quota_free_percent) : null,
        ]));
    }

    private function sizeChange(BackupResult $result, ?BackupResult $previous, int $percent): ?string
    {
        $current = (int) $result->SizeOfExaminedFiles;
        $before = (int) $previous?->SizeOfExaminedFiles;

        if ($before <= 0 || $current <= 0) {
            return null;
        }

        $change = ($current - $before) / $before * 100;
        if (abs($change) < $percent) {
            return null;
        }

        return sprintf('Source size changed by %s%d%% since the previous backup (%s → %s).',
            $change > 0 ? '+' : '−', (int) round(abs($change)), Number::fileSize($before, 1), Number::fileSize($current, 1));
    }

    private function duration(BackupResult $result, Collection $previous, float $factor): ?string
    {
        $seconds = $this->seconds($result);
        $usual = $previous->take(self::DURATION_SAMPLE)->map(fn ($r) => $this->seconds($r))->filter()->sort()->values();

        if ($seconds === null || $usual->count() < 3) {
            return null;
        }

        $median = (int) $usual->median();
        if ($median < self::MIN_DURATION_SECONDS || $seconds < $factor * $median) {
            return null;
        }

        return sprintf('The backup took %s, %s× the usual %s.',
            $this->humanDuration($seconds), rtrim(rtrim(number_format($seconds / $median, 1), '0'), '.'), $this->humanDuration($median));
    }

    private function unchanged(BackupResult $result, Collection $previous, int $runs): ?string
    {
        $streak = 0;
        foreach ($previous->prepend($result) as $run) {
            if (! $this->isUnchanged($run)) {
                break;
            }
            $streak++;
        }

        // Exactly at the limit: reported once per streak.
        return $streak === $runs
            ? sprintf('No files were added, changed or deleted in the last %d backups. Is the source still mounted?', $runs)
            : null;
    }

    private function quota(BackupResult $result, ?BackupResult $previous, int $percent): ?string
    {
        $free = $this->freePercent($result);
        if ($free === null || $free >= $percent) {
            return null;
        }

        $before = $previous ? $this->freePercent($previous) : null;
        if ($before !== null && $before < $percent) {
            return null; // already reported when it crossed the line
        }

        return sprintf('Only %s%% (%s) free on the backup destination.',
            number_format($free, $free < 10 ? 1 : 0), Number::fileSize((int) $result->BackendStatistics['FreeQuotaSpace'], 1));
    }

    /**
     * Earlier backups of the same job (not failed), newest first.
     *
     * @return Collection<int, BackupResult>
     */
    private function previousRuns(BackupResult $result, int $limit): Collection
    {
        return BackupResult::query()
            ->where('backup_server_id', $result->backup_server_id)
            ->where('backup_id', $result->backup_id)
            ->where('MainOperation', 'Backup')
            ->whereNotIn('ParsedResult', ['Error', 'Fatal'])
            ->where('id', '!=', $result->id)
            ->where('EndTime', '<', $result->getAttributes()['EndTime'])
            ->orderByDesc('EndTime')
            ->limit($limit)
            ->get(['id', 'BeginTime', 'EndTime', 'SizeOfExaminedFiles', 'AddedFiles', 'ModifiedFiles', 'DeletedFiles', 'BackendStatistics']);
    }

    private function isUnchanged(BackupResult $run): bool
    {
        if ($run->AddedFiles === null || $run->ModifiedFiles === null || $run->DeletedFiles === null) {
            return false;
        }

        return (int) $run->AddedFiles + (int) $run->ModifiedFiles + (int) $run->DeletedFiles === 0;
    }

    private function freePercent(BackupResult $run): ?float
    {
        $stats = (array) $run->BackendStatistics;
        $total = (float) ($stats['TotalQuotaSpace'] ?? 0);
        $free = $stats['FreeQuotaSpace'] ?? null;

        return $total > 0 && is_numeric($free) && $free >= 0 ? $free / $total * 100 : null;
    }

    private function seconds(BackupResult $run): ?int
    {
        if (! $run->BeginTime || ! $run->EndTime) {
            return null;
        }

        $seconds = $run->EndTime->getTimestamp() - $run->BeginTime->getTimestamp();

        return $seconds > 0 ? $seconds : null;
    }

    private function humanDuration(int $seconds): string
    {
        return CarbonInterval::seconds($seconds)->cascade()->forHumans(['short' => true, 'parts' => 2]);
    }
}
