<?php

use App\Services\RemoteControlUrlService;
use Illuminate\Http\Middleware\TrustProxies;

afterEach(function () {
    TrustProxies::flushState();
});

test('behind a trusted reverse proxy, links use https and the forwarded host', function () {
    TrustProxies::at('*');

    $html = $this->withServerVariables(['REMOTE_ADDR' => '172.18.0.2'])
        ->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'central.example.com', 'X-Forwarded-Port' => '443'])
        ->get('/login')
        ->assertOk()
        ->getContent();

    expect($html)->toContain('https://central.example.com/')
        ->not->toContain('http://central.example.com/');
});

test('without TRUSTED_PROXIES the forwarded headers are ignored', function () {
    $html = $this->withHeaders(['X-Forwarded-Proto' => 'https', 'X-Forwarded-Host' => 'evil.example.com'])
        ->get('/login')
        ->getContent();

    expect($html)->not->toContain('evil.example.com');
});

test('with an https APP_URL Duplicati gets a wss websocket url on the same host and port', function () {
    config(['app.url' => 'https://central.example.com', 'remote_control.bridge_url' => null]);
    $urls = app(RemoteControlUrlService::class);

    expect($urls->websocketUrl())->toBe('wss://central.example.com/api/remote-control/ws')
        ->and($urls->certificatesUrl())->toBe('https://central.example.com/api/remote-control/certificates');
});
