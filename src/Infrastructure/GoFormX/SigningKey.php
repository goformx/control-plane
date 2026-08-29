<?php

declare(strict_types=1);

namespace App\Infrastructure\GoFormX;

final readonly class SigningKey
{
    private const KEY_ID_PATTERN = '/\A[A-Za-z0-9._-]{1,128}\z/';

    private function __construct(
        public string $id,
        #[\SensitiveParameter]
        private string $secretKey,
        private string $publicKey,
    ) {}

    public static function fromBase64Seed(string $id, #[\SensitiveParameter] string $encodedSeed): self
    {
        if (!extension_loaded('sodium')) {
            throw new \RuntimeException('The sodium extension is required for first-party assertion signing.');
        }
        if (preg_match(self::KEY_ID_PATTERN, $id) !== 1) {
            throw new \InvalidArgumentException('A valid first-party assertion key id is required.');
        }
        $seed = base64_decode($encodedSeed, true);
        if (!is_string($seed) || strlen($seed) !== SODIUM_CRYPTO_SIGN_SEEDBYTES) {
            throw new \InvalidArgumentException('The first-party assertion signing seed must be 32 bytes encoded as base64.');
        }
        $pair = sodium_crypto_sign_seed_keypair($seed);

        return new self(
            $id,
            sodium_crypto_sign_secretkey($pair),
            sodium_crypto_sign_publickey($pair),
        );
    }

    public function sign(string $message): string
    {
        return sodium_crypto_sign_detached($message, $this->secretKey);
    }

    /** @return array{kty: string, crv: string, x: string, kid: string, use: string, alg: string, state: string} */
    public function publicJwk(): array
    {
        return [
            'kty' => 'OKP',
            'crv' => 'Ed25519',
            'x' => self::base64Url($this->publicKey),
            'kid' => $this->id,
            'use' => 'sig',
            'alg' => 'EdDSA',
            'state' => 'active',
        ];
    }

    public static function base64Url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
