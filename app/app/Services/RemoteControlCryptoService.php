<?php

namespace App\Services;

use App\Exceptions\RemoteControlCryptoException;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Core\JWK;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;
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
