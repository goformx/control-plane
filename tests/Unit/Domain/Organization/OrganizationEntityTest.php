<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Organization;

use App\Domain\Organization\OrganizationRole;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use PHPUnit\Framework\TestCase;

final class OrganizationEntityTest extends TestCase
{
    public function testRolesExposeOnlyTheClosedApplicationSet(): void
    {
        self::assertSame(['owner', 'admin', 'member'], array_column(OrganizationRole::cases(), 'value'));
        self::assertTrue(OrganizationRole::Owner->canManageMembers());
        self::assertTrue(OrganizationRole::Admin->canManageMembers());
        self::assertFalse(OrganizationRole::Member->canManageMembers());
    }

    public function testOrganizationRequiresAnAuthenticatedCreator(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('creator');

        new Organization(['name' => 'Example', 'created_by_user_id' => 0]);
    }

    public function testMembershipRejectsUnknownRoles(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('role');

        new OrganizationMembership([
            'organization_uuid' => '79b8829a-ac2f-4a74-b7f0-34af64a62df1',
            'user_id' => 7,
            'role' => 'superuser',
        ]);
    }
}
