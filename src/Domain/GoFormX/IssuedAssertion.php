<?php

declare(strict_types=1);

namespace App\Domain\GoFormX;

final readonly class IssuedAssertion
{
    public function __construct(
        public string $compact,
        public string $assertionId,
        public string $requestId,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
