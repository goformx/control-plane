<?php

declare(strict_types=1);

namespace App\Domain\GoFormX;

use App\Domain\Organization\OrganizationRole;
use Symfony\Component\Uid\Uuid;

/** Organization-owned site management uses the canonical forms scopes. */
enum SiteOperation: string
{
    case List = 'list';
    case Create = 'create';
    case Get = 'get';

    public function method(): string
    {
        return $this === self::Create ? 'POST' : 'GET';
    }

    public function operationId(): string
    {
        return match ($this) {
            self::List => 'listSites',
            self::Create => 'createSite',
            self::Get => 'getSite',
        };
    }

    public function scope(): ManagementScope
    {
        return $this === self::Create ? ManagementScope::FormsWrite : ManagementScope::FormsRead;
    }

    public function allowedFor(OrganizationRole $role): bool
    {
        return $this !== self::Create || $role !== OrganizationRole::Member;
    }

    public function template(): string
    {
        return $this === self::Get ? '/v1/sites/{siteId}' : '/v1/sites';
    }

    public function path(string $siteId = ''): string
    {
        if ($this === self::Get && !Uuid::isValid($siteId)) {
            throw new \InvalidArgumentException('siteId must be a UUID.');
        }
        return str_replace('{siteId}', $siteId, $this->template());
    }
}
