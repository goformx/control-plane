<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use Waaseyaa\Entity\EntityInterface;

final readonly class AuthenticatedAccount
{
    public function __construct(
        public int $userId,
        public string $subjectId,
        public string $displayName,
        public EntityInterface $entity,
    ) {}
}
