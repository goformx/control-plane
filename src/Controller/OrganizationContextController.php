<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationContext;
use App\Domain\Organization\OrganizationMembershipService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Access\AccountInterface;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class OrganizationContextController
{
    private const string SESSION_KEY = 'goformx_organization_uuid';

    public function __construct(
        private readonly OrganizationMembershipService $memberships,
        private readonly EntityTypeManagerInterface $entityTypeManager,
        private readonly UserInternalFieldReaderInterface $internalFields,
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            [$userId, $user] = $this->verifiedUser($request);
            $identity = $this->internalFields->sessionIdentity($user);
            $selected = $request->getSession()->get(self::SESSION_KEY);
            $context = $this->memberships->ensurePersonalOrganization($userId, $identity->name);

            if (is_string($selected) && $selected !== $context->organizationId) {
                $context = $this->memberships->resolve($userId, $selected);
            }
            $request->getSession()->set(self::SESSION_KEY, $context->organizationId);

            return $this->contextResponse($context);
        } catch (OrganizationAccessDenied $exception) {
            return $this->error(403, 'Forbidden', $exception->getMessage());
        }
    }

    public function switch(Request $request): JsonResponse
    {
        try {
            $this->assertCsrf($request);
            [$userId] = $this->verifiedUser($request);
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            $organizationId = is_string($body['organization_id'] ?? null) ? trim($body['organization_id']) : '';
            if ($organizationId === '') {
                return $this->error(400, 'Bad Request', 'organization_id is required.');
            }

            $context = $this->memberships->resolve($userId, $organizationId);
            $request->getSession()->set(self::SESSION_KEY, $context->organizationId);

            return $this->contextResponse($context);
        } catch (\JsonException) {
            return $this->error(400, 'Bad Request', 'Request body is not valid JSON.');
        } catch (OrganizationAccessDenied $exception) {
            return $this->error(403, 'Forbidden', $exception->getMessage());
        }
    }

    public function leave(Request $request): JsonResponse
    {
        try {
            $this->assertCsrf($request);
            [$userId] = $this->verifiedUser($request);
            $organizationId = (string) $request->getSession()->get(self::SESSION_KEY, '');
            if ($organizationId === '') {
                return $this->error(409, 'Conflict', 'No active organization is selected.');
            }

            $this->memberships->leave($userId, $organizationId);
            $request->getSession()->remove(self::SESSION_KEY);

            return new JsonResponse(['jsonapi' => ['version' => '1.1'], 'meta' => ['message' => 'Organization left.']]);
        } catch (OrganizationAccessDenied $exception) {
            return $this->error(403, 'Forbidden', $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->error(409, 'Conflict', $exception->getMessage());
        }
    }

    public function deleteAccount(Request $request): JsonResponse
    {
        try {
            $this->assertCsrf($request);
            [$userId, $user] = $this->verifiedUser($request);
            $this->memberships->revokeForAccountDeletion($userId);
            $this->entityTypeManager->getRepository('user')->delete($user);

            $request->getSession()->clear();
            $request->getSession()->migrate(true);

            return new JsonResponse(['jsonapi' => ['version' => '1.1'], 'meta' => ['message' => 'Account deleted.']]);
        } catch (OrganizationAccessDenied $exception) {
            return $this->error(403, 'Forbidden', $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->error(409, 'Conflict', $exception->getMessage());
        }
    }

    /** @return array{0: int, 1: EntityInterface} */
    private function verifiedUser(Request $request): array
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

        return [$userId, $user];
    }

    private function contextResponse(OrganizationContext $context): JsonResponse
    {
        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'data' => ['type' => 'organization-context', 'id' => $context->organizationId, 'attributes' => $context->toArray()],
        ]);
    }

    private function assertCsrf(Request $request): void
    {
        $expected = $request->getSession()->get('_csrf_token');
        $provided = $request->headers->get('X-XSRF-TOKEN');
        if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, rawurldecode($provided))) {
            throw new OrganizationAccessDenied('CSRF token validation failed.');
        }
    }

    private function error(int $status, string $title, string $detail): JsonResponse
    {
        return new JsonResponse([
            'jsonapi' => ['version' => '1.1'],
            'errors' => [['status' => (string) $status, 'title' => $title, 'detail' => $detail]],
        ], $status);
    }
}
