<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Organization;

use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationMembershipService;
use App\Domain\Organization\OrganizationRole;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Database\TransactionInterface;
use Waaseyaa\Database\UpdateInterface;
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

    public function testPersonalProvisioningUsesAStableSubjectDerivedUuidAndCommitsAtomically(): void
    {
        $membershipQuery = $this->createStub(EntityQueryInterface::class);
        $membershipQuery->method('accessCheck')->willReturnSelf();
        $membershipQuery->method('condition')->willReturnSelf();
        $membershipQuery->method('execute')->willReturn([]);

        $membershipRepository = $this->createMock(EntityRepositoryInterface::class);
        $membershipRepository->method('getQuery')->willReturn($membershipQuery);
        $membershipRepository->method('findMany')->willReturn([]);
        $membershipRepository->method('create')->willReturnCallback(
            static fn(array $values): OrganizationMembership => new OrganizationMembership($values),
        );
        $membershipRepository->expects(self::once())->method('save');

        $organization = null;
        $organizationQuery = $this->createStub(EntityQueryInterface::class);
        $organizationQuery->method('accessCheck')->willReturnSelf();
        $organizationQuery->method('condition')->willReturnSelf();
        $organizationQuery->method('range')->willReturnSelf();
        $organizationQuery->method('execute')->willReturn([8]);
        $organizationRepository = $this->createMock(EntityRepositoryInterface::class);
        $organizationRepository->method('getQuery')->willReturn($organizationQuery);
        $organizationRepository->method('create')->willReturnCallback(
            static function (array $values) use (&$organization): Organization {
                return $organization = new Organization($values);
            },
        );
        $organizationRepository->expects(self::once())->method('save');
        $organizationRepository->method('find')->willReturnCallback(
            static function () use (&$organization): ?Organization {
                return $organization;
            },
        );

        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $type): EntityRepositoryInterface => match ($type) {
                'goformx_organization_membership' => $membershipRepository,
                'goformx_organization' => $organizationRepository,
                default => throw new \LogicException('Unexpected repository: ' . $type),
            },
        );
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->expects(self::once())->method('commit');
        $transaction->expects(self::never())->method('rollBack');
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('transaction')->willReturn($transaction);

        $subjectId = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $context = (new OrganizationMembershipService($manager, $database))
            ->ensurePersonalOrganization(42, $subjectId, 'Person');
        $expected = Uuid::v5(
            Uuid::fromString('6ba7b810-9dad-11d1-80b4-00c04fd430c8'),
            'goformx.com/personal-organization/' . $subjectId,
        )->toRfc4122();

        self::assertSame($expected, $context->organizationId);
        self::assertSame($expected, $organization?->uuid());
        self::assertSame(OrganizationRole::Owner, $context->role);
    }

    public function testSoleOwnerLeaveLocksThenRollsBackWithoutRevokingMembership(): void
    {
        $organizationId = '11111111-1111-4111-8111-111111111111';
        $membership = new OrganizationMembership([
            'organization_uuid' => $organizationId,
            'user_id' => 42,
            'role' => OrganizationRole::Owner->value,
            'status' => 'active',
        ]);
        $activeQuery = $this->createStub(EntityQueryInterface::class);
        $activeQuery->method('accessCheck')->willReturnSelf();
        $activeQuery->method('condition')->willReturnSelf();
        $activeQuery->method('execute')->willReturn([1]);
        $ownerQuery = $this->createStub(EntityQueryInterface::class);
        $ownerQuery->method('accessCheck')->willReturnSelf();
        $ownerQuery->method('condition')->willReturnSelf();
        $ownerQuery->method('execute')->willReturn([1]);
        $membershipRepository = $this->createMock(EntityRepositoryInterface::class);
        $membershipRepository->method('getQuery')->willReturnOnConsecutiveCalls(
            $activeQuery,
            $activeQuery,
            $ownerQuery,
        );
        $membershipRepository->method('findMany')->willReturn([$membership]);
        $membershipRepository->expects(self::never())->method('save');
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $type): EntityRepositoryInterface => $type === 'goformx_organization_membership'
                ? $membershipRepository
                : throw new \LogicException('Unexpected repository: ' . $type),
        );

        $update = $this->createMock(UpdateInterface::class);
        $update->expects(self::once())->method('fields')->willReturnSelf();
        $update->expects(self::once())->method('condition')->with('uuid', $organizationId)->willReturnSelf();
        $update->expects(self::once())->method('execute')->willReturn(1);
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->expects(self::never())->method('commit');
        $transaction->expects(self::once())->method('rollBack');
        $database = $this->createMock(DatabaseInterface::class);
        $database->expects(self::once())->method('update')->with('goformx_organization')->willReturn($update);
        $database->method('transaction')->willReturn($transaction);

        try {
            (new OrganizationMembershipService($manager, $database))->leave(42, $organizationId);
            self::fail('A sole owner must not be able to leave.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('sole owner', $exception->getMessage());
        }
        self::assertSame('active', $membership->get('status'));
    }

    public function testSoleOwnerAccountDeletionRollsBackBeforeDeletingTheUser(): void
    {
        $organizationId = '11111111-1111-4111-8111-111111111111';
        $membership = new OrganizationMembership([
            'organization_uuid' => $organizationId,
            'user_id' => 42,
            'role' => OrganizationRole::Owner->value,
            'status' => 'active',
        ]);
        $activeQuery = $this->createStub(EntityQueryInterface::class);
        $activeQuery->method('accessCheck')->willReturnSelf();
        $activeQuery->method('condition')->willReturnSelf();
        $activeQuery->method('execute')->willReturn([1]);
        $ownerQuery = $this->createStub(EntityQueryInterface::class);
        $ownerQuery->method('accessCheck')->willReturnSelf();
        $ownerQuery->method('condition')->willReturnSelf();
        $ownerQuery->method('execute')->willReturn([1]);
        $membershipRepository = $this->createMock(EntityRepositoryInterface::class);
        $membershipRepository->method('getQuery')->willReturnOnConsecutiveCalls(
            $activeQuery,
            $activeQuery,
            $ownerQuery,
        );
        $membershipRepository->method('findMany')->willReturn([$membership]);
        $membershipRepository->expects(self::never())->method('save');
        $userRepository = $this->createMock(EntityRepositoryInterface::class);
        $userRepository->expects(self::never())->method('delete');
        $manager = $this->createStub(EntityTypeManagerInterface::class);
        $manager->method('getRepository')->willReturnCallback(
            static fn(string $type): EntityRepositoryInterface => match ($type) {
                'goformx_organization_membership' => $membershipRepository,
                'user' => $userRepository,
                default => throw new \LogicException('Unexpected repository: ' . $type),
            },
        );

        $update = $this->createStub(UpdateInterface::class);
        $update->method('fields')->willReturnSelf();
        $update->method('condition')->willReturnSelf();
        $update->method('execute')->willReturn(1);
        $transaction = $this->createMock(TransactionInterface::class);
        $transaction->expects(self::never())->method('commit');
        $transaction->expects(self::once())->method('rollBack');
        $database = $this->createStub(DatabaseInterface::class);
        $database->method('update')->willReturn($update);
        $database->method('transaction')->willReturn($transaction);

        try {
            (new OrganizationMembershipService($manager, $database))->deleteAccount(
                42,
                $this->createStub(\Waaseyaa\Entity\EntityInterface::class),
            );
            self::fail('A sole owner account must not be deleted.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString('solely owned', $exception->getMessage());
        }
        self::assertSame('active', $membership->get('status'));
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
