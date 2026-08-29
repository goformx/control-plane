<?php

declare(strict_types=1);

namespace App\Domain\Organization;

final readonly class OrganizationContext
{
    public function __construct(
        public string $organizationId,
        public string $name,
        public OrganizationRole $role,
    ) {}

    /** @return array{organization_id: string, name: string, role: string} */
    public function toArray(): array
    {
        return [
            'organization_id' => $this->organizationId,
            'name' => $this->name,
            'role' => $this->role->value,
        ];
    }
}
