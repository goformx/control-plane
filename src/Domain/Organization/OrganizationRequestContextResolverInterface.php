<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use Symfony\Component\HttpFoundation\Request;

interface OrganizationRequestContextResolverInterface
{
    public function account(Request $request): AuthenticatedAccount;

    public function resolve(Request $request): OrganizationRequestContext;
}
