<?php

namespace App\Support;

use App\Models\BackupServer;
use Illuminate\Support\Carbon;

/**
 * Snooze durations offered in the UI for servers and backup jobs.
 */
class Snooze
{
    public const OPTIONS = [
        '1h' => '1 hour',
        '4h' => '4 hours',
        '1d' => '1 day',
        '1w' => '1 week',
        'forever' => 'Until I resume',
    ];

    public static function until(string $duration): Carbon
    {
        return match ($duration) {
            '1h' => now()->addHour(),
            '4h' => now()->addHours(4),
            '1d' => now()->addDay(),
            '1w' => now()->addWeek(),
            'forever' => now()->addYears(BackupServer::MUTE_INDEFINITELY_YEARS),
            default => throw new \InvalidArgumentException("Unknown snooze duration: {$duration}"),
        };
    }

    /** Short label for a snooze end, e.g. "until 14:30", "until Tue 12 Nov 08:00" or "until resumed". */
    public static function label(?Carbon $until): ?string
    {
        if ($until === null || ! $until->isFuture()) {
            return null;
        }

        if ($until->greaterThan(now()->addYears(10))) {
            return 'until resumed';
        }

        $local = $until->copy()->setTimezone(config('app.timezone'));

        return 'until ' . ($local->isToday() ? $local->format('H:i') : $local->format('D j M H:i'));
    }
}
