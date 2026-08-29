<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

/**
 * The sole authority that turns an authenticated user id into an organization.
 *
 * Organization ids supplied by a browser are selectors only. Every public
 * method derives authority again from an active membership row.
 */
final class OrganizationMembershipService
{
    private const string PERSONAL_ORGANIZATION_NAMESPACE = '6ba7b810-9dad-11d1-80b4-00c04fd430c8';

    public function __construct(
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly ?DatabaseInterface $database = null,
    ) {}

    public function ensurePersonalOrganization(int $userId, string $subjectId, string $displayName): OrganizationContext
    {
        if (!Uuid::isValid($subjectId)) {
            throw new OrganizationAccessDenied('The authenticated account has no stable service identity.');
        }
        $existing = $this->activeMemberships($userId);
        if ($existing !== []) {
            return $this->resolve($userId);
        }

        $organizationRepository = $this->entityTypeManager->getRepository('goformx_organization');
        $membershipRepository = $this->entityTypeManager->getRepository('goformx_organization_membership');
        $now = time();
        $organizationId = Uuid::v5(
            Uuid::fromString(self::PERSONAL_ORGANIZATION_NAMESPACE),
            'goformx.com/personal-organization/' . strtolower($subjectId),
        )->toRfc4122();
        $createdOrganization = null;

        try {
            return $this->transactional(function () use (
                $userId,
                $displayName,
                $organizationId,
                $organizationRepository,
                $membershipRepository,
                $now,
                &$createdOrganization,
            ): OrganizationContext {
                $existing = $this->activeMemberships($userId);
                if ($existing !== []) {
                    return $this->resolve($userId);
                }

                $createdOrganization = $organizationRepository->create([
                    'uuid' => $organizationId,
                    'name' => $this->personalOrganizationName($displayName),
                    'created_by_user_id' => $userId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $organizationRepository->save($createdOrganization);
                $membership = $membershipRepository->create([
                    'organization_uuid' => $organizationId,
                    'user_id' => $userId,
                    'role' => OrganizationRole::Owner->value,
                    'joined_at' => $now,
                ]);
                $membershipRepository->save($membership);

                return $this->contextFromMembership($membership);
            }, 'goformx-personal-organization');
        } catch (\Throwable $exception) {
            // Without a database transaction (unit/in-memory adapters), restore
            // the cross-repository invariant explicitly.
            if ($this->database === null && $createdOrganization instanceof EntityInterface) {
                $organizationRepository->delete($createdOrganization);
            }

            // Every contender derives the same UUID. Once a unique-key loser is
            // rolled back, the winning membership is the only valid result.
            $existing = $this->activeMemberships($userId);
            if ($existing !== []) {
                return $this->resolve($userId);
            }

            throw $exception;
        }
    }

    public function resolve(int $userId, ?string $requestedOrganizationId = null): OrganizationContext
    {
        $memberships = $this->activeMemberships($userId);
        if ($memberships === []) {
            throw new OrganizationAccessDenied('No active organization membership exists.');
        }

        if ($requestedOrganizationId !== null && $requestedOrganizationId !== '') {
            foreach ($memberships as $membership) {
                if ((string) $membership->get('organization_uuid') === $requestedOrganizationId) {
                    return $this->contextFromMembership($membership);
                }
            }

            throw new OrganizationAccessDenied('The requested organization is not available to this account.');
        }

        usort($memberships, static function (EntityInterface $left, EntityInterface $right): int {
            $rank = ['owner' => 0, 'admin' => 1, 'member' => 2];
            $roleOrder = ($rank[(string) $left->get('role')] ?? 3) <=> ($rank[(string) $right->get('role')] ?? 3);

            return $roleOrder !== 0
                ? $roleOrder
                : (int) $left->get('joined_at') <=> (int) $right->get('joined_at');
        });

        return $this->contextFromMembership($memberships[0]);
    }

    public function leave(int $userId, string $organizationId): void
    {
        $authorized = $this->membership($userId, $organizationId);
        if ($authorized === null) {
            throw new OrganizationAccessDenied('The requested organization is not available to this account.');
        }

        $this->transactional(function () use ($userId, $organizationId): void {
            $this->lockOrganization($organizationId);
            $membership = $this->membership($userId, $organizationId);
            if ($membership === null) {
                throw new OrganizationAccessDenied('The requested organization is not available to this account.');
            }
            if ((string) $membership->get('role') === OrganizationRole::Owner->value && $this->activeOwnerCount($organizationId) <= 1) {
                throw new \DomainException('Transfer ownership or delete the organization before its sole owner can leave.');
            }

            $membership->set('status', 'revoked');
            $this->entityTypeManager->getRepository('goformx_organization_membership')->save($membership);
        }, 'goformx-organization-leave');
    }

    /**
     * Revoke all memberships before account deletion. Refuse deletion when it
     * would orphan an organization, making ownership transfer an explicit act.
     */
    public function deleteAccount(int $userId, EntityInterface $account): void
    {
        $this->transactional(function () use ($userId, $account): void {
            $memberships = $this->activeMemberships($userId);
            $organizationIds = array_values(array_unique(array_map(
                static fn(EntityInterface $membership): string => (string) $membership->get('organization_uuid'),
                $memberships,
            )));
            sort($organizationIds, SORT_STRING);
            foreach ($organizationIds as $organizationId) {
                $this->lockOrganization($organizationId);
            }

            // Re-read after acquiring every organization lock so concurrent
            // owner removal cannot make the validation stale.
            $memberships = $this->activeMemberships($userId);
            foreach ($memberships as $membership) {
                $organizationId = (string) $membership->get('organization_uuid');
                if ((string) $membership->get('role') === OrganizationRole::Owner->value && $this->activeOwnerCount($organizationId) <= 1) {
                    throw new \DomainException('Transfer or delete every solely owned organization before deleting the account.');
                }
            }

            $repository = $this->entityTypeManager->getRepository('goformx_organization_membership');
            foreach ($memberships as $membership) {
                $membership->set('status', 'revoked');
                $repository->save($membership);
            }
            $this->entityTypeManager->getRepository('user')->delete($account);
        }, 'goformx-account-delete');
    }

    /** @return list<EntityInterface> */
    private function activeMemberships(int $userId): array
    {
        if ($userId <= 0) {
            throw new OrganizationAccessDenied('Authentication is required.');
        }

        $repository = $this->entityTypeManager->getRepository('goformx_organization_membership');
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition('user_id', $userId)
            ->condition('status', 'active')
            ->execute();

        return $repository->findMany($ids);
    }

    private function membership(int $userId, string $organizationId): ?EntityInterface
    {
        foreach ($this->activeMemberships($userId) as $membership) {
            if (hash_equals((string) $membership->get('organization_uuid'), $organizationId)) {
                return $membership;
            }
        }

        return null;
    }

    private function activeOwnerCount(string $organizationId): int
    {
        $repository = $this->entityTypeManager->getRepository('goformx_organization_membership');
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition('organization_uuid', $organizationId)
            ->condition('role', OrganizationRole::Owner->value)
            ->condition('status', 'active')
            ->execute();

        return count($ids);
    }

    private function lockOrganization(string $organizationId): void
    {
        if ($this->database === null) {
            return;
        }
        $updated = $this->database->update('goformx_organization')
            ->fields(['updated_at' => time()])
            ->condition('uuid', $organizationId)
            ->execute();
        if ($updated !== 1) {
            throw new OrganizationAccessDenied('The organization is unavailable.');
        }
    }

    /**
     * @template T
     * @param \Closure(): T $operation
     * @return T
     */
    private function transactional(\Closure $operation, string $name): mixed
    {
        if ($this->database === null) {
            return $operation();
        }
        $transaction = $this->database->transaction($name);
        try {
            $result = $operation();
            $transaction->commit();

            return $result;
        } catch (\Throwable $exception) {
            try {
                $transaction->rollBack();
            } catch (\Throwable) {
            }

            throw $exception;
        }
    }

    private function contextFromMembership(EntityInterface $membership): OrganizationContext
    {
        $organizationId = (string) $membership->get('organization_uuid');
        $repository = $this->entityTypeManager->getRepository('goformx_organization');
        $ids = $repository->getQuery()
            ->accessCheck(false)
            ->condition('uuid', $organizationId)
            ->condition('status', 'active')
            ->range(0, 1)
            ->execute();
        $organization = $ids === [] ? null : $repository->find((string) $ids[0]);

        if (!$organization instanceof Organization) {
            throw new OrganizationAccessDenied('The organization is unavailable.');
        }

        $role = OrganizationRole::tryFrom((string) $membership->get('role'));
        if ($role === null) {
            throw new OrganizationAccessDenied('The organization membership role is invalid.');
        }

        return new OrganizationContext($organizationId, (string) $organization->get('name'), $role);
    }

    private function personalOrganizationName(string $displayName): string
    {
        $displayName = trim($displayName);

        return ($displayName !== '' ? $displayName : 'My') . "'s workspace";
    }
}
