<?php

use App\Models\RemoteControlAgentState;
use App\Models\RemoteControlRegistration;
use App\Services\RemoteControlCertificateService;
use App\Services\RemoteControlConnectionHandler;
use App\Services\RemoteControlCryptoService;
use Ratchet\Http\HttpServer;
use Ratchet\Server\IoServer;
use Ratchet\WebSocket\WsServer;
use React\EventLoop\Loop;
use React\Promise\Deferred;
use React\Socket\SocketServer;

use function React\Async\await;
use function Ratchet\Client\connect;

function startRemoteControlTestServer(): array
{
    $loop = Loop::get();
    $socket = new SocketServer('127.0.0.1:0', [], $loop);
    $server = new IoServer(new HttpServer(new WsServer(app(RemoteControlConnectionHandler::class))), $socket, $loop);

    $port = (int) parse_url((string) $socket->getAddress(), PHP_URL_PORT);
    $url = "ws://127.0.0.1:{$port}" . config('remote_control.websocket_path');

    return [$socket, $server, $url, $loop];
}

function makeStandaloneClientCryptoService(): RemoteControlCryptoService
{
    $tempPath = sys_get_temp_dir() . '/rc-ws-test-client-' . bin2hex(random_bytes(8));

    $certificateService = new class($tempPath) extends RemoteControlCertificateService
    {
        public function __construct(private readonly string $tempPath)
        {
        }

        protected function resolveStoragePath(): string
        {
            return $this->tempPath;
        }
    };

    return new RemoteControlCryptoService($certificateService);
}

test('welcome envelope is sent in plaintext on connect', function () {
    [$socket, $server, $url, $loop] = startRemoteControlTestServer();

    try {
        $deferred = new Deferred();

        /** @var \Ratchet\Client\WebSocket $connection */
        $connection = await(connect($url, [], [], $loop));
        $connection->on('message', function ($msg) use ($deferred) {
            $deferred->resolve(json_decode((string) $msg, true));
        });

        $welcome = await($deferred->promise());
        $connection->close();

        expect($welcome['type'])->toBe('welcome')
            ->and($welcome['from'])->toBe('server')
            ->and($welcome['errorMessage'])->toBeNull();

        $payload = json_decode($welcome['payload'], true);
        expect($payload)->toHaveKeys(['publicKeyHash', 'machineName', 'serverVersion', 'supportedProtocolVersions'])
            ->and($payload['supportedProtocolVersions'])->toBe([1, 2]);
    } finally {
        $socket->close();
    }
});

test('full handshake authenticates, pushes pending settings, and records the acknowledgement', function () {
    $registration = RemoteControlRegistration::query()->create([
        'instance_id' => 'ws-test-instance',
        'machine_id' => 'ws-test-machine',
        'machine_name' => 'WS Test Machine',
        'registration_token' => 'reg-' . bin2hex(random_bytes(8)),
        'claim_token' => 'claim-' . bin2hex(random_bytes(8)),
        'status' => RemoteControlRegistration::STATUS_CLAIMED,
        'expires_at' => now()->addHour(),
        'claimed_at' => now(),
        'jwt' => 'jwt-' . bin2hex(random_bytes(8)),
        'settings' => [
            'settingsversion' => '2',
            'reportingurl' => 'https://central.example.test/api/backup-servers/test/key/backup-result',
        ],
    ]);

    $serverPublicKeyPem = app(RemoteControlCertificateService::class)->listCertificates()[0]['publicKey'];

    $clientCrypto = makeStandaloneClientCryptoService();
    $clientPublicKeyPem = $clientCrypto->ourPublicKeyPem();

    [$socket, $server, $url, $loop] = startRemoteControlTestServer();

    try {
        $deferred = new Deferred();
        $seen = [];
        $stage = 'await_welcome';

        /** @var \Ratchet\Client\WebSocket $connection */
        $connection = await(connect($url, [], [], $loop));

        $connection->on('message', function ($msg) use (
            &$stage,
            &$seen,
            $connection,
            $clientCrypto,
            $serverPublicKeyPem,
            $clientPublicKeyPem,
            $registration,
            $deferred
        ) {
            if ($stage === 'await_welcome') {
                $seen['welcome'] = json_decode((string) $msg, true);

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
                        'metadata' => ['source' => 'pest-handshake-test'],
                    ]),
                    'errorMessage' => null,
                ];

                $connection->send($clientCrypto->encryptEnvelopeFor($authEnvelope, $serverPublicKeyPem));
                $stage = 'await_auth_reply';

                return;
            }

            $envelope = $clientCrypto->decryptEnvelope((string) $msg);

            if ($stage === 'await_auth_reply' && $envelope['type'] === 'auth') {
                $seen['authReply'] = $envelope;
                $stage = 'await_control';

                return;
            }

            if ($stage === 'await_control' && $envelope['type'] === 'control') {
                $seen['control'] = $envelope;

                $ackEnvelope = [
                    'from' => 'client',
                    'to' => 'server',
                    'type' => 'control',
                    'messageId' => 'control-ack-1',
                    'payload' => json_encode(['output' => ['settingsversion' => 2]]),
                    'errorMessage' => null,
                ];

                $connection->send($clientCrypto->encryptEnvelopeFor($ackEnvelope, $serverPublicKeyPem));
                $stage = 'done';

                // Give the loop a few iterations to flush the write and let the server
                // process the acknowledgement before we assert on database state.
                Loop::get()->addTimer(0.2, function () use ($deferred, &$seen) {
                    $deferred->resolve($seen);
                });
            }
        });

        $seen = await($deferred->promise());
        $connection->close();

        expect($seen['welcome']['type'])->toBe('welcome');

        $authReplyPayload = json_decode($seen['authReply']['payload'], true);
        expect($authReplyPayload['accepted'])->toBeTrue();

        $controlPayload = json_decode($seen['control']['payload'], true);
        expect($controlPayload['command'])->toBe('updatesettings')
            ->and($controlPayload['parameters']['settingsversion'])->toBe('2');
    } finally {
        $socket->close();
    }

    $state = RemoteControlAgentState::query()
        ->where('remote_control_registration_id', $registration->id)
        ->first();

    expect($state)->not->toBeNull()
        ->and($state->last_applied_settings_version)->toBe(2);
});
