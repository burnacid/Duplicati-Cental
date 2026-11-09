<?php

namespace App\Services;

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
        return $this->bridgeBaseUrl($request) . $this->normalizedPath(config('remote_control.websocket_path', '/api/remote-control/ws'));
    }

    public function certificatesUrl(?Request $request = null): string
    {
        return $this->appBaseUrl($request) . $this->normalizedPath(config('remote_control.certificates_path', '/api/remote-control/certificates'));
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
        return '/' . ltrim($path, '/');
    }

    private function convertHttpToWebsocket(string $value): string
    {
        return str_starts_with($value, 'https://')
            ? str_replace('https://', 'wss://', $value)
            : str_replace('http://', 'ws://', $value);
    }
}

