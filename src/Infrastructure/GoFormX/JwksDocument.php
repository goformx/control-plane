<?php

declare(strict_types=1);

namespace App\Infrastructure\GoFormX;

final readonly class JwksDocument
{
    private const STATES = ['next', 'retiring', 'revoked'];

    /** @param list<array<string, string>> $keys */
    private function __construct(private array $keys) {}

    public static function fromConfiguration(SigningKey $active, string $additionalJson): self
    {
        $additional = json_decode($additionalJson, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($additional) || !array_is_list($additional)) {
            throw new \InvalidArgumentException('Additional first-party JWKs must be a JSON array.');
        }
        $keys = [$active->publicJwk()];
        $ids = [$active->id => true];
        foreach ($additional as $item) {
            if (!is_array($item) || array_diff(array_keys($item), ['kty', 'crv', 'x', 'kid', 'use', 'alg', 'state']) !== []) {
                throw new \InvalidArgumentException('An additional first-party JWK has an invalid shape.');
            }
            $key = array_map(static fn(mixed $value): string => is_string($value) ? $value : '', $item);
            $decoded = self::decodeBase64Url($key['x'] ?? '');
            if (($key['kty'] ?? '') !== 'OKP' || ($key['crv'] ?? '') !== 'Ed25519' ||
                ($key['use'] ?? '') !== 'sig' || ($key['alg'] ?? '') !== 'EdDSA' ||
                !in_array($key['state'] ?? '', self::STATES, true) ||
                preg_match('/\A[A-Za-z0-9._-]{1,128}\z/', $key['kid'] ?? '') !== 1 ||
                !is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES ||
                isset($ids[$key['kid']])) {
                throw new \InvalidArgumentException('An additional first-party JWK is invalid or duplicated.');
            }
            $ids[$key['kid']] = true;
            $keys[] = $key;
        }

        return new self($keys);
    }

    /** @return array{keys: list<array<string, string>>} */
    public function toArray(): array
    {
        return ['keys' => $this->keys];
    }

    private static function decodeBase64Url(string $value): string|false
    {
        if ($value === '' || preg_match('/\A[A-Za-z0-9_-]+\z/', $value) !== 1) {
            return false;
        }
        $padding = (4 - strlen($value) % 4) % 4;

        return base64_decode(strtr($value, '-_', '+/') . str_repeat('=', $padding), true);
    }
}
