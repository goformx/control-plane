<?php

declare(strict_types=1);

namespace App\Tests\Unit\Domain\Organization;

use App\Domain\Organization\AuthenticatedOrganizationResolver;
use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationMembershipService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Access\User\UserSessionSnapshot;
use Waaseyaa\Access\User\UserVerificationSnapshot;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Entity\Repository\EntityRepositoryInterface;

final class AuthenticatedOrganizationResolverTest extends TestCase
{
    public function testUnverifiedAuthenticatedAccountIsAcceptedWhenPolicyAllowsIt(): void
    {
        $resolver = $this->resolver(requireVerifiedEmail: false, emailVerified: false);

        $account = $resolver->account($this->authenticatedRequest());

        self::assertSame(42, $account->userId);
        self::assertSame('d2719d3e-6c16-4e4d-90b6-6558e14397c8', $account->subjectId);
        self::assertSame('Russell', $account->displayName);
    }

    public function testUnverifiedAccountIsDeniedWhenPolicyRequiresVerification(): void
    {
        $resolver = $this->resolver(requireVerifiedEmail: true, emailVerified: false);

        $this->expectException(OrganizationAccessDenied::class);
        $this->expectExceptionMessage('Verify your email address before accessing an organization.');
        $resolver->account($this->authenticatedRequest());
    }

    public function testVerifiedAccountRemainsAcceptedWhenPolicyRequiresVerification(): void
    {
        $resolver = $this->resolver(requireVerifiedEmail: true, emailVerified: true);

        self::assertSame(42, $resolver->account($this->authenticatedRequest())->userId);
    }

    private function resolver(bool $requireVerifiedEmail, bool $emailVerified): AuthenticatedOrganizationResolver
    {
        $user = $this->createStub(EntityInterface::class);
        $user->method('uuid')->willReturn('d2719d3e-6c16-4e4d-90b6-6558e14397c8');

        $users = $this->createStub(EntityRepositoryInterface::class);
        $users->method('find')->willReturn($user);

        $entityTypeManager = $this->createStub(EntityTypeManagerInterface::class);
        $entityTypeManager->method('getRepository')->willReturn($users);

        $internalFields = $this->createStub(UserInternalFieldReaderInterface::class);
        $internalFields->method('verification')->willReturn(
            new UserVerificationSnapshot('russell@example.test', $emailVerified, true),
        );
        $internalFields->method('sessionIdentity')->willReturn(
            new UserSessionSnapshot('Russell', 'russell@example.test', ['registered']),
        );

        return new AuthenticatedOrganizationResolver(
            new OrganizationMembershipService($entityTypeManager),
            $entityTypeManager,
            $internalFields,
            $requireVerifiedEmail,
        );
    }

    private function authenticatedRequest(): Request
    {
        $account = $this->createStub(AccountInterface::class);
        $account->method('id')->willReturn(42);
        $account->method('isAuthenticated')->willReturn(true);

        $request = Request::create('/api/control-plane/context');
        $request->attributes->set('_account', $account);

        return $request;
    }
}
