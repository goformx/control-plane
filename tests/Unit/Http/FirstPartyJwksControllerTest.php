<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\FirstPartyJwksController;
use App\Infrastructure\GoFormX\JwksDocument;
use App\Infrastructure\GoFormX\SigningKey;
use PHPUnit\Framework\TestCase;

final class FirstPartyJwksControllerTest extends TestCase
{
    public function testItPublishesOnlyPublicVerificationMaterial(): void
    {
        $seed = base64_encode(str_repeat("\x12", 32));
        $document = JwksDocument::fromConfiguration(SigningKey::fromBase64Seed('active', $seed), '[]');
        $response = (new FirstPartyJwksController(static fn(): JwksDocument => $document))->show();
        $body = (string) $response->getContent();

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('max-age=15, public, stale-if-error=60', $response->headers->get('Cache-Control'));
        self::assertStringContainsString('"kty":"OKP"', $body);
        self::assertStringContainsString('"state":"active"', $body);
        self::assertStringNotContainsString($seed, $body);
        self::assertStringNotContainsString('private', strtolower($body));
    }

    public function testItFailsClosedWithoutConfiguredSigningCustody(): void
    {
        $response = (new FirstPartyJwksController(
            static fn(): JwksDocument => throw new \RuntimeException('secret detail'),
        ))->show();

        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('secret detail', (string) $response->getContent());
    }
}
