<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\Organization\AuthenticatedOrganizationResolver;
use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationContext;
use App\Domain\Organization\OrganizationMembershipService;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class OrganizationContextController
{
    public function __construct(
        private readonly OrganizationMembershipService $memberships,
        private readonly OrganizationRequestContextResolverInterface $resolver,
    ) {}

    public function show(Request $request): JsonResponse
    {
        try {
            return $this->contextResponse($this->resolver->resolve($request)->organization);
        } catch (OrganizationAccessDenied $exception) {
            return $this->error(403, 'Forbidden', $exception->getMessage());
        }
    }

    public function switch(Request $request): JsonResponse
    {
        try {
            $this->assertCsrf($request);
            $account = $this->resolver->account($request);
            $body = json_decode($request->getContent(), true, 32, JSON_THROW_ON_ERROR);
            $organizationId = is_string($body['organization_id'] ?? null) ? trim($body['organization_id']) : '';
            if ($organizationId === '') {
                return $this->error(400, 'Bad Request', 'organization_id is required.');
            }

            $context = $this->memberships->resolve($account->userId, $organizationId);
            $request->getSession()->set(AuthenticatedOrganizationResolver::SESSION_KEY, $context->organizationId);

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
            $account = $this->resolver->account($request);
            $organizationId = (string) $request->getSession()->get(AuthenticatedOrganizationResolver::SESSION_KEY, '');
            if ($organizationId === '') {
                return $this->error(409, 'Conflict', 'No active organization is selected.');
            }

            $this->memberships->leave($account->userId, $organizationId);
            $request->getSession()->remove(AuthenticatedOrganizationResolver::SESSION_KEY);

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
            $account = $this->resolver->account($request);
            $this->memberships->deleteAccount($account->userId, $account->entity);

            $request->getSession()->clear();
            $request->getSession()->migrate(true);

            return new JsonResponse(['jsonapi' => ['version' => '1.1'], 'meta' => ['message' => 'Account deleted.']]);
        } catch (OrganizationAccessDenied $exception) {
            return $this->error(403, 'Forbidden', $exception->getMessage());
        } catch (\DomainException $exception) {
            return $this->error(409, 'Conflict', $exception->getMessage());
        }
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
