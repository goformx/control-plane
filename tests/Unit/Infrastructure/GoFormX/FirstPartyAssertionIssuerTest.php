<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\GoFormX;

use App\Domain\GoFormX\ManagementScope;
use App\Infrastructure\GoFormX\FirstPartyAssertionIssuer;
use App\Infrastructure\GoFormX\SigningKey;
use PHPUnit\Framework\TestCase;

final class FirstPartyAssertionIssuerTest extends TestCase
{
    public function testItIssuesTheExactSignedSingleRequestProfile(): void
    {
        $seed = str_repeat("\x11", SODIUM_CRYPTO_SIGN_SEEDBYTES);
        $key = SigningKey::fromBase64Seed('gofx-fpa-test-a', base64_encode($seed));
        $issuer = new FirstPartyAssertionIssuer('https://goformx.com', 'https://api.goformx.com', $key);
        $now = new \DateTimeImmutable('2026-08-29T22:00:00.987654Z');
        $requestId = '44444444-4444-4444-8444-444444444444';

        $issued = $issuer->issue(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            [ManagementScope::FormsRead, ManagementScope::FormsWrite],
            $requestId,
            $now,
        );

        [$encodedHeader, $encodedClaims, $encodedSignature] = explode('.', $issued->compact);
        $header = json_decode($this->decode($encodedHeader), true, 8, JSON_THROW_ON_ERROR);
        $claims = json_decode($this->decode($encodedClaims), true, 16, JSON_THROW_ON_ERROR);
        self::assertSame(['alg' => 'EdDSA', 'typ' => 'gofx-fpa+jwt', 'kid' => 'gofx-fpa-test-a'], $header);
        self::assertSame('https://goformx.com', $claims['iss']);
        self::assertSame('https://api.goformx.com', $claims['aud']);
        self::assertSame(['forms:read', 'forms:write'], $claims['scp']);
        self::assertSame($claims['iat'], $claims['nbf']);
        self::assertSame(60, $claims['exp'] - $claims['iat']);
        self::assertSame($requestId, $claims['rid']);
        self::assertSame(1, $claims['ver']);
        self::assertSame($claims['jti'], $issued->assertionId);
        self::assertSame('2026-08-29T22:01:00+00:00', $issued->expiresAt->format(DATE_ATOM));

        $publicKey = $this->decode($key->publicJwk()['x']);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            $this->decode($encodedSignature),
            $encodedHeader . '.' . $encodedClaims,
            $publicKey,
        ));
        self::assertStringNotContainsString(base64_encode($seed), $issued->compact);
    }

    public function testItRejectsDuplicateOrUnknownScopeValuesBeforeSigning(): void
    {
        $key = SigningKey::fromBase64Seed('test', base64_encode(str_repeat("\x22", 32)));
        $issuer = new FirstPartyAssertionIssuer('https://goformx.com', 'https://api.goformx.com', $key);

        $this->expectException(\InvalidArgumentException::class);
        $issuer->issue(
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            [ManagementScope::FormsRead, ManagementScope::FormsRead],
        );
    }

    private function decode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        self::assertIsString($decoded);

        return $decoded;
    }
}
