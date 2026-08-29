<?php

declare(strict_types=1);

namespace App\Domain\Organization;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final readonly class AuthenticatedOrganizationResolver implements OrganizationRequestContextResolverInterface
{
    public const string SESSION_KEY = 'goformx_organization_uuid';

    public function __construct(
        private OrganizationMembershipService $memberships,
        private EntityTypeManagerInterface $entityTypeManager,
        private UserInternalFieldReaderInterface $internalFields,
    ) {}

    public function account(Request $request): AuthenticatedAccount
    {
        $account = $request->attributes->get('_account');
        if (!$account instanceof AccountInterface || !$account->isAuthenticated() || (int) $account->id() <= 0) {
            throw new OrganizationAccessDenied('Authentication is required.');
        }
        $userId = (int) $account->id();
        $user = $this->entityTypeManager->getRepository('user')->find((string) $userId);
        if (!$user instanceof EntityInterface) {
            throw new OrganizationAccessDenied('The authenticated account is unavailable.');
        }
        if (!$this->internalFields->verification($user)->emailVerified) {
            throw new OrganizationAccessDenied('Verify your email address before accessing an organization.');
        }
        $subjectId = $user->uuid();
        if (!Uuid::isValid($subjectId)) {
            throw new OrganizationAccessDenied('The authenticated account has no stable service identity.');
        }
        $identity = $this->internalFields->sessionIdentity($user);

        return new AuthenticatedAccount($userId, $subjectId, $identity->name, $user);
    }

    public function resolve(Request $request): OrganizationRequestContext
    {
        $account = $this->account($request);
        $selected = $request->getSession()->get(self::SESSION_KEY);
        $context = $this->memberships->ensurePersonalOrganization($account->userId, $account->displayName);
        if (is_string($selected) && $selected !== $context->organizationId) {
            $context = $this->memberships->resolve($account->userId, $selected);
        }
        $request->getSession()->set(self::SESSION_KEY, $context->organizationId);

        return new OrganizationRequestContext($account, $context);
    }
}
