<?php

declare(strict_types=1);

namespace App\Domain\Organization;

final readonly class OrganizationRequestContext
{
    public function __construct(
        public AuthenticatedAccount $account,
        public OrganizationContext $organization,
    ) {}
}
