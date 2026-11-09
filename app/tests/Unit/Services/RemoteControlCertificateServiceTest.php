<?php

use App\Exceptions\RemoteControlCertificateException;
use App\Services\RemoteControlCertificateService;
use Tests\TestCase;

uses(TestCase::class);

test('certificate service returns deterministic key pair after initial creation', function () {
    $service = new RemoteControlCertificateService();

    $first = $service->listCertificates();
    $second = $service->listCertificates();

    expect($first[0]['publicKeyHash'])->toBe($second[0]['publicKeyHash'])
        ->and($first[0]['publicKey'])->toBe($second[0]['publicKey']);
});

test('throws RemoteControlCertificateException instead of fabricating fake PEM when OpenSSL is unavailable', function () {
    $tempPath = sys_get_temp_dir() . '/rc-cert-test-' . bin2hex(random_bytes(4));

    $service = new class($tempPath) extends RemoteControlCertificateService
    {
        public function __construct(private readonly string $tempPath)
        {
        }

        protected function isOpenSslAvailable(): bool
        {
            return false;
        }

        protected function resolveStoragePath(): string
        {
            return $this->tempPath;
        }
    };

    $service->getOrCreateKeyPair();
})->throws(RemoteControlCertificateException::class);

