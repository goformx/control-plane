<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\ManagementIntegrationsController;
use App\Domain\GoFormX\IntegrationOperation;
use App\Domain\GoFormX\ManagementScope;
use App\Domain\GoFormX\RequestMediaType;
use App\Domain\Organization\AuthenticatedAccount;
use App\Domain\Organization\OrganizationAccessDenied;
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
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\Routing\WaaseyaaRouter;

final class IntegrationWorkflowTest extends TestCase
{
    private const SUBJECT = '11111111-1111-4111-8111-111111111111';
    private const ORGANIZATION = '22222222-2222-4222-8222-222222222222';
    private const FORM = '33333333-3333-4333-8333-333333333333';
    private const DELIVERY = '44444444-4444-4444-8444-444444444444';

    #[DataProvider('operationsAndRoles')]
    public function testRealRouteCompositionAppliesRoleAndScopeAndProjectsOnlyIntendedData(IntegrationOperation $operation, OrganizationRole $role): void
    {
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn($this->context($role));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $allowed = $role !== OrganizationRole::Member;
        if ($allowed) {
            $expectedScope = match ($operation) {
                IntegrationOperation::Tokens => ManagementScope::TokensRead,
                IntegrationOperation::CreateToken, IntegrationOperation::RevokeToken => ManagementScope::TokensWrite,
                IntegrationOperation::Webhook => ManagementScope::WebhooksRead, default => ManagementScope::WebhooksWrite,
            };
            $scopes = $operation === IntegrationOperation::CreateToken ? [$expectedScope, ManagementScope::FormsRead] : [$expectedScope];
            $client->expects(self::once())->method('request')->with($operation->method(),
                $operation->path(self::FORM, $this->token()['id'], self::DELIVERY) . ($operation === IntegrationOperation::Tokens ? '?limit=100' : ''),
                self::SUBJECT, self::ORGANIZATION, $scopes, $operation->hasBody() ? $this->body($operation) : null,
                null, null, RequestMediaType::Json)
                ->willReturn($this->upstream($operation));
        } else { $client->expects(self::never())->method('request'); }
        $controller = new ManagementIntegrationsController($resolver, $client);
        $services = $this->createMock(KernelServicesInterface::class);
        $services->expects(self::once())->method('get')->with(ManagementIntegrationsController::class)->willReturn($controller);
        $provider = new AppServiceProvider(); $provider->setKernelServices($services);
        $router = new WaaseyaaRouter(new RequestContext('', $operation->method())); $provider->routes($router);
        $request = $this->request($operation);
        $request->attributes->add($router->match($request->getPathInfo()));
        $route = $router->getRouteCollection()->get('goformx.management.integrations.' . $operation->value);
        self::assertTrue($route->getOption('_authenticated'));
        self::assertSame($operation->method() !== 'GET', $route->getOption('_csrf') === true);
        $response = (new ControllerDispatcher([]))->dispatch($request);
        self::assertSame($allowed ? $this->upstream($operation)->statusCode : 403, $response->getStatusCode(), $response->getContent());
        self::assertStringNotContainsString('server-secret-canary', $response->getContent());
        self::assertFalse($response->headers->has('Authorization')); self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        if ($allowed && $operation === IntegrationOperation::CreateToken) { self::assertStringContainsString($this->secret(), $response->getContent()); }
        else { self::assertStringNotContainsString($this->secret(), $response->getContent()); }
    }

    public static function operationsAndRoles(): iterable
    {
        foreach (IntegrationOperation::cases() as $operation) { foreach (OrganizationRole::cases() as $role) { yield $operation->value . '/' . $role->value => [$operation, $role]; } }
    }

    #[DataProvider('operations')]
    public function testMembershipRevocationDeniesTheVeryNextRequestForEveryOperation(IntegrationOperation $operation): void
    {
        $attempt = 0;
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::exactly(2))->method('resolve')->willReturnCallback(function () use (&$attempt): OrganizationRequestContext {
            if ($attempt++ === 0) { return $this->context(OrganizationRole::Owner); }
            throw new OrganizationAccessDenied('The organization membership is no longer active.');
        });
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn($this->upstream($operation));
        $controller = new ManagementIntegrationsController($resolver, $client);

        self::assertSame($this->upstream($operation)->statusCode,
            $controller->handle($this->request($operation), $operation)->getStatusCode());
        $denied = $controller->handle($this->request($operation), $operation);
        self::assertSame(403, $denied->getStatusCode());
        self::assertStringContainsString('no-store', $denied->headers->get('Cache-Control'));
    }

    public static function operations(): iterable
    {
        foreach (IntegrationOperation::cases() as $operation) { yield $operation->value => [$operation]; }
    }

    #[DataProvider('mutations')]
    public function testCsrfDeniesEveryMutationBeforeMembershipOrIssuance(IntegrationOperation $operation): void
    {
        $request = $this->request($operation); $request->headers->remove('X-XSRF-TOKEN');
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class); $resolver->expects(self::never())->method('resolve');
        $client = $this->createMock(ManagementApiClientInterface::class); $client->expects(self::never())->method('request');
        self::assertSame(403, (new ManagementIntegrationsController($resolver, $client))->handle($request, $operation)->getStatusCode());
    }

    public static function mutations(): iterable
    {
        foreach (IntegrationOperation::cases() as $operation) { if ($operation->method() !== 'GET') { yield $operation->value => [$operation]; } }
    }

    #[DataProvider('invalidScopes')]
    public function testInvalidDelegationNeverReachesTheIssuer(string $body): void
    {
        $client = $this->createMock(ManagementApiClientInterface::class); $client->expects(self::never())->method('request');
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class); $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        self::assertSame(400, (new ManagementIntegrationsController($resolver, $client))->handle($this->request(IntegrationOperation::CreateToken, $body), IntegrationOperation::CreateToken)->getStatusCode());
    }

    public static function invalidScopes(): iterable
    {
        foreach (['{}', '{"scopes":[]}', '{"scopes":["admin:all"]}', '{"scopes":["forms:read","forms:read"]}', '{"scopes":{"0":"forms:read"}}', '{"scopes":[null]}'] as $body) { yield [$body]; }
    }

    public function testMembershipIsResolvedAgainForEveryRequest(): void
    {
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::exactly(3))->method('resolve')->willReturnOnConsecutiveCalls(
            $this->context(OrganizationRole::Owner), $this->context(OrganizationRole::Member), $this->context(OrganizationRole::Admin));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::exactly(2))->method('request')->willReturn($this->upstream(IntegrationOperation::Tokens));
        $controller = new ManagementIntegrationsController($resolver, $client);
        foreach ([200, 403, 200] as $status) { self::assertSame($status, $controller->handle($this->request(IntegrationOperation::Tokens), IntegrationOperation::Tokens)->getStatusCode()); }
    }

    public function testTokenInventoryProxiesOnlyOneBoundedOpaqueCursor(): void
    {
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::once())->method('request')->with('GET', '/v1/service-tokens?limit=100&cursor=page-100',
            self::SUBJECT, self::ORGANIZATION, [ManagementScope::TokensRead], null)->willReturn($this->upstream(IntegrationOperation::Tokens));
        $controller = new ManagementIntegrationsController($resolver, $client);
        $request = $this->request(IntegrationOperation::Tokens);
        $request->server->set('QUERY_STRING', 'cursor=page-100');
        self::assertSame(200, $controller->handle($request, IntegrationOperation::Tokens)->getStatusCode());

        foreach (['limit=1', 'cursor=', 'cursor=one&cursor=two', 'cursor=one&extra=two', 'cursor=' . str_repeat('a', 1025)] as $query) {
            $request = $this->request(IntegrationOperation::Tokens); $request->server->set('QUERY_STRING', $query);
            self::assertSame(400, $controller->handle($request, IntegrationOperation::Tokens)->getStatusCode(), $query);
        }
    }

    public function testTokenInventoryRejectsMalformedPaginationMetadata(): void
    {
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        foreach ([[], ['limit' => 25, 'nextCursor' => null], ['limit' => 100, 'nextCursor' => ''],
            ['limit' => 100, 'nextCursor' => str_repeat('a', 1025)], ['limit' => 100, 'nextCursor' => 'not+a+cursor']] as $meta) {
            $client = $this->createStub(ManagementApiClientInterface::class);
            $client->method('request')->willReturn(new HttpResponse(200,
                json_encode(['data' => [$this->token()], 'meta' => $meta]), ['content-type' => 'application/json']));
            $response = (new ManagementIntegrationsController($resolver, $client))->handle(
                $this->request(IntegrationOperation::Tokens), IntegrationOperation::Tokens);
            self::assertSame(502, $response->getStatusCode(), json_encode($meta));
        }
    }

    public function testErrorsAndMalformedSuccessCannotRevealSecretsOrMasqueradeAsInvalidInput(): void
    {
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class); $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        foreach ([new HttpResponse(500, 'server-secret-canary'), new HttpResponse(201, '{"data":', ['content-type' => 'application/json']),
            new HttpResponse(201, json_encode(['data' => ['token' => $this->secret(), 'metadata' => [...$this->token(), 'organizationId' => self::FORM]]]), ['content-type' => 'application/json'])] as $upstream) {
            $client = $this->createStub(ManagementApiClientInterface::class); $client->method('request')->willReturn($upstream);
            $response = (new ManagementIntegrationsController($resolver, $client))->handle($this->request(IntegrationOperation::CreateToken), IntegrationOperation::CreateToken);
            self::assertGreaterThanOrEqual(500, $response->getStatusCode()); self::assertStringContainsString('uncertain', $response->getContent()); self::assertStringNotContainsString($this->secret(), $response->getContent()); self::assertStringNotContainsString('server-secret-canary', $response->getContent());
        }
    }

    #[DataProvider('downstreamErrors')]
    public function testDownstreamErrorsPreserveSessionAndMutationOutcomeSemantics(
        int $downstreamStatus,
        string $downstreamCode,
        int $expectedStatus,
        string $expectedCode,
        string $expectedMessage,
        bool $uncertain,
    ): void {
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createStub(ManagementApiClientInterface::class);
        $client->method('request')->willReturn(new HttpResponse($downstreamStatus,
            json_encode(['error' => ['code' => $downstreamCode, 'message' => 'server-secret-canary']])));

        $response = (new ManagementIntegrationsController($resolver, $client))->handle(
            $this->request(IntegrationOperation::PutWebhook), IntegrationOperation::PutWebhook);
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame($expectedCode, $payload['error']['code']);
        self::assertStringContainsString($expectedMessage, $payload['error']['message']);
        self::assertSame($uncertain, str_contains($payload['error']['message'], 'uncertain'));
        self::assertStringNotContainsString('server-secret-canary', $response->getContent());
    }

    public static function downstreamErrors(): iterable
    {
        yield 'Go assertion 401 is a definite no-op, not a PHP session failure' => [401, 'invalid_first_party_assertion', 502, 'data_plane_authentication_failed', 'No change was committed', false];
        yield 'concurrent conflict is a definite rejection' => [409, 'conflict', 409, 'integration_request_failed', 'concurrently', false];
        yield 'stale precondition is a definite rejection' => [412, 'precondition_failed', 412, 'integration_request_failed', 'precondition is stale', false];
        yield 'downstream denial is not blamed on PHP membership' => [403, 'forbidden', 403, 'data_plane_access_denied', 'data plane denied', false];
        yield 'audit failure commits nothing' => [503, 'management_audit_unavailable', 503, 'management_audit_unavailable', 'No change was committed', false];
        yield 'disabled webhooks commit nothing' => [503, 'webhooks_disabled', 503, 'webhooks_disabled', 'No change was committed', false];
        yield 'missing token service commits nothing' => [503, 'service_unavailable', 503, 'service_unavailable', 'No change was committed', false];
        yield 'unknown outage remains uncertain' => [503, 'unknown_outage', 503, 'integration_request_failed', 'uncertain', true];
    }

    public function testSafeCorrelationAndRetryHeadersSurviveTheIntegrationProjection(): void
    {
        $trace = '55555555-5555-4555-8555-555555555555';
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::exactly(3))->method('request')->willReturnOnConsecutiveCalls(
            new HttpResponse(200, json_encode(['data' => [$this->token()], 'meta' => ['limit' => 100, 'nextCursor' => null]]), [
                'content-type' => 'application/json', 'x-trace-id' => $trace, 'retry-after' => 'private-canary',
            ]),
            new HttpResponse(429, '{"error":{"code":"rate_limited"}}', [
                'x-trace-id' => $trace, 'retry-after' => '7', 'set-cookie' => 'private-canary',
            ]),
            new HttpResponse(200, '{"data":"malformed-private-canary"}', [
                'content-type' => 'application/json', 'x-trace-id' => $trace, 'set-cookie' => 'private-canary',
            ]),
        );
        $controller = new ManagementIntegrationsController($resolver, $client);

        $success = $controller->handle($this->request(IntegrationOperation::Tokens), IntegrationOperation::Tokens);
        self::assertSame($trace, $success->headers->get('X-Trace-Id'));
        self::assertFalse($success->headers->has('Retry-After'));
        $limited = $controller->handle($this->request(IntegrationOperation::Tokens), IntegrationOperation::Tokens);
        self::assertSame($trace, $limited->headers->get('X-Trace-Id'));
        self::assertSame('7', $limited->headers->get('Retry-After'));
        self::assertFalse($limited->headers->has('Set-Cookie'));
        $rejected = $controller->handle($this->request(IntegrationOperation::Tokens), IntegrationOperation::Tokens);
        self::assertSame(502, $rejected->getStatusCode());
        self::assertSame($trace, $rejected->headers->get('X-Trace-Id'));
        self::assertStringNotContainsString('private-canary', $rejected->getContent());
    }

    private function context(OrganizationRole $role): OrganizationRequestContext
    {
        return new OrganizationRequestContext(new AuthenticatedAccount(7, self::SUBJECT, 'Person', $this->createStub(EntityInterface::class)), new OrganizationContext(self::ORGANIZATION, 'Workspace', $role));
    }

    private function body(IntegrationOperation $operation): string
    {
        return $operation === IntegrationOperation::CreateToken ? '{"name":"Test","scopes":["forms:read"]}' : '{}';
    }

    private function request(IntegrationOperation $operation, ?string $body = null): Request
    {
        $request = Request::create('/api/control-plane' . substr($operation->path(self::FORM, $this->token()['id'], self::DELIVERY), 3), $operation->method(), content: $body ?? $this->body($operation));
        $request->attributes->add(['formId' => self::FORM, 'tokenId' => $this->token()['id'], 'deliveryId' => self::DELIVERY]);
        $session = new Session(new MockArraySessionStorage()); $session->set('_csrf_token', 'csrf'); $request->setSession($session);
        $request->headers->set('X-XSRF-TOKEN', 'csrf'); $request->headers->set('Content-Type', 'application/json');
        $request->headers->set('Authorization', 'Bearer browser-forgery'); $request->headers->set('X-Organization-ID', self::FORM); $request->headers->set('X-Role', 'owner');
        return $request;
    }

    private function secret(): string { return 'gfst_' . str_repeat('a', 43); }
    private function token(): array
    {
        return ['id' => rtrim(strtr(base64_encode(substr(hash('sha256', $this->secret(), true), 0, 12)), '+/', '-_'), '='), 'name' => 'Test', 'organizationId' => self::ORGANIZATION,
            'scopes' => ['forms:read'], 'status' => 'active', 'createdAt' => '2026-08-30T00:00:00Z', 'expiresAt' => '2026-09-30T00:00:00Z', 'token' => $this->secret(), 'hash' => 'server-secret-canary'];
    }

    private function upstream(IntegrationOperation $operation): HttpResponse
    {
        $data = match ($operation) {
            IntegrationOperation::Tokens => [$this->token()], IntegrationOperation::CreateToken => ['token' => $this->secret(), 'metadata' => $this->token()],
            IntegrationOperation::ReplayDelivery => ['id' => self::DELIVERY, 'status' => 'pending'],
            default => ['id' => self::DELIVERY, 'formId' => self::FORM, 'origin' => 'https://example.com', 'enabled' => true, 'createdAt' => '2026-08-30T00:00:00Z', 'updatedAt' => '2026-08-30T00:00:00Z', 'signingSecret' => 'server-secret-canary'],
        };
        $status = match ($operation) { IntegrationOperation::CreateToken => 201, IntegrationOperation::ReplayDelivery => 202, IntegrationOperation::RevokeToken, IntegrationOperation::DeleteWebhook => 204, default => 200 };
        $payload = ['data' => $data, 'debug' => 'server-secret-canary'];
        if ($operation === IntegrationOperation::Tokens) { $payload['meta'] = ['limit' => 100, 'nextCursor' => null]; }
        return new HttpResponse($status, json_encode($payload), ['content-type' => 'application/json', 'authorization' => 'server-secret-canary', 'set-cookie' => 'server-secret-canary']);
    }
}
