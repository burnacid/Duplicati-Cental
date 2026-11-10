<?php

namespace App\Services;

use App\Exceptions\RemoteControlCryptoException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256GCM;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;
use Throwable;

class RemoteControlCryptoService
{
    private const SHARED_PROTECTED_HEADER = [
        'alg' => 'RSA-OAEP-256',
        'enc' => 'A256CBC-HS512',
    ];

    /** Command payload wrapper version used by Duplicati 2.4+ (remote-control protocol v2). */
    public const COMMAND_PAYLOAD_VERSION = 2;

    private const COMMAND_PROTECTED_HEADER = [
        'alg' => 'RSA-OAEP-256',
        'enc' => 'A256GCM',
    ];

    /** Duplicati compresses plaintexts above this size; we do the same. */
    private const COMPRESSION_THRESHOLD_BYTES = 2 * 1024;

    private const MAX_DECOMPRESSED_BYTES = 64 * 1024 * 1024;

    private ?JWK $ownPrivateKey = null;

    public function __construct(private readonly RemoteControlCertificateService $certificateService)
    {
    }

    public function ourPublicKeyPem(): string
    {
        return $this->certificateService->getOrCreateKeyPair()['public'];
    }

    /**
     * @param array<string, mixed> $envelope
     */
    public function encryptEnvelopeFor(array $envelope, string $recipientPublicKeyPem): string
    {
        try {
            $recipientKey = JWKFactory::createFromKey($recipientPublicKeyPem);
        } catch (Throwable $e) {
            throw new RemoteControlCryptoException('Invalid recipient public key: ' . $e->getMessage(), 0, $e);
        }

        try {
            $jwe = (new JWEBuilder($this->algorithmManager()))
                ->create()
                ->withPayload(json_encode($envelope, JSON_THROW_ON_ERROR))
                ->withSharedProtectedHeader(self::SHARED_PROTECTED_HEADER)
                ->addRecipient($recipientKey)
                ->build();

            return (new CompactSerializer())->serialize($jwe, 0);
        } catch (Throwable $e) {
            throw new RemoteControlCryptoException('Failed to encrypt envelope: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function decryptEnvelope(string $compactJwe): array
    {
        try {
            $jwe = (new CompactSerializer())->unserialize($compactJwe);
        } catch (Throwable $e) {
            throw new RemoteControlCryptoException('Malformed JWE: ' . $e->getMessage(), 0, $e);
        }

        try {
            $decrypted = (new JWEDecrypter($this->algorithmManager()))
                ->decryptUsingKey($jwe, $this->ownPrivateKey(), 0);
        } catch (Throwable $e) {
            throw new RemoteControlCryptoException('Failed to decrypt envelope: ' . $e->getMessage(), 0, $e);
        }

        if (! $decrypted || $jwe->getPayload() === null) {
            throw new RemoteControlCryptoException('Failed to decrypt envelope: decryption unsuccessful.');
        }

        try {
            $payload = json_decode($jwe->getPayload(), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new RemoteControlCryptoException('Decrypted payload is not valid JSON: ' . $e->getMessage(), 0, $e);
        }

        if (! is_array($payload)) {
            throw new RemoteControlCryptoException('Decrypted payload is not a JSON object.');
        }

        return $payload;
    }

    /**
     * Wraps a command request for a protocol v2 client: {"v":2,"jwe":"..."} encrypted to the client key.
     * The reply key is our own public key, since this server is both relay and portal.
     *
     * @param array<string, mixed> $request method, path, body, headers
     * @return array<string, mixed>
     */
    public function encryptCommandRequest(string $messageId, array $request, string $clientPublicKeyPem): array
    {
        $replyKey = $this->ownPrivateKey()->toPublic()->all();

        $plaintext = json_encode([
            'messageId' => $messageId,
            'replyKey' => $replyKey,
            'request' => $request,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $zip = null;
        if (strlen($plaintext) > self::COMPRESSION_THRESHOLD_BYTES) {
            $plaintext = (string) gzencode($plaintext);
            $zip = 'gzip';
        }

        try {
            $recipientKey = JWKFactory::createFromKey($clientPublicKeyPem);

            $jwe = (new JWEBuilder($this->commandAlgorithmManager()))
                ->create()
                ->withPayload($plaintext)
                ->withSharedProtectedHeader(self::COMMAND_PROTECTED_HEADER)
                ->addRecipient($recipientKey)
                ->build();

            $compact = (new CompactSerializer())->serialize($jwe, 0);
        } catch (Throwable $e) {
            throw new RemoteControlCryptoException('Failed to encrypt command payload: ' . $e->getMessage(), 0, $e);
        }

        $wrapper = ['v' => self::COMMAND_PAYLOAD_VERSION, 'jwe' => $compact];
        if ($zip !== null) {
            $wrapper['zip'] = $zip;
        }

        return $wrapper;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function isEncryptedCommandPayload(array $payload): bool
    {
        return ($payload['v'] ?? null) === self::COMMAND_PAYLOAD_VERSION
            && is_string($payload['jwe'] ?? null)
            && $payload['jwe'] !== '';
    }

    /**
     * Decrypts a protocol v2 command response and returns the inner response (statusCode, body, headers).
     *
     * @param array<string, mixed> $wrapper
     * @return array<string, mixed>
     */
    public function decryptCommandResponse(array $wrapper, string $expectedMessageId): array
    {
        $zip = $wrapper['zip'] ?? null;
        if ($zip !== null && $zip !== 'gzip') {
            throw new RemoteControlCryptoException('Unsupported command payload compression.');
        }

        try {
            $jwe = (new CompactSerializer())->unserialize((string) $wrapper['jwe']);
            $header = $jwe->getSharedProtectedHeader();
            if (($header['alg'] ?? null) !== 'RSA-OAEP-256' || ($header['enc'] ?? null) !== 'A256GCM') {
                throw new RemoteControlCryptoException('Unexpected command payload algorithms.');
            }

            $decrypted = (new JWEDecrypter($this->commandAlgorithmManager()))
                ->decryptUsingKey($jwe, $this->ownPrivateKey(), 0);
        } catch (RemoteControlCryptoException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new RemoteControlCryptoException('Failed to decrypt command payload: ' . $e->getMessage(), 0, $e);
        }

        if (! $decrypted || $jwe->getPayload() === null) {
            throw new RemoteControlCryptoException('Failed to decrypt command payload.');
        }

        $plaintext = $jwe->getPayload();
        if ($zip === 'gzip') {
            $plaintext = @gzdecode($plaintext, self::MAX_DECOMPRESSED_BYTES);
            if ($plaintext === false) {
                throw new RemoteControlCryptoException('Command payload could not be decompressed.');
            }
        }

        $decoded = json_decode($plaintext, true);
        if (! is_array($decoded) || ! is_array($decoded['response'] ?? null)) {
            throw new RemoteControlCryptoException('Decrypted command payload has no response.');
        }

        if (($decoded['messageId'] ?? null) !== $expectedMessageId) {
            throw new RemoteControlCryptoException('Command response belongs to another message.');
        }

        return $decoded['response'];
    }

    private function commandAlgorithmManager(): AlgorithmManager
    {
        return new AlgorithmManager([
            new RSAOAEP256(),
            new A256GCM(),
        ]);
    }

    private function ownPrivateKey(): JWK
    {
        if ($this->ownPrivateKey === null) {
            $pem = $this->certificateService->getOrCreateKeyPair()['private'];

            try {
                $this->ownPrivateKey = JWKFactory::createFromKey($pem);
            } catch (Throwable $e) {
                throw new RemoteControlCryptoException('Invalid server private key: ' . $e->getMessage(), 0, $e);
            }
        }

        return $this->ownPrivateKey;
    }

    private function algorithmManager(): AlgorithmManager
    {
        return new AlgorithmManager([
            new RSAOAEP256(),
            new A256CBCHS512(),
        ]);
    }
}
