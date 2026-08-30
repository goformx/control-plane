<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Domain\Organization\OrganizationMembershipService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Auth\Event\AuthLifecycleEvent;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\DevAdminAccount;

final class OrganizationPersistenceTest extends TestCase
{
    public function testMembershipRoleAndRevocationChangesSurviveRepositoryReload(): void
    {
        $kernel = new HttpKernel(dirname(__DIR__, 2));
        $kernel->bootForCli();
        $repository = $kernel->getEntityTypeManager()->getRepository('goformx_organization_membership');
        $kernel->accountContext()->set(new DevAdminAccount());
        $joinedAt = time();
        // Repository creation uses field definitions, not constructor defaults.
        // Supply the timestamp just as production personal provisioning does.
        $membership = $repository->create([
            'organization_uuid' => Uuid::v4()->toRfc4122(),
            'user_id' => random_int(1_000_000, 2_000_000_000),
            'role' => 'member',
            'joined_at' => $joinedAt,
        ]);

        try {
            $repository->save($membership);
            foreach ([['member', 'active'], ['admin', 'active'], ['member', 'active'], ['member', 'revoked']] as [$role, $status]) {
                $membership->set('role', $role);
                $membership->set('status', $status);
                $repository->save($membership);
                $reloaded = $repository->find((string) $membership->id());
                self::assertInstanceOf(EntityInterface::class, $reloaded);
                self::assertSame($role, $reloaded->get('role'));
                self::assertSame($status, $reloaded->get('status'));
                self::assertSame($joinedAt, $reloaded->get('joined_at'));
            }
        } finally {
            if ($membership->id() !== null) {
                $repository->delete($membership);
            }
            $kernel->accountContext()->set(null);
        }
    }

    public function testAuthLifecycleEventsReachTheAppendOnlyAuditLedger(): void
    {
        $kernel = new HttpKernel(dirname(__DIR__, 2));
        $kernel->bootForCli();
        $userId = random_int(1_000_000, 2_000_000_000);
        $kernel->getEventDispatcher()->dispatch(
            new AuthLifecycleEvent((string) $userId, 'login_succeeded', ['two_factor' => true]),
            AuthLifecycleEvent::NAME,
        );

        $events = iterator_to_array($kernel->getDatabase()->select('audit_event', 'ae')
            ->fields('ae', ['actor_uid', 'attributes'])
            ->condition('actor_uid', $userId)
            ->orderBy('id', 'DESC')
            ->range(0, 1)
            ->execute());

        self::assertCount(1, $events);
        self::assertSame($userId, (int) $events[0]['actor_uid']);
        self::assertStringContainsString('auth.login_succeeded', (string) $events[0]['attributes']);
        self::assertStringNotContainsString('password', (string) $events[0]['attributes']);
    }

    public function testRepeatedPersonalProvisioningPersistsOneOrganizationAndMembership(): void
    {
        $root = dirname(__DIR__, 2);
        $kernel = new HttpKernel($root);
        $kernel->bootForCli();
        $manager = $kernel->getEntityTypeManager();
        $service = new OrganizationMembershipService($manager, $kernel->getDatabase());
        $accountContext = $kernel->accountContext();
        $accountContext->set(new DevAdminAccount());
        $userId = random_int(1_000_000, 2_000_000_000);
        $subjectId = Uuid::v4()->toRfc4122();
        $organizationId = '';

        try {
            $first = $service->ensurePersonalOrganization($userId, $subjectId, 'Persistence');
            $second = $service->ensurePersonalOrganization($userId, $subjectId, 'Changed display name');
            $organizationId = $first->organizationId;

            self::assertSame($first->organizationId, $second->organizationId);
            self::assertSame(1, $this->countMatching($manager, 'goformx_organization', 'uuid', $organizationId));
            self::assertSame(1, $this->countMatching($manager, 'goformx_organization_membership', 'user_id', $userId));
            $events = iterator_to_array($kernel->getDatabase()->select('audit_event', 'ae')
                ->fields('ae', ['actor_uid', 'attributes'])
                ->condition('entity_type_id', 'goformx_organization')
                ->condition('entity_uuid', $organizationId)
                ->orderBy('id', 'DESC')
                ->range(0, 1)
                ->execute());
            self::assertCount(1, $events);
            self::assertSame(PHP_INT_MAX, (int) $events[0]['actor_uid']);
            self::assertStringContainsString('"is_new":true', (string) $events[0]['attributes']);
        } finally {
            $accountContext->set(null);
            $this->deleteMatching($manager, 'goformx_organization_membership', 'user_id', $userId);
            if ($organizationId !== '') {
                $this->deleteMatching($manager, 'goformx_organization', 'uuid', $organizationId);
            }
        }
    }

    private function countMatching(EntityTypeManagerInterface $manager, string $entityType, string $field, string|int $value): int
    {
        return count($manager->getRepository($entityType)->getQuery()
            ->accessCheck(false)
            ->condition($field, $value)
            ->execute());
    }

    private function deleteMatching(EntityTypeManagerInterface $manager, string $entityType, string $field, string|int $value): void
    {
        $repository = $manager->getRepository($entityType);
        $ids = $repository->getQuery()->accessCheck(false)->condition($field, $value)->execute();
        foreach ($repository->findMany($ids) as $entity) {
            if ($entity instanceof EntityInterface) {
                $repository->delete($entity);
            }
        }
    }
}
