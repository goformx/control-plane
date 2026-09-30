<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\ManagementSitesController;
use App\Domain\GoFormX\SiteOperation;
use App\Domain\Organization\AuthenticatedAccount;
use App\Domain\Organization\OrganizationContext;
use App\Domain\Organization\OrganizationRequestContext;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use App\Domain\Organization\OrganizationRole;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use App\Provider\AppServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\Routing\WaaseyaaRouter;

final class SiteWorkflowTest extends TestCase
{
    private const SUBJECT = '11111111-1111-4111-8111-111111111111';
    private const ORGANIZATION = '22222222-2222-4222-8222-222222222222';
    private const SITE = '33333333-3333-4333-8333-333333333333';

    #[DataProvider('operationsAndRoles')]
    public function testSiteOperationUsesFreshMembershipAndCanonicalScope(SiteOperation $operation, OrganizationRole $role): void
    {
        $request = $this->request($operation);
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn($this->context($role));
        $client = $this->createMock(ManagementApiClientInterface::class);
        if ($operation->allowedFor($role)) {
            $client->expects(self::once())->method('request')->with(
                $operation->method(), $operation->path(self::SITE), self::SUBJECT, self::ORGANIZATION,
                [$operation->scope()], $operation === SiteOperation::Create ? '{"name":"Personal","origin":"https://example.test"}' : null,
            )->willReturn(new HttpResponse($operation === SiteOperation::Create ? 201 : 200,
                '{"data":{"id":"' . self::SITE . '"}}'));
        } else {
            $client->expects(self::never())->method('request');
        }
        $provider = new AppServiceProvider();
        $router = new WaaseyaaRouter(new RequestContext('', $operation->method()));
        $provider->routes($router);
        $route = $router->getRouteCollection()->get('goformx.management.sites.' . $operation->value);
        self::assertTrue($route->getOption('_authenticated'));
        self::assertSame($operation === SiteOperation::Create, $route->getOption('_csrf') === true);
        $request->attributes->add($router->match($request->getPathInfo()));
        $response = (new ManagementSitesController($resolver, $client))->handle($request, $operation);
        self::assertSame($operation->allowedFor($role) ? ($operation === SiteOperation::Create ? 201 : 200) : 403,
            $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public static function operationsAndRoles(): iterable
    {
        foreach (SiteOperation::cases() as $operation) {
            foreach (OrganizationRole::cases() as $role) {
                yield $operation->value . '-' . $role->value => [$operation, $role];
            }
        }
    }

    public function testSiteCreationRequiresCsrfBeforeCallingGo(): void
    {
        $request = $this->request(SiteOperation::Create);
        $request->headers->remove('X-XSRF-TOKEN');
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::never())->method('request');
        self::assertSame(403, (new ManagementSitesController($resolver, $client))->handle($request, SiteOperation::Create)->getStatusCode());
    }

    public function testSiteListPreservesQueryForGoStrictParser(): void
    {
        $request = $this->request(SiteOperation::List);
        $request->server->set('QUERY_STRING', 'limit=2&limit=3');
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::once())->method('request')->with('GET', '/v1/sites?limit=2&limit=3')
            ->willReturn(new HttpResponse(400, '{"error":{"code":"invalid_request"}}'));
        self::assertSame(400, (new ManagementSitesController($resolver, $client))->handle($request, SiteOperation::List)->getStatusCode());
    }

    private function request(SiteOperation $operation): Request
    {
        $body = $operation === SiteOperation::Create ? '{"name":"Personal","origin":"https://example.test"}' : '';
        $path = '/api/control-plane' . substr($operation->path(self::SITE), strlen('/v1'));
        $request = Request::create($path, $operation->method(), content: $body);
        $request->attributes->set('siteId', self::SITE);
        $session = new Session(new MockArraySessionStorage());
        $session->set('_csrf_token', 'test-csrf');
        $request->setSession($session);
        $request->headers->set('X-XSRF-TOKEN', 'test-csrf');
        $request->headers->set('Content-Type', 'application/json');
        return $request;
    }

    private function context(OrganizationRole $role): OrganizationRequestContext
    {
        return new OrganizationRequestContext(new AuthenticatedAccount(7, self::SUBJECT, 'Person',
            $this->createStub(EntityInterface::class)), new OrganizationContext(self::ORGANIZATION, 'Workspace', $role));
    }
}
