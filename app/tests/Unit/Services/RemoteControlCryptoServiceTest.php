<?php

use App\Exceptions\RemoteControlCryptoException;
use App\Services\RemoteControlCertificateService;
use App\Services\RemoteControlCryptoService;
use Jose\Component\Core\AlgorithmManager;
use Jose\Component\Encryption\Algorithm\ContentEncryption\A256CBCHS512;
use Jose\Component\Encryption\Algorithm\KeyEncryption\RSAOAEP256;
use Jose\Component\Encryption\JWEBuilder;
use Jose\Component\Encryption\JWEDecrypter;
use Jose\Component\Encryption\Serializer\CompactSerializer;
use Jose\Component\KeyManagement\JWKFactory;
use Tests\TestCase;

uses(TestCase::class);

// Static fixture: a PKCS#1 "RSA PUBLIC KEY" PEM, the exact format .NET's
// RSA.ExportRSAPublicKeyPem() produces for the Duplicati client's own key.
const FIXTURE_CLIENT_PRIVATE_PEM = <<<'PEM'
-----BEGIN PRIVATE KEY-----
MIIEvgIBADANBgkqhkiG9w0BAQEFAASCBKgwggSkAgEAAoIBAQC6MwxR7Oq6xPId
Q8FtY1ge2NAsrjUsZMzPStTxGy9PI53cQjtYCWY8hY21xNvmbS6jc93jOwOLR3n7
l1EySw56ke0+jUJh6oKuGlGWwtB3l/1tMHsdj+ut9fpH4hi6fvyzLgd3ecAPjN89
ig2zAu1M6YCORZVqXfqjLhNyFD8227dUtrA+Uwou9xsrg8yTPXuCINMwp2zvJ7vX
m415TfiyqNbOl0dNa7bNTNsExjECxzkeW12JtHLE7WkyOJD2QPmq2mJbdWMZOqju
jbH8ih6ZucO6wdA1/KmhWDkpCpq7PMmtx0AgLrgd50RIXKf/qxThO9WBPIyO3osV
GlDYM23RAgMBAAECggEABHu6LjbQKBMZ6U56V9Huiz+ZM5o2OY1ryQn6Opv29abb
5DPMDgCb75UfpmLJELFMUC84BoKjQTVFYKNyFw3rmVPuLHVmRclJwDtt/31NW7PT
LE/ghwOyHwwvLI/rHIJ2PzI6h8vsrfMsZOTwE4FciV+N8t8GC/ubT1aKrVKDeySg
rKZELBqqKc+pJUtkHfKmdRbbf+gGFbFAH+AZzy6fX+ML44Q0L4ITvHRJQ0QjgpgD
CHmp1HC8z/lijt3b6CDzUjgrxFAyErQ9BzeQ9oiBGMy9DbR3dXE76ftsKoQM68L8
hi0qqSoTTy/ZQaBwjf2KkwsYXIUy+FzHf+jiDFCYaQKBgQDlQnvBzU6GjZGffpDH
ykvanE4Rvhk/Z+yYOtuZvJB1RBz1kZj/eyjAxPs5VoPZ0JLhN27vKLyY+UlpMPNp
B/iv6OxzoIhNOpHoAl28FmmkQscT1WfPUNCu7YdrzvXXpxFVzkE1ltCvJNW8RV3l
r7XyfyNn0NnlKYH0zSwvSq6sxwKBgQDP6tFZIRB50cULQco7ZtUCmlW+FDbmMVtK
XXF7zYrOn8vVynGDPpbLcR3Df08xcJQet8N/4gMA8JjhygtGwbEtqgBt1ykMUH6d
+BiR8t4F+I5zzHImORhKpaWf7/J14zDsD0UDWEEUxfCwVHv4wIey/4NF17drxCUg
Y8WUitGIpwKBgQC10ixkX3dPryc23yHj0cSm9B/WYnWTm9BASy2ul6XZ2tM8zuQQ
epSvjYnQx5R6Qri6dUQ92Ij6qXpXOsNRIUlR+eDNMxvmY/Ujk/zTFtNhurQ4Pp++
A9H9adRk5BaFmbJd4t5Eqf+HaCc60ajOaLRcRgjGZRTqcijnBXFtcEVoMQKBgQCV
EsrpOdDDV0kNSi2Cq7c8d4XUlE/MekT3K8prNrM4dNBESDNdI6og0cjCW+xfCVXa
NG3Cqjz3Foq3ERNGtyefEw3ds3Al+SzffHu5YUYZR95zXnnVuAgXtW7sHrFcHvn7
tvMHc+aty0JIWzrL3tcs2xC3KfTEGs13zLFkUKftbwKBgHYxy1bGribNFLeIucRy
T0gGJtiU6hQCTCK95FPPjj8NweD7zY48xhFKC6q2EqFqaTG+I1lFecEV0ODC26JJ
8x3KC1K7zNtm+0QNEjGx/+GDeSY8qR1pZP/Ax0Fd0NQL6y7oiVUW3VVVpy/ENY3f
ztuYF3TqqxZnPw187yWILPdw
-----END PRIVATE KEY-----
PEM;

const FIXTURE_CLIENT_PUBLIC_PKCS1_PEM = <<<'PEM'
-----BEGIN RSA PUBLIC KEY-----
MIIBCgKCAQEAujMMUezqusTyHUPBbWNYHtjQLK41LGTMz0rU8RsvTyOd3EI7WAlm
PIWNtcTb5m0uo3Pd4zsDi0d5+5dRMksOepHtPo1CYeqCrhpRlsLQd5f9bTB7HY/r
rfX6R+IYun78sy4Hd3nAD4zfPYoNswLtTOmAjkWVal36oy4TchQ/Ntu3VLawPlMK
LvcbK4PMkz17giDTMKds7ye715uNeU34sqjWzpdHTWu2zUzbBMYxAsc5HltdibRy
xO1pMjiQ9kD5qtpiW3VjGTqo7o2x/IoembnDusHQNfypoVg5KQqauzzJrcdAIC64
HedESFyn/6sU4TvVgTyMjt6LFRpQ2DNt0QIDAQAB
-----END RSA PUBLIC KEY-----
PEM;

function flipBase64UrlChar(string $value): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-_';
    $pos = (int) floor(strlen($value) / 2);
    $current = $value[$pos];
    $replacement = $current === 'A' ? 'B' : 'A';

    return substr($value, 0, $pos).$replacement.substr($value, $pos + 1);
}

test('round trips an envelope through encrypt and decrypt using a fresh server key pair', function () {
    $crypto = new RemoteControlCryptoService(new RemoteControlCertificateService);

    $envelope = [
        'from' => 'client',
        'to' => 'server',
        'type' => 'auth',
        'messageId' => 'msg-1',
        'payload' => json_encode(['token' => 'abc123']),
        'errorMessage' => null,
    ];

    $jwe = $crypto->encryptEnvelopeFor($envelope, $crypto->ourPublicKeyPem());
    $decrypted = $crypto->decryptEnvelope($jwe);

    expect($decrypted)->toBe($envelope);
});

test('encrypts for a recipient using a PKCS#1 RSA PUBLIC KEY PEM, matching what the .NET client produces', function () {
    $crypto = new RemoteControlCryptoService(new RemoteControlCertificateService);

    $envelope = [
        'from' => 'server',
        'to' => 'client',
        'type' => 'welcome',
        'messageId' => 'msg-2',
        'payload' => json_encode(['hello' => 'world']),
        'errorMessage' => null,
    ];

    $jwe = $crypto->encryptEnvelopeFor($envelope, FIXTURE_CLIENT_PUBLIC_PKCS1_PEM);

    // Decrypt with the matching private key directly via the jwt-library, simulating the client side.
    $privateJwk = JWKFactory::createFromKey(FIXTURE_CLIENT_PRIVATE_PEM);
    $jweObject = (new CompactSerializer)->unserialize($jwe);
    $decrypter = new JWEDecrypter(new AlgorithmManager([
        new RSAOAEP256,
        new A256CBCHS512,
    ]));

    expect($decrypter->decryptUsingKey($jweObject, $privateJwk, 0))->toBeTrue()
        ->and(json_decode($jweObject->getPayload(), true))->toBe($envelope);
});

test('decrypts an envelope encrypted by a client with our PKCS#1 public key fixture', function () {
    $crypto = new RemoteControlCryptoService(new RemoteControlCertificateService);

    $envelope = [
        'from' => 'client',
        'to' => 'server',
        'type' => 'ping',
        'messageId' => 'msg-3',
        'payload' => null,
        'errorMessage' => null,
    ];

    $serverPublicJwk = JWKFactory::createFromKey($crypto->ourPublicKeyPem());
    $jwe = (new JWEBuilder(new AlgorithmManager([
        new RSAOAEP256,
        new A256CBCHS512,
    ])))
        ->create()
        ->withPayload(json_encode($envelope))
        ->withSharedProtectedHeader(['alg' => 'RSA-OAEP-256', 'enc' => 'A256CBC-HS512'])
        ->addRecipient($serverPublicJwk)
        ->build();

    $compact = (new CompactSerializer)->serialize($jwe, 0);

    expect($crypto->decryptEnvelope($compact))->toBe($envelope);
});

test('decryptEnvelope throws RemoteControlCryptoException for a tampered ciphertext', function () {
    $crypto = new RemoteControlCryptoService(new RemoteControlCertificateService);

    $envelope = ['from' => 'client', 'to' => 'server', 'type' => 'ping', 'messageId' => 'msg-4', 'payload' => null, 'errorMessage' => null];
    $jwe = $crypto->encryptEnvelopeFor($envelope, $crypto->ourPublicKeyPem());

    $parts = explode('.', $jwe);
    $parts[3] = flipBase64UrlChar($parts[3]);
    $tampered = implode('.', $parts);

    $crypto->decryptEnvelope($tampered);
})->throws(RemoteControlCryptoException::class);

test('decryptEnvelope throws RemoteControlCryptoException for malformed input', function () {
    $crypto = new RemoteControlCryptoService(new RemoteControlCertificateService);

    $crypto->decryptEnvelope('not-a-valid-jwe');
})->throws(RemoteControlCryptoException::class);

test('encryptEnvelopeFor throws RemoteControlCryptoException for an invalid recipient PEM', function () {
    $crypto = new RemoteControlCryptoService(new RemoteControlCertificateService);

    $crypto->encryptEnvelopeFor(['type' => 'ping'], 'not a pem');
})->throws(RemoteControlCryptoException::class);
