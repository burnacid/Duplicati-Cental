<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Reads the job configuration returned by Duplicati's GET /api/v1/backup/{id}
 * ({Schedule, Backup, DisplayNames}) and builds the PUT body for a schedule change.
 * Duplicati masks passwords in the GET response and restores them on PUT.
 */
class DuplicatiJobConfig
{
    public const WEEKDAYS = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];

    /** Repeat units Duplicati's Timeparser understands, with labels. */
    public const REPEAT_UNITS = ['m' => 'minutes', 'h' => 'hours', 'D' => 'days', 'W' => 'weeks', 'M' => 'months'];

    /** Settings shown as "retention". */
    private const RETENTION_SETTINGS = ['keep-time' => 'Keep for', 'keep-versions' => 'Keep versions', 'retention-policy' => 'Retention policy'];

    /**
     * Readable summary for the job details panel.
     *
     * @return array{
     *     name: string, description: ?string, sources: list<string>,
     *     destination: array{type: string, location: string},
     *     schedule: ?array{repeat: string, next_run: ?CarbonImmutable, last_run: ?CarbonImmutable, days: list<string>},
     *     retention: array<string, string>, encrypted: bool, volume_size: ?string, filters: int, settings: int
     * }
     */
    public static function summarize(array $config): array
    {
        $backup = (array) ($config['Backup'] ?? []);
        $settings = self::settings($backup);
        $displayNames = (array) ($config['DisplayNames'] ?? []);

        $retention = [];
        foreach (self::RETENTION_SETTINGS as $key => $label) {
            if (filled($settings[$key] ?? null)) {
                $retention[$label] = (string) $settings[$key];
            }
        }

        return [
            'name' => (string) ($backup['Name'] ?? ''),
            'description' => filled($backup['Description'] ?? null) ? (string) $backup['Description'] : null,
            'sources' => array_values(array_map(
                fn ($source) => (string) ($displayNames[$source] ?? $source),
                array_filter((array) ($backup['Sources'] ?? []), 'is_string'),
            )),
            'destination' => self::destination((string) ($backup['TargetURL'] ?? '')),
            'schedule' => self::schedule($config['Schedule'] ?? null),
            'retention' => $retention,
            'encrypted' => ! self::truthy($settings['no-encryption'] ?? null) && filled($settings['passphrase'] ?? ($settings['encryption-module'] ?? null)),
            'volume_size' => filled($settings['dblock-size'] ?? null) ? (string) $settings['dblock-size'] : null,
            'filters' => count((array) ($backup['Filters'] ?? [])),
            'settings' => count($settings),
        ];
    }

    /**
     * Editable schedule values: enabled, every N unit, time of day (app timezone), allowed days.
     *
     * @return array{enabled: bool, every: int, unit: string, time: string, days: list<string>}
     */
    public static function scheduleForm(array $config): array
    {
        $schedule = $config['Schedule'] ?? null;
        if (! is_array($schedule)) {
            return ['enabled' => false, 'every' => 1, 'unit' => 'D', 'time' => '02:00', 'days' => []];
        }

        [$every, $unit] = self::parseRepeat((string) ($schedule['Repeat'] ?? '1D'));
        $time = self::date($schedule['Time'] ?? null);

        return [
            'enabled' => true,
            'every' => $every,
            'unit' => $unit,
            'time' => $time ? $time->setTimezone(config('app.timezone'))->format('H:i') : '02:00',
            'days' => array_values(array_intersect(self::WEEKDAYS, array_map('strval', (array) ($schedule['AllowedDays'] ?? [])))),
        ];
    }

    /**
     * Body for PUT /api/v1/backup/{id}: the job as Duplicati returned it, with a new schedule
     * (null removes the schedule). The first run is the next occurrence of $time (app timezone).
     */
    public static function withSchedule(array $config, bool $enabled, int $every, string $unit, string $time, array $days): array
    {
        $backup = (array) ($config['Backup'] ?? []);

        $body = ['Backup' => [
            'Name' => $backup['Name'] ?? '',
            'Description' => $backup['Description'] ?? '',
            'Tags' => $backup['Tags'] ?? [],
            'TargetURL' => $backup['TargetURL'] ?? '',
            'ConnectionStringID' => $backup['ConnectionStringID'] ?? -1,
            'Sources' => $backup['Sources'] ?? [],
            'Settings' => array_map(fn ($s) => ['Name' => $s['Name'] ?? '', 'Value' => $s['Value'] ?? null, 'Filter' => $s['Filter'] ?? null], (array) ($backup['Settings'] ?? [])),
            'Filters' => array_map(fn ($f) => ['Order' => $f['Order'] ?? 0, 'Include' => (bool) ($f['Include'] ?? false), 'Expression' => $f['Expression'] ?? null], (array) ($backup['Filters'] ?? [])),
            'Metadata' => $backup['Metadata'] ?? null,
            'AdditionalTargetURLs' => array_map(fn ($t) => [
                'UrlKey' => $t['UrlKey'] ?? null,
                'TargetUrl' => $t['TargetUrl'] ?? '',
                'Mode' => $t['Mode'] ?? null,
                'Interval' => $t['Interval'] ?? null,
                'ConnectionStringID' => $t['ConnectionStringID'] ?? -1,
                'Options' => $t['Options'] ?? null,
            ], (array) ($backup['AdditionalTargetURLs'] ?? [])),
        ]];

        if (! $enabled) {
            $body['Schedule'] = null;

            return $body;
        }

        $existing = is_array($config['Schedule'] ?? null) ? $config['Schedule'] : [];
        [$hour, $minute] = array_map('intval', explode(':', $time) + [1 => 0]);
        $first = CarbonImmutable::now(config('app.timezone'))->setTime($hour, $minute);
        if ($first->isPast()) {
            $first = $first->addDay();
        }

        $body['Schedule'] = [
            'ID' => $existing['ID'] ?? 0,
            'Tags' => $existing['Tags'] ?? ['ID='.($backup['ID'] ?? '')],
            'Time' => $first->utc()->format('Y-m-d\TH:i:s\Z'),
            'Repeat' => $every.$unit,
            'LastRun' => $existing['LastRun'] ?? null,
            'Rule' => $existing['Rule'] ?? '',
            'AllowedDays' => array_values(array_intersect(self::WEEKDAYS, $days)),
        ];

        return $body;
    }

    /** "1D" → "every day", "12h" → "every 12 hours". */
    public static function repeatLabel(string $repeat): string
    {
        [$every, $unit] = self::parseRepeat($repeat);
        $label = self::REPEAT_UNITS[$unit] ?? $unit;

        return $every === 1 ? 'every '.rtrim($label, 's') : "every {$every} {$label}";
    }

    /**
     * @return array{0: int, 1: string}
     */
    private static function parseRepeat(string $repeat): array
    {
        if (preg_match('/^\s*(\d+)\s*([mhDWMY])\s*$/', $repeat, $m)) {
            return $m[2] === 'Y' ? [(int) $m[1] * 12, 'M'] : [max(1, (int) $m[1]), $m[2]];
        }

        return [1, 'D'];
    }

    private static function schedule(mixed $schedule): ?array
    {
        if (! is_array($schedule)) {
            return null;
        }

        return [
            'repeat' => self::repeatLabel((string) ($schedule['Repeat'] ?? '1D')),
            'next_run' => self::date($schedule['Time'] ?? null),
            'last_run' => self::date($schedule['LastRun'] ?? null),
            'days' => array_values(array_intersect(self::WEEKDAYS, array_map('strval', (array) ($schedule['AllowedDays'] ?? [])))),
        ];
    }

    /**
     * Destination type and location, without credentials or query options.
     *
     * @return array{type: string, location: string}
     */
    public static function destination(string $url): array
    {
        if (! preg_match('#^([a-z0-9+.-]+)://(.*)$#i', $url, $m)) {
            return ['type' => 'unknown', 'location' => ''];
        }

        $rest = explode('?', $m[2], 2)[0];
        $rest = preg_replace('#^[^/@]*@#', '', $rest); // user:password@

        return ['type' => strtolower($m[1]), 'location' => $rest];
    }

    /**
     * @return array<string, mixed> setting name (without leading dashes) => value
     */
    private static function settings(array $backup): array
    {
        $settings = [];
        foreach ((array) ($backup['Settings'] ?? []) as $setting) {
            if (is_array($setting) && isset($setting['Name'])) {
                $settings[ltrim((string) $setting['Name'], '-')] = $setting['Value'] ?? null;
            }
        }

        return $settings;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0001-')) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->setTimezone(config('app.timezone'));
        } catch (\Throwable) {
            return null;
        }
    }

    private static function truthy(mixed $value): bool
    {
        return in_array(strtolower((string) $value), ['true', '1', 'yes', 'on'], true);
    }
}
