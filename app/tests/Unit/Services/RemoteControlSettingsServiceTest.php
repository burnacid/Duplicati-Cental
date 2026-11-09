<?php

use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlSettingsService;

test('settings builder injects reporting url and increments settings version', function () {
    $service = new RemoteControlSettingsService();

    $registration = new RemoteControlRegistration([
        'settings' => [
            'settingsversion' => '4',
            'dashboardurl' => 'https://existing.example.test',
        ],
    ]);

    $settings = $service->build(
        $registration,
        'https://central.example.test',
        'https://central.example.test/api/backup-servers/server/api/backup-result'
    );

    expect($settings['dashboardurl'])->toBe('https://existing.example.test')
        ->and($settings['reportingurl'])->toBe('https://central.example.test/api/backup-servers/server/api/backup-result')
        ->and($settings['settingsversion'])->toBe('5');
});

test('settings builder initializes dashboard and version when empty', function () {
    $service = new RemoteControlSettingsService();
    $registration = new RemoteControlRegistration([
        'settings' => null,
    ]);

    $settings = $service->build($registration, 'https://central.example.test');

    expect($settings['dashboardurl'])->toBe('https://central.example.test')
        ->and($settings['settingsversion'])->toBe('1')
        ->and(isset($settings['reportingurl']))->toBeFalse();
});

