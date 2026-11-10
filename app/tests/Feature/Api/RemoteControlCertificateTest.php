<?php

test('remote control certificates endpoint returns at least one certificate', function () {
    $response = $this->getJson('/api/remote-control/certificates');

    $response->assertOk()
        ->assertJsonStructure([
            '*' => [
                'publicKeyHash',
                'publicKey',
                'obtained',
                'expiry',
            ],
        ]);

    $certificates = $response->json();

    expect($certificates)->toBeArray()
        ->and(count($certificates))->toBeGreaterThan(0)
        ->and($certificates[0]['publicKey'])->toContain('BEGIN PUBLIC KEY');
});
