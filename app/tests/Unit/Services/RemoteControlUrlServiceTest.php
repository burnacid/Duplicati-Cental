<?php

use App\Services\RemoteControlUrlService;
use Illuminate\Http\Request;
use Tests\TestCase;

uses(TestCase::class);

afterEach(function () {
    config()->set('app.url', 'http://localhost');
    config()->set('remote_control.bridge_url', null);
    config()->set('remote_control.websocket_path', '/api/remote-control/ws');
    config()->set('remote_control.certificates_path', '/api/remote-control/certificates');
});

test('websocket url uses dedicated bridge url when configured', function () {
    config()->set('app.url', 'https://central.example.test');
    config()->set('remote_control.bridge_url', 'wss://bridge.example.test:8090');

    $service = new RemoteControlUrlService();

    expect($service->websocketUrl())->toBe('wss://bridge.example.test:8090/api/remote-control/ws')
        ->and($service->certificatesUrl())->toBe('https://central.example.test/api/remote-control/certificates');
});

test('http bridge url is normalized to websocket scheme', function () {
    config()->set('remote_control.bridge_url', 'https://bridge.example.test');

    $service = new RemoteControlUrlService();

    expect($service->websocketUrl())->toBe('wss://bridge.example.test/api/remote-control/ws');
});

test('websocket url falls back to app url when no bridge url is configured', function () {
    config()->set('app.url', 'http://central.example.test');

    $service = new RemoteControlUrlService();

    expect($service->websocketUrl())->toBe('ws://central.example.test/api/remote-control/ws');
});

test('app base url can fall back to request host when config is empty', function () {
    config()->set('app.url', '');

    $service = new RemoteControlUrlService();
    $request = Request::create('https://request-host.example.test/anything', 'GET');

    expect($service->appBaseUrl($request))->toBe('https://request-host.example.test');
});

