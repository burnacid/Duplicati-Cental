<?php

namespace App\Services;

use App\Models\BackupServer;
use App\Models\RemoteControlRegistration;
use Illuminate\Http\Request;

class RemoteControlUrlService
{
    public function appBaseUrl(?Request $request = null): string
    {
        return $this->normalizeBaseUrl(config('app.url') ?: $request?->getSchemeAndHttpHost() ?: 'http://localhost');
    }

    public function bridgeBaseUrl(?Request $request = null): string
    {
        $configured = config('remote_control.bridge_url');

        if (is_string($configured) && trim($configured) !== '') {
            return $this->normalizeBridgeUrl($configured);
        }

        return $this->convertHttpToWebsocket($this->appBaseUrl($request));
    }

    public function websocketUrl(?Request $request = null): string
    {
        return $this->bridgeBaseUrl($request).$this->normalizedPath(config('remote_control.websocket_path', '/api/remote-control/ws'));
    }

    public function certificatesUrl(?Request $request = null): string
    {
        return $this->appBaseUrl($request).$this->normalizedPath(config('remote_control.certificates_path', '/api/remote-control/certificates'));
    }

    /**
     * The base URL an instance should use to reach us: the address it registered
     * through (so LAN instances stay on the LAN and remote ones use the public
     * address), falling back to APP_URL.
     */
    public function baseUrlFor(RemoteControlRegistration $registration): string
    {
        $registered = $registration->registration_base_url;

        return is_string($registered) && $registered !== ''
            ? $this->normalizeBaseUrl($registered)
            : $this->appBaseUrl();
    }

    /**
     * Websocket URL for an instance. REMOTE_CONTROL_BRIDGE_URL, when set, overrides it for all instances.
     */
    public function websocketUrlFor(RemoteControlRegistration $registration): string
    {
        $configured = config('remote_control.bridge_url');
        $base = is_string($configured) && trim($configured) !== ''
            ? $this->normalizeBridgeUrl($configured)
            : $this->convertHttpToWebsocket($this->baseUrlFor($registration));

        return $base.$this->normalizedPath(config('remote_control.websocket_path', '/api/remote-control/ws'));
    }

    public function certificatesUrlFor(RemoteControlRegistration $registration): string
    {
        return $this->baseUrlFor($registration).$this->normalizedPath(config('remote_control.certificates_path', '/api/remote-control/certificates'));
    }

    /** Reporting URL (send-http-url) for a backup server, on the instance's address. */
    public function reportingUrlFor(RemoteControlRegistration $registration, BackupServer $server): string
    {
        return $this->baseUrlFor($registration).'/api/backup-servers/'.$server->id.'/'.$server->api_key.'/backup-result';
    }

    /**
     * Registration URLs to show to the user: the public one (APP_URL) and, when
     * the page is opened on another address (e.g. a LAN IP), that one too.
     *
     * @return array<string, string> label => URL
     */
    public function registrationUrls(Request $request): array
    {
        $path = '/api/remote-control/register';
        $public = $this->appBaseUrl().$path;
        $current = $this->normalizeBaseUrl($request->getSchemeAndHttpHost()).$path;

        return $current === $public
            ? ['Registration URL' => $public]
            : ['Registration URL (public, APP_URL)' => $public, 'Registration URL (this address)' => $current];
    }

    private function normalizeBridgeUrl(string $value): string
    {
        $value = trim($value);

        if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) {
            return $this->convertHttpToWebsocket($this->normalizeBaseUrl($value));
        }

        return rtrim($value, '/');
    }

    private function normalizeBaseUrl(string $value): string
    {
        return rtrim(trim($value), '/');
    }

    private function normalizedPath(string $path): string
    {
        return '/'.ltrim($path, '/');
    }

    private function convertHttpToWebsocket(string $value): string
    {
        return str_starts_with($value, 'https://')
            ? str_replace('https://', 'wss://', $value)
            : str_replace('http://', 'ws://', $value);
    }
}
