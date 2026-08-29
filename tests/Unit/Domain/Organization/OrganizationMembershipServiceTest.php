<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Organization;

use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationMembershipService;
use App\Domain\Organization\OrganizationRole;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;
use Waaseyaa\Entity\Storage\EntityQueryInterface;

final class OrganizationMembershipServiceTest extends TestCase
{
    public function testARequestedOrganizationIdNeverOverridesMembershipAuthority(): void
    {
        $membership = new OrganizationMembership([
            'organization_uuid' => '11111111-1111-4111-8111-111111111111',
            'user_id' => 42,
            'role' => OrganizationRole::Owner->value,
            'status' => 'active',
        ]);
        $service = new OrganizationMembershipService($this->managerWithMemberships([$membership]));

        $this->expectException(OrganizationAccessDenied::class);
        $service->resolve(42, '22222222-2222-4222-8222-222222222222');
    }

    public function testAnAuthorizedMembershipResolvesTheOrganizationAndRole(): void
    {
        $organizationId = '11111111-1111-4111-8111-111111111111';
        $membership = new OrganizationMembership([
            'organization_uuid' => $organizationId,
            'user_id' => 42,
            'role' => OrganizationRole::Admin->value,
            'status' => 'active',
        ]);
        $organization = new Organization([
            'organization_id' => 8,
            'uuid' => $organizationId,
            'name' => 'Authorized workspace',
            'created_by_user_id' => 7,
            'status' => 'active',
        ]);

        $manager = $this->managerWithMemberships([$membership], $organization);
        $context = (new OrganizationMembershipService($manager))->resolve(42, $organizationId);

        self::assertSame($organizationId, $context->organizationId);
        self::assertSame('Authorized workspace', $context->name);
        self::assertSame(OrganizationRole::Admin, $context->role);
    }

    /** @param list<OrganizationMembership> $memberships */
    private function managerWithMemberships(array $memberships, ?Organization $organization = null): EntityTypeManagerInterface
    {
        $membershipQuery = $this->createStub(EntityQueryInterface::class);
        $membershipQuery->method('accessCheck')->willReturnSelf();
        $membershipQuery->method('condition')->willReturnSelf();
        $membershipQuery->method('execute')->willReturn(array_keys($memberships));

        $membershipRepository = $this->createStub(EntityRepositoryInterface::class);
        $membershipRepository->method('getQuery')->willReturn($membershipQuery);
        $membershipRepository->method('findMany')->willReturn($memberships);

        $organizationRepository = $this->createStub(EntityRepositoryInterface::class);
        if ($organization !== null) {
            $organizationQuery = $this->createStub(EntityQueryInterface::class);
            $organizationQuery->method('accessCheck')->willReturnSelf();
            $organizationQuery->method('condition')->willReturnSelf();
            $organizationQuery->method('range')->willReturnSelf();
            $organizationQuery->method('execute')->willReturn([8]);
            $organizationRepository->method('getQuery')->willReturn($organizationQuery);
            $organizationRepository->method('find')->willReturn($organization);
        }

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $type): EntityRepositoryInterface => match ($type) {
                'goformx_organization_membership' => $membershipRepository,
                'goformx_organization' => $organizationRepository,
                default => throw new \LogicException('Unexpected repository: ' . $type),
            },
        );

        return $manager;
    }
}
