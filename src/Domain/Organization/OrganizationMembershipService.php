<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use App\Entity\Organization;
use App\Entity\OrganizationMembership;
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
    public function __construct(private readonly EntityTypeManagerInterface $entityTypeManager) {}

    public function ensurePersonalOrganization(int $userId, string $displayName): OrganizationContext
    {
        $existing = $this->activeMemberships($userId);
        if ($existing !== []) {
            return $this->contextFromMembership($existing[0]);
        }

        $organizationRepository = $this->entityTypeManager->getRepository('goformx_organization');
        $membershipRepository = $this->entityTypeManager->getRepository('goformx_organization_membership');
        $now = time();
        $organization = $organizationRepository->create([
            'name' => $this->personalOrganizationName($displayName),
            'created_by_user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $organizationRepository->save($organization);

        try {
            $membership = $membershipRepository->create([
                'organization_uuid' => (string) $organization->uuid(),
                'user_id' => $userId,
                'role' => OrganizationRole::Owner->value,
                'joined_at' => $now,
            ]);
            $membershipRepository->save($membership);
        } catch (\Throwable $exception) {
            // Cross-repository saveMany() is intentionally unavailable. Restore
            // the invariant explicitly if the second half cannot be persisted.
            $organizationRepository->delete($organization);

            // A concurrent request may have won the unique membership race.
            $existing = $this->activeMemberships($userId);
            if ($existing !== []) {
                return $this->contextFromMembership($existing[0]);
            }

            throw $exception;
        }

        return $this->contextFromMembership($membership);
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
        $membership = $this->membership($userId, $organizationId);
        if ($membership === null) {
            throw new OrganizationAccessDenied('The requested organization is not available to this account.');
        }

        if ((string) $membership->get('role') === OrganizationRole::Owner->value && $this->activeOwnerCount($organizationId) <= 1) {
            throw new \DomainException('Transfer ownership or delete the organization before its sole owner can leave.');
        }

        $membership->set('status', 'revoked');
        $this->entityTypeManager->getRepository('goformx_organization_membership')->save($membership);
    }

    /**
     * Revoke all memberships before account deletion. Refuse deletion when it
     * would orphan an organization, making ownership transfer an explicit act.
     */
    public function revokeForAccountDeletion(int $userId): void
    {
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
