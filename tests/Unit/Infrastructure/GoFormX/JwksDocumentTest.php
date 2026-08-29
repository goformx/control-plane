<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\GoFormX;

use App\Infrastructure\GoFormX\JwksDocument;
use App\Infrastructure\GoFormX\SigningKey;
use PHPUnit\Framework\TestCase;

final class JwksDocumentTest extends TestCase
{
    public function testItPublishesDerivedActiveAndValidatedOverlapKeys(): void
    {
        $active = SigningKey::fromBase64Seed('active', base64_encode(str_repeat("\x33", 32)));
        $next = SigningKey::fromBase64Seed('next', base64_encode(str_repeat("\x44", 32)))->publicJwk();
        $next['state'] = 'next';
        $document = JwksDocument::fromConfiguration($active, json_encode([$next], JSON_THROW_ON_ERROR));

        self::assertSame(['active', 'next'], array_column($document->toArray()['keys'], 'kid'));
        self::assertSame(['active', 'next'], array_column($document->toArray()['keys'], 'state'));
    }

    public function testItRejectsASecondActiveOrDuplicateKey(): void
    {
        $active = SigningKey::fromBase64Seed('active', base64_encode(str_repeat("\x55", 32)));
        $duplicate = $active->publicJwk();
        $duplicate['state'] = 'retiring';

        $this->expectException(\InvalidArgumentException::class);
        JwksDocument::fromConfiguration($active, json_encode([$duplicate], JSON_THROW_ON_ERROR));
    }
}
