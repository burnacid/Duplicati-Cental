<?php

use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlCertificateService;
use App\Services\RemoteControlConnectionHandler;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function Ratchet\Client\connect;
use function React\Async\await;

/**
 * Acts like the Duplicati 2.4 client (protocol 2): it refuses a server without protocol 2,
 * and command payloads are wrapped as {"v":2,"jwe":"..."} with RSA-OAEP-256 + A256GCM.
 */
test('a protocol 2 client gets end-to-end encrypted commands and its encrypted reply completes them', function () {
    $registration = RemoteControlRegistration::query()->create([
        'instance_id' => 'v2-instance',
        'machine_id' => 'v2-machine',
        'machine_name' => 'Protocol 2 Machine',
        'registration_token' => 'reg-'.bin2hex(random_bytes(8)),
        'claim_token' => 'claim-'.bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-'.bin2hex(random_bytes(8)),
        'settings' => ['settingsversion' => '0'],
    ]);

    $serverPublicKeyPem = app(RemoteControlCertificateService::class)->listCertificates()[0]['publicKey'];
    $clientCrypto = makeStandaloneClientCryptoService();
    $clientPublicKeyPem = $clientCrypto->ourPublicKeyPem();

    // The standalone service's own key pair, read back so the test client can decrypt command payloads.
    $clientPrivateKey = (function () {
        return JWKFactory::createFromKey($this->certificateService->getOrCreateKeyPair()['private']);
    })->call($clientCrypto);

    $algorithms = new AlgorithmManager([new RSAOAEP256, new A256GCM]);
    $largeResult = array_map(fn ($i) => ['id' => "job-{$i}", 'name' => str_repeat('Backup ', 20)], range(1, 50));

    [$socket, $server, $url, $loop] = startRemoteControlTestServer();

    try {
        $deferred = new Deferred;
        $stage = 'await_welcome';

        /** @var WebSocket $connection */
        $connection = await(connect($url, [], [], $loop));

        $connection->on('message', function ($msg) use (
            &$stage, $connection, $clientCrypto, $clientPrivateKey, $algorithms, $largeResult,
            $serverPublicKeyPem, $clientPublicKeyPem, $registration, $deferred
        ) {
            if ($stage === 'await_welcome') {
                $welcome = json_decode((string) $msg, true);
                $versions = json_decode($welcome['payload'], true)['supportedProtocolVersions'];

                if (! in_array(2, $versions, true)) {
                    $deferred->reject(new RuntimeException('Server does not offer protocol version 2'));

                    return;
                }

                $connection->send($clientCrypto->encryptEnvelopeFor([
                    'from' => 'client',
                    'to' => 'server',
                    'type' => 'auth',
                    'messageId' => $welcome['messageId'],
                    'payload' => json_encode([
                        'token' => $registration->jwt,
                        'publicKey' => $clientPublicKeyPem,
                        'clientVersion' => '2.4.1.0',
                        'protocolVersion' => 2,
                        'metadata' => ['feature:restore-test' => 'true'],
                    ]),
                    'errorMessage' => null,
                ], $serverPublicKeyPem));
                $stage = 'authenticated';

                return;
            }

            $envelope = $clientCrypto->decryptEnvelope((string) $msg);

            if ($envelope['type'] !== 'command') {
                return;
            }

            try {
                $wrapper = json_decode($envelope['payload'], true);
                expect($wrapper['v'])->toBe(2)->and($wrapper)->not->toHaveKey('method');

                $jwe = (new CompactSerializer)->unserialize($wrapper['jwe']);
                expect($jwe->getSharedProtectedHeader())->toMatchArray(['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM'])
                    ->and((new JWEDecrypter($algorithms))->decryptUsingKey($jwe, $clientPrivateKey, 0))->toBeTrue();

                $plain = json_decode($jwe->getPayload(), true);
                expect($plain['messageId'])->toBe($envelope['messageId'])
                    ->and($plain['replyKey']['kty'])->toBe('RSA')
                    ->and($plain['replyKey'])->toHaveKeys(['n', 'e'])
                    ->and($plain['replyKey'])->not->toHaveKey('d');

                // Reply as Duplicati does: encrypted to the reply key, gzip because it is over 2 KiB.
                $responsePlain = gzencode(json_encode([
                    'messageId' => $envelope['messageId'],
                    'response' => ['statusCode' => 200, 'body' => base64_encode(json_encode($largeResult)), 'headers' => null],
                ]));
                $responseJwe = (new JWEBuilder($algorithms))->create()
                    ->withPayload($responsePlain)
                    ->withSharedProtectedHeader(['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM'])
                    ->addRecipient(new JWK($plain['replyKey']))
                    ->build();

                $connection->send($clientCrypto->encryptEnvelopeFor([
                    'from' => 'client',
                    'to' => 'server',
                    'type' => 'command',
                    'messageId' => $envelope['messageId'],
                    'payload' => json_encode(['v' => 2, 'jwe' => (new CompactSerializer)->serialize($responseJwe, 0), 'zip' => 'gzip']),
                    'errorMessage' => null,
                ], $serverPublicKeyPem));

                Loop::get()->addTimer(0.2, fn () => $deferred->resolve($plain['request']));
            } catch (Throwable $e) {
                $deferred->reject($e);
            }
        });

        $authSettled = new Deferred;
        Loop::get()->addTimer(0.2, fn () => $authSettled->resolve(null));
        await($authSettled->promise());

        $command = RemoteControlCommand::query()->create([
            'remote_control_registration_id' => $registration->id,
            'method' => 'GET',
            'path' => '/api/v1/backups',
            'status' => RemoteControlCommand::STATUS_PENDING,
        ]);

        app(RemoteControlConnectionHandler::class)->dispatchPendingCommands();

        $request = await($deferred->promise());
        $connection->close();

        expect($request['method'])->toBe('GET')->and($request['path'])->toBe('/api/v1/backups');
    } finally {
        $socket->close();
    }

    $command->refresh();

    expect($command->status)->toBe(RemoteControlCommand::STATUS_COMPLETED)
        ->and($command->response_status_code)->toBe(200)
        ->and(json_decode((string) $command->response_body, true))->toBe($largeResult);
});
