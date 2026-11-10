<?php

namespace App\Services;

use App\Exceptions\RemoteControlCertificateException;
use Carbon\CarbonImmutable;

class RemoteControlCertificateService
{
    private const KEY_DIRECTORY = 'app/remote-control';

    private const PRIVATE_KEY_FILE = 'server_private.pem';

    private const PUBLIC_KEY_FILE = 'server_public.pem';

    /**
     * Returns the certificate payload shape expected by Duplicati's claim/status flow.
     */
    public function listCertificates(): array
    {
        ['public' => $publicKey] = $this->getOrCreateKeyPair();

        $obtained = CarbonImmutable::now()->startOfMinute();
        $expiry = $obtained->addYear();

        return [[
            'publicKeyHash' => hash('sha256', trim($publicKey)),
            'publicKey' => $publicKey,
            'obtained' => $obtained->toIso8601String(),
            'expiry' => $expiry->toIso8601String(),
        ]];
    }

    /**
     * Returns the persisted keypair, creating it once if missing.
     *
     * @return array{private: string, public: string}
     */
    public function getOrCreateKeyPair(): array
    {
        $basePath = $this->resolveStoragePath();
        $privatePath = $basePath.DIRECTORY_SEPARATOR.self::PRIVATE_KEY_FILE;
        $publicPath = $basePath.DIRECTORY_SEPARATOR.self::PUBLIC_KEY_FILE;

        if (is_file($privatePath) && is_file($publicPath)) {
            return [
                'private' => (string) file_get_contents($privatePath),
                'public' => (string) file_get_contents($publicPath),
            ];
        }

        if (! is_dir($basePath)) {
            mkdir($basePath, 0700, true);
        }

        $generated = $this->generateKeyPair();
        $privateKey = $generated['private'];
        $publicKey = $generated['public'];

        file_put_contents($privatePath, $privateKey);
        @chmod($privatePath, 0600);
        file_put_contents($publicPath, $publicKey);

        return [
            'private' => $privateKey,
            'public' => $publicKey,
        ];
    }

    protected function resolveStoragePath(): string
    {
        if (function_exists('storage_path') && function_exists('app')) {
            try {
                $candidate = storage_path(self::KEY_DIRECTORY);
                if (is_string($candidate) && $candidate !== '') {
                    return $candidate;
                }
            } catch (\Throwable) {
                // Fall through to temp path fallback.
            }
        }

        return rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'duplicati-central-remote-control';
    }

    /**
     * @return array{private: string, public: string}
     */
    private function generateKeyPair(): array
    {
        if (! $this->isOpenSslAvailable()) {
            throw new RemoteControlCertificateException(
                'The OpenSSL PHP extension is required to generate the remote-control RSA key pair.'
            );
        }

        $options = [
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = openssl_pkey_new($options);
        $exported = $resource !== false && openssl_pkey_export($resource, $privateKey);

        // PHP on Windows often has no openssl.cnf; retry with the minimal config shipped with the app.
        if (! $exported) {
            $firstErrors = $this->collectOpenSslErrors();
            $options['config'] = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'openssl.cnf';

            $resource = openssl_pkey_new($options);
            $exported = $resource !== false && openssl_pkey_export($resource, $privateKey, null, $options);

            if (! $exported) {
                throw new RemoteControlCertificateException(
                    'Failed to generate an RSA key pair: '.$firstErrors.'; with bundled config: '.$this->collectOpenSslErrors()
                );
            }
        }

        $details = openssl_pkey_get_details($resource);
        if (! is_array($details) || empty($details['key'])) {
            throw new RemoteControlCertificateException('Failed to read the generated RSA public key.');
        }

        return [
            'private' => $privateKey,
            'public' => (string) $details['key'],
        ];
    }

    protected function isOpenSslAvailable(): bool
    {
        return function_exists('openssl_pkey_new') && defined('OPENSSL_KEYTYPE_RSA');
    }

    private function collectOpenSslErrors(): string
    {
        $messages = [];
        while ($message = openssl_error_string()) {
            $messages[] = $message;
        }

        return $messages === [] ? 'unknown OpenSSL error' : implode('; ', $messages);
    }
}
