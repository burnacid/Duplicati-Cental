<?php

namespace App\Services;

use App\Exceptions\RemoteControlCryptoException;
use App\Models\RemoteControlCommand;
use App\Models\RemoteControlRegistration;
use Exception;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Psr\Http\Message\RequestInterface;
use Ratchet\ConnectionInterface;
use Ratchet\MessageComponentInterface;
use SplObjectStorage;
use stdClass;

class RemoteControlConnectionHandler implements MessageComponentInterface
{
    private const PHASE_WELCOME_SENT = 'welcome_sent';
    private const PHASE_AUTHENTICATED = 'authenticated';

    /**
     * Protocol 1: Duplicati up to 2.3. Protocol 2 (Duplicati 2.4+): command payloads are
     * end-to-end encrypted and the client refuses servers that do not offer version 2.
     */
    private const SUPPORTED_PROTOCOL_VERSIONS = [1, 2];

    private SplObjectStorage $connections;

    /** @var array<string, ConnectionInterface> registrationId => ConnectionInterface */
    private array $connectionsByRegistrationId = [];

    /** @var array<string, string> messageId => commandId */
    private array $pendingCommandsByMessageId = [];

    public function __construct(
        private readonly RemoteControlCertificateService $certificateService,
        private readonly RemoteControlCryptoService $cryptoService,
        private readonly RemoteControlAgentSyncService $syncService,
    ) {
        $this->connections = new SplObjectStorage();
    }

    public function onOpen(ConnectionInterface $conn): void
    {
        $request = isset($conn->httpRequest) && $conn->httpRequest instanceof RequestInterface
            ? $conn->httpRequest
            : null;

        if ($request === null || $request->getUri()->getPath() !== config('remote_control.websocket_path')) {
            $conn->close();

            return;
        }

        $state = new stdClass();
        $state->phase = self::PHASE_WELCOME_SENT;
        $state->clientId = 'client';
        $state->registration = null;
        $state->clientPublicKeyPem = null;
        $state->protocolVersion = 1;

        $this->connections[$conn] = $state;

        $certificate = $this->certificateService->listCertificates()[0];

        $this->sendPlaintext($conn, $state, 'welcome', [
            'publicKeyHash' => $certificate['publicKeyHash'],
            'machineName' => (string) config('app.name'),
            'serverVersion' => (string) config('remote_control.server_version'),
            'supportedProtocolVersions' => self::SUPPORTED_PROTOCOL_VERSIONS,
        ]);
    }

    /**
     * @param mixed $msg
     */
    public function onMessage(ConnectionInterface $from, $msg): void
    {
        if (! $this->connections->contains($from)) {
            $from->close();

            return;
        }

        $state = $this->connections[$from];

        match ($state->phase) {
            self::PHASE_WELCOME_SENT => $this->handleAuth($from, $state, (string) $msg),
            self::PHASE_AUTHENTICATED => $this->handleAuthenticatedMessage($from, $state, (string) $msg),
            default => $from->close(),
        };
    }

    public function onClose(ConnectionInterface $conn): void
    {
        if (! $this->connections->contains($conn)) {
            return;
        }

        $state = $this->connections[$conn];

        if ($state->registration instanceof RemoteControlRegistration) {
            $this->syncService->markDisconnected($state->registration);
            unset($this->connectionsByRegistrationId[$state->registration->id]);
        }

        $this->connections->detach($conn);
    }

    public function onError(ConnectionInterface $conn, Exception $e): void
    {
        Log::warning('remote-control: connection error', ['error' => $e->getMessage()]);
        $conn->close();
    }

    private function handleAuth(ConnectionInterface $conn, stdClass $state, string $rawMessage): void
    {
        try {
            $envelope = $this->cryptoService->decryptEnvelope($rawMessage);
        } catch (RemoteControlCryptoException $e) {
            Log::warning('remote-control: failed to decrypt auth envelope', ['error' => $e->getMessage()]);
            $conn->close();

            return;
        }

        $state->clientId = is_string($envelope['from'] ?? null) ? $envelope['from'] : $state->clientId;

        if (! in_array($envelope['type'] ?? null, ['auth', 'authrunner'], true)) {
            $conn->close();

            return;
        }

        $payload = $this->decodePayload($envelope);
        $token = $payload['token'] ?? null;
        $clientPublicKey = $payload['publicKey'] ?? null;

        if (! is_string($token) || $token === '' || ! is_string($clientPublicKey) || $clientPublicKey === '') {
            $conn->close();

            return;
        }

        $registration = $this->syncService->findClaimedRegistrationByJwt($token);

        if (! $registration) {
            $conn->close();

            return;
        }

        $state->registration = $registration;
        $state->clientPublicKeyPem = $clientPublicKey;
        $protocolVersion = (int) ($payload['protocolVersion'] ?? 1);
        $state->protocolVersion = in_array($protocolVersion, self::SUPPORTED_PROTOCOL_VERSIONS, true) ? $protocolVersion : 1;
        $state->phase = self::PHASE_AUTHENTICATED;

        $this->connectionsByRegistrationId[$registration->id] = $conn;

        $this->sendEncrypted($conn, $state, (string) $envelope['type'], [
            'accepted' => true,
            'willReplaceToken' => false,
            'newToken' => null,
        ]);

        $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null;
        $result = $this->syncService->touchAndBuildControl($registration, $metadata);

        if ($result['control'] !== null) {
            $this->sendEncrypted($conn, $state, 'control', [
                'command' => $result['control']['command'],
                'parameters' => $result['control']['parameters'],
            ]);
        }
    }

    private function handleAuthenticatedMessage(ConnectionInterface $conn, stdClass $state, string $rawMessage): void
    {
        try {
            $envelope = $this->cryptoService->decryptEnvelope($rawMessage);
        } catch (RemoteControlCryptoException $e) {
            Log::warning('remote-control: failed to decrypt envelope', ['error' => $e->getMessage()]);
            $conn->close();

            return;
        }

        /** @var RemoteControlRegistration $registration */
        $registration = $state->registration;

        match ($envelope['type'] ?? null) {
            'ping' => $this->handlePing($conn, $state, $registration),
            'control' => $this->handleControlAck($envelope, $registration),
            'command' => $this->handleCommandResponse($envelope, $registration),
            default => Log::debug('remote-control: ignoring envelope type', ['type' => $envelope['type'] ?? null]),
        };
    }

    private function handlePing(ConnectionInterface $conn, stdClass $state, RemoteControlRegistration $registration): void
    {
        $this->syncService->touchLastSeen($registration);
        $this->sendEncrypted($conn, $state, 'pong', null);
    }

    private function handleControlAck(array $envelope, RemoteControlRegistration $registration): void
    {
        $payload = $this->decodePayload($envelope);
        $output = $payload['output'] ?? null;
        $settingsVersion = is_array($output) ? (int) ($output['settingsversion'] ?? 0) : 0;

        if ($settingsVersion > 0) {
            $this->syncService->acknowledgeSettings($registration, $settingsVersion);
        }
    }

    private function handleCommandResponse(array $envelope, RemoteControlRegistration $registration): void
    {
        $messageId = $envelope['messageId'] ?? null;
        $commandId = is_string($messageId) ? ($this->pendingCommandsByMessageId[$messageId] ?? null) : null;

        if ($commandId === null) {
            Log::debug('remote-control: command response for unknown/expired messageId', [
                'messageId' => $messageId,
            ]);

            return;
        }

        unset($this->pendingCommandsByMessageId[$messageId]);

        $command = RemoteControlCommand::query()->find($commandId);

        if ($command === null || $command->status !== RemoteControlCommand::STATUS_SENT) {
            return;
        }

        $payload = $this->decodePayload($envelope);

        // Protocol v2 replies are wrapped; a plain reply is v1 or a refusal (e.g. 426) from the client.
        if ($this->cryptoService->isEncryptedCommandPayload($payload)) {
            try {
                $payload = $this->cryptoService->decryptCommandResponse($payload, $messageId);
            } catch (RemoteControlCryptoException $e) {
                Log::warning('remote-control: failed to decrypt command response', [
                    'registration' => $registration->id,
                    'error' => $e->getMessage(),
                ]);

                $command->status = RemoteControlCommand::STATUS_COMPLETED;
                $command->response_status_code = 502;
                $command->error_message = 'Could not decrypt the response from the instance.';
                $command->completed_at = now();
                $command->save();

                return;
            }
        }

        $bodyBase64 = $payload['body'] ?? null;
        $decodedBody = is_string($bodyBase64) && $bodyBase64 !== '' ? base64_decode($bodyBase64, true) : null;

        $command->status = RemoteControlCommand::STATUS_COMPLETED;
        $command->response_status_code = (int) ($payload['statusCode'] ?? 0);
        $command->response_body = $decodedBody === false ? null : $decodedBody;
        $command->response_headers = is_array($payload['headers'] ?? null) ? $payload['headers'] : null;
        $command->completed_at = now();
        $command->save();
    }

    public function dispatchPendingCommands(): void
    {
        $pending = RemoteControlCommand::query()->where('status', RemoteControlCommand::STATUS_PENDING)->get();

        foreach ($pending as $command) {
            $conn = $this->connectionsByRegistrationId[$command->remote_control_registration_id] ?? null;

            if ($conn === null || ! $this->connections->contains($conn)) {
                continue;
            }

            $state = $this->connections[$conn];

            if ($state->phase !== self::PHASE_AUTHENTICATED) {
                continue;
            }

            $messageId = (string) Str::uuid();
            $request = [
                'method' => $command->method,
                'path' => $command->path,
                'body' => $command->request_body === null ? null : base64_encode($command->request_body),
                'headers' => $command->request_headers,
            ];

            if ($state->protocolVersion >= 2) {
                $request = $this->cryptoService->encryptCommandRequest($messageId, $request, $state->clientPublicKeyPem);
            }

            $this->sendEncrypted($conn, $state, 'command', $request, $messageId);

            $command->status = RemoteControlCommand::STATUS_SENT;
            $command->message_id = $messageId;
            $command->sent_at = now();
            $command->save();

            $this->pendingCommandsByMessageId[$messageId] = $command->id;
        }
    }

    public function reapStaleCommands(): void
    {
        $cutoff = now()->subSeconds(30);

        RemoteControlCommand::query()
            ->where('status', RemoteControlCommand::STATUS_PENDING)
            ->where('created_at', '<', $cutoff)
            ->update([
                'status' => RemoteControlCommand::STATUS_TIMEOUT,
                'error_message' => 'No connection available before timeout.',
            ]);

        RemoteControlCommand::query()
            ->where('status', RemoteControlCommand::STATUS_SENT)
            ->where('sent_at', '<', $cutoff)
            ->update([
                'status' => RemoteControlCommand::STATUS_TIMEOUT,
                'error_message' => 'No response received before timeout.',
            ]);
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function sendPlaintext(ConnectionInterface $conn, stdClass $state, string $type, ?array $payload): void
    {
        $conn->send(json_encode($this->buildEnvelope($state, $type, $payload)));
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function sendEncrypted(ConnectionInterface $conn, stdClass $state, string $type, ?array $payload, ?string $messageId = null): string
    {
        $envelope = $this->buildEnvelope($state, $type, $payload, $messageId);
        $conn->send($this->cryptoService->encryptEnvelopeFor($envelope, $state->clientPublicKeyPem));

        return $envelope['messageId'];
    }

    /**
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function buildEnvelope(stdClass $state, string $type, ?array $payload, ?string $messageId = null): array
    {
        return [
            'from' => 'server',
            'to' => $state->clientId,
            'type' => $type,
            'messageId' => $messageId ?? (string) Str::uuid(),
            'payload' => $payload === null ? null : json_encode($payload),
            'errorMessage' => null,
        ];
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>
     */
    private function decodePayload(array $envelope): array
    {
        $raw = $envelope['payload'] ?? null;

        if (! is_string($raw) || $raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }
}
