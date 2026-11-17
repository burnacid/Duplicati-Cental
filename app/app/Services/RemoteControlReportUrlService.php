<?php

namespace App\Services;

use App\Models\EventLog;
use App\Models\RemoteControlRegistration;
use App\Support\DuplicatiJobConfig;
use RuntimeException;

/**
 * Keeps the reporting URL of remote-controlled instances correct.
 *
 * Central gives each instance a managed report URL (`reportingurl` in the updatesettings
 * control message). Duplicati stores it as AdditionalReportUrl and adds it to
 * send-http-json-urls for every job it runs, and reports it back on connect
 * (`feature:additional-report-url`). The URL contains the server's API key, so it goes
 * stale when the key is regenerated or the instance's address changes.
 *
 * Report URLs pointing at Central that were set by hand (default options or a job) are
 * removed: they are either wrong or duplicate the managed URL (every run stored twice).
 */
class RemoteControlReportUrlService
{
    /** Duplicati options that hold report URLs (without the leading dashes). */
    public const SEND_HTTP_OPTIONS = ['send-http-url', 'send-http-json-urls', 'send-http-form-urls'];

    /** A Central report endpoint on any host. */
    private const CENTRAL_ENDPOINT = '#^https?://\S+/api/backup-servers/[^/?\s]+/[^/?\s]+/backup-result/?(\?\S*)?$#i';

    public function __construct(
        private readonly RemoteControlUrlService $urls,
        private readonly RemoteControlSettingsService $settings,
        private readonly RemoteControlCommandService $commands,
        private readonly EventLogger $events,
    ) {}

    public function expectedUrl(RemoteControlRegistration $registration): ?string
    {
        $server = $registration->backupServer;

        return $server ? $this->urls->reportingUrlFor($registration, $server) : null;
    }

    /**
     * State is ok, pending (not confirmed by the instance yet), wrong or unlinked (no backup server).
     *
     * @return array{state: string, expected: ?string, managed: ?string, reported: ?string, applied: bool}
     */
    public function status(RemoteControlRegistration $registration): array
    {
        $settings = is_array($registration->settings) ? $registration->settings : [];
        $metadata = $registration->agentState?->metadata;
        $expected = $this->expectedUrl($registration);
        $managed = filled($settings['reportingurl'] ?? null) ? (string) $settings['reportingurl'] : null;
        $applied = (int) ($registration->agentState?->last_applied_settings_version ?? 0) >= (int) ($settings['settingsversion'] ?? 0);

        $state = match (true) {
            $expected === null => 'unlinked',
            $managed !== $expected => 'wrong',
            ! $applied => 'pending',
            default => 'ok',
        };

        return [
            'state' => $state,
            'expected' => $expected,
            'managed' => $managed,
            // As the instance reported it when it last connected.
            'reported' => is_array($metadata) && filled($metadata[RemoteControlAgentSyncService::REPORT_URL_METADATA] ?? null)
                ? (string) $metadata[RemoteControlAgentSyncService::REPORT_URL_METADATA]
                : null,
            'applied' => $applied,
        ];
    }

    /**
     * Store the correct managed URL as a new settings version when it differs.
     * The websocket server sends it to the instance (now when connected, else on reconnect).
     */
    public function pushManagedUrl(RemoteControlRegistration $registration): bool
    {
        $expected = $this->expectedUrl($registration);
        $settings = is_array($registration->settings) ? $registration->settings : [];

        if ($expected === null || ($settings['reportingurl'] ?? null) === $expected) {
            return false;
        }

        $registration->update([
            'settings' => $this->settings->build($registration, $this->urls->baseUrlFor($registration), $expected),
        ]);

        return true;
    }

    /**
     * Remove Central report URLs that were set by hand on the instance.
     *
     * @return array{changed: list<string>, failed: list<string>} locations, e.g. 'Default options', 'Job "Docs"'
     *
     * @throws RuntimeException when the instance cannot be read
     */
    public function cleanManualUrls(RemoteControlRegistration $registration): array
    {
        $result = ['changed' => [], 'failed' => []];

        [$status, $serverSettings] = $this->commands->dispatchAndWait($registration, 'GET', '/api/v1/serversettings');
        if ($status !== 200 || ! is_array($serverSettings)) {
            throw new RuntimeException("Could not read the default options (HTTP {$status}).");
        }

        $patch = [];
        foreach ($serverSettings as $name => $value) {
            if (! $this->isSendHttpOption((string) $name) || ! str_starts_with((string) $name, '--') || ! is_string($value)) {
                continue;
            }

            [$remaining, $removed] = self::withoutCentralUrls($value);
            if ($removed > 0) {
                $patch[$name] = $remaining === '' ? null : $remaining;
            }
        }

        if ($patch !== []) {
            [$status] = $this->commands->dispatchAndWait($registration, 'PATCH', '/api/v1/serversettings', $patch);
            $this->isSuccess($status)
                ? $result['changed'][] = 'Default options'
                : $result['failed'][] = "Default options: HTTP {$status}";
        }

        [$status, $backups] = $this->commands->dispatchAndWait($registration, 'GET', '/api/v1/backups');
        if ($status !== 200 || ! is_array($backups)) {
            throw new RuntimeException("Could not list backup jobs (HTTP {$status}).");
        }

        foreach (RemoteLogImportService::normalizeBackups($backups) as $job) {
            $label = 'Job "'.$job['name'].'"';

            try {
                if ($this->cleanJob($registration, $job['id'])) {
                    $result['changed'][] = $label;
                }
            } catch (\Throwable $e) {
                $result['failed'][] = "{$label}: {$e->getMessage()}";
            }
        }

        return $result;
    }

    /**
     * Push the managed URL and, when the instance is connected, clean up hand-set URLs.
     * Writes the outcome to the owner's event log.
     *
     * @param  bool  $logUnchanged  also log when everything was already correct (manual runs)
     * @return array{pushed: bool, changed: list<string>, failed: list<string>, connected: bool}
     */
    public function sync(RemoteControlRegistration $registration, bool $cleanup = true, bool $logUnchanged = false): array
    {
        $registration->loadMissing(['backupServer', 'agentState']);
        $summary = ['pushed' => false, 'changed' => [], 'failed' => [], 'connected' => (bool) $registration->agentState?->is_connected];
        $name = $registration->display_name;

        if ($this->expectedUrl($registration) === null) {
            $this->log($registration, EventLog::LEVEL_WARNING, "{$name} is not linked to a backup server, so it has no reporting URL.");

            return $summary;
        }

        $summary['pushed'] = $this->pushManagedUrl($registration);
        if ($summary['pushed']) {
            $this->log($registration, EventLog::LEVEL_INFO, $summary['connected']
                ? "Reporting URL updated on {$name}."
                : "Reporting URL updated for {$name}. It is sent when the instance reconnects.");
        }

        if ($cleanup && $summary['connected']) {
            try {
                ['changed' => $summary['changed'], 'failed' => $summary['failed']] = $this->cleanManualUrls($registration);
            } catch (\Throwable $e) {
                $summary['failed'][] = $e->getMessage();
            }

            if ($summary['changed'] !== []) {
                $this->log($registration, EventLog::LEVEL_INFO,
                    "Removed Central report URLs set by hand on {$name} from: ".implode(', ', $summary['changed']).'. The managed reporting URL is used instead.');
            }

            if ($summary['failed'] !== []) {
                $this->log($registration, EventLog::LEVEL_WARNING,
                    "Could not check all report options on {$name}.", ['errors' => $summary['failed']]);
            }
        }

        if ($logUnchanged && ! $summary['pushed'] && $summary['changed'] === [] && $summary['failed'] === []) {
            $this->log($registration, EventLog::LEVEL_INFO, $summary['connected'] || ! $cleanup
                ? "Reporting URL on {$name} is correct."
                : "Reporting URL for {$name} is correct. Options set by hand are checked when the instance is connected.");
        }

        return $summary;
    }

    /**
     * Remove Central endpoints from a ";"-separated URL list.
     *
     * @return array{0: string, 1: int} remaining list, number removed
     */
    public static function withoutCentralUrls(string $value): array
    {
        $urls = array_values(array_filter(array_map('trim', explode(';', $value)), fn ($url) => $url !== ''));
        $kept = array_values(array_filter($urls, fn ($url) => ! preg_match(self::CENTRAL_ENDPOINT, $url)));

        return [implode(';', $kept), count($urls) - count($kept)];
    }

    private function cleanJob(RemoteControlRegistration $registration, string $backupId): bool
    {
        $path = '/api/v1/backup/'.rawurlencode($backupId);
        [$status, $config] = $this->commands->dispatchAndWait($registration, 'GET', $path);
        if ($status !== 200 || ! is_array($config) || ! is_array($config['Backup'] ?? null)) {
            throw new RuntimeException("could not read the job (HTTP {$status})");
        }

        $changed = false;
        $settings = [];
        foreach ((array) ($config['Backup']['Settings'] ?? []) as $setting) {
            if (is_array($setting) && $this->isSendHttpOption((string) ($setting['Name'] ?? '')) && is_string($setting['Value'] ?? null)) {
                [$remaining, $removed] = self::withoutCentralUrls($setting['Value']);
                if ($removed > 0) {
                    $changed = true;
                    if ($remaining === '') {
                        continue;
                    }
                    $setting['Value'] = $remaining;
                }
            }

            $settings[] = $setting;
        }

        if (! $changed) {
            return false;
        }

        [$status] = $this->commands->dispatchAndWait($registration, 'PUT', $path, DuplicatiJobConfig::withSettings($config, $settings));
        if (! $this->isSuccess($status)) {
            throw new RuntimeException("could not save the job (HTTP {$status})");
        }

        return true;
    }

    private function isSendHttpOption(string $name): bool
    {
        return in_array(strtolower(ltrim($name, '-')), self::SEND_HTTP_OPTIONS, true);
    }

    private function isSuccess(?int $status): bool
    {
        return $status !== null && $status >= 200 && $status < 300;
    }

    private function log(RemoteControlRegistration $registration, string $level, string $message, array $context = []): void
    {
        if ($registration->claimed_by_user_id === null) {
            return;
        }

        $this->events->record($registration->claimed_by_user_id, EventLog::CATEGORY_REMOTE, $level, $message, $context, $registration->backup_server_id);
    }
}
