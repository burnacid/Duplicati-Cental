<?php

use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlCertificateService;
use App\Services\RemoteControlConnectionHandler;
use Ratchet\Client\WebSocket;
use React\EventLoop\Loop;
use React\Promise\Deferred;

use function Ratchet\Client\connect;
use function React\Async\await;

test('a pending command is dispatched over the connection and the agent reply completes it', function () {
    $registration = RemoteControlRegistration::query()->create([
        'instance_id' => 'cmd-roundtrip-instance',
        'machine_id' => 'cmd-roundtrip-machine',
        'machine_name' => 'Command Roundtrip Machine',
        'registration_token' => 'reg-'.bin2hex(random_bytes(8)),
        'claim_token' => 'claim-'.bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-'.bin2hex(random_bytes(8)),
        'settings' => [
            'settingsversion' => '1',
            'reportingurl' => 'https://central.example.test/api/backup-servers/test/key/backup-result',
        ],
    ]);

    $serverPublicKeyPem = app(RemoteControlCertificateService::class)->listCertificates()[0]['publicKey'];
    $clientCrypto = makeStandaloneClientCryptoService();
    $clientPublicKeyPem = $clientCrypto->ourPublicKeyPem();

    [$socket, $server, $url, $loop] = startRemoteControlTestServer();

    try {
        $deferred = new Deferred;
        $stage = 'await_welcome';

        /** @var WebSocket $connection */
        $connection = await(connect($url, [], [], $loop));

        $connection->on('message', function ($msg) use (
            &$stage,
            $connection,
            $clientCrypto,
            $serverPublicKeyPem,
            $clientPublicKeyPem,
            $registration,
            $deferred
        ) {
            if ($stage === 'await_welcome') {
                $authEnvelope = [
                    'from' => 'client',
                    'to' => 'server',
                    'type' => 'auth',
                    'messageId' => 'auth-1',
                    'payload' => json_encode([
                        'token' => $registration->jwt,
                        'publicKey' => $clientPublicKeyPem,
                        'clientVersion' => '2.1.0.5',
                        'protocolVersion' => 1,
                        'metadata' => null,
                    ]),
                    'errorMessage' => null,
                ];

                $connection->send($clientCrypto->encryptEnvelopeFor($authEnvelope, $serverPublicKeyPem));
                $stage = 'authenticated';

                return;
            }

            $envelope = $clientCrypto->decryptEnvelope((string) $msg);

            if ($envelope['type'] === 'command') {
                $payload = json_decode($envelope['payload'], true);

                $responseBody = base64_encode(json_encode([['id' => 'job-1', 'name' => 'Daily Backup']]));
                $responseEnvelope = [
                    'from' => 'client',
                    'to' => 'server',
                    'type' => 'command',
                    'messageId' => $envelope['messageId'],
                    'payload' => json_encode([
                        'statusCode' => 200,
                        'body' => $responseBody,
                        'headers' => null,
                    ]),
                    'errorMessage' => null,
                ];

                $connection->send($clientCrypto->encryptEnvelopeFor($responseEnvelope, $serverPublicKeyPem));

                // Give the loop a few iterations to flush the write and let the server
                // process the response before we assert on database state.
                Loop::get()->addTimer(0.2, function () use ($deferred, $payload) {
                    $deferred->resolve(['requestPath' => $payload['path'], 'requestMethod' => $payload['method']]);
                });
            }
        });

        // Wait for auth to complete before creating + dispatching the command.
        $authSettled = new Deferred;
        Loop::get()->addTimer(0.2, function () use ($authSettled) {
            $authSettled->resolve(null);
        });
        await($authSettled->promise());

        $command = RemoteControlCommand::query()->create([
            'remote_control_registration_id' => $registration->id,
            'method' => 'GET',
            'path' => '/api/v1/backups',
            'status' => RemoteControlCommand::STATUS_PENDING,
        ]);

        app(RemoteControlConnectionHandler::class)->dispatchPendingCommands();

        $seen = await($deferred->promise());
        $connection->close();

        expect($seen['requestMethod'])->toBe('GET')
            ->and($seen['requestPath'])->toBe('/api/v1/backups');
    } finally {
        $socket->close();
    }

    $command->refresh();

    expect($command->status)->toBe(RemoteControlCommand::STATUS_COMPLETED)
        ->and($command->response_status_code)->toBe(200)
        ->and(json_decode((string) $command->response_body, true))->toBe([['id' => 'job-1', 'name' => 'Daily Backup']]);
});
