<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\ManagementSubmissionsController;
use App\Domain\GoFormX\ManagementScope;
use App\Domain\GoFormX\SubmissionOperation;
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
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\HttpClient\HttpResponse;
use Waaseyaa\Routing\WaaseyaaRouter;

final class SubmissionWorkflowTest extends TestCase
{
    private const SUBJECT = '11111111-1111-4111-8111-111111111111';
    private const ORGANIZATION = '22222222-2222-4222-8222-222222222222';
    private const FORM = '33333333-3333-4333-8333-333333333333';
    private const SUBMISSION = '44444444-4444-4444-8444-444444444444';
    private const EXPORT = '55555555-5555-4555-8555-555555555555';
    private const PAYLOAD = '{"data":{"number":9007199254740993,"decimal":0.10000000000000000001}}';

    #[DataProvider('operationsAndRoles')]
    public function testProductionCompositionEnforcesTheRoleAndSingleScope(SubmissionOperation $operation, OrganizationRole $role): void
    {
        $request = $this->request($operation);
        $request->headers->set('Authorization', 'Bearer browser-invented');
        $request->headers->set('X-Organization-ID', 'foreign');
        $request->headers->set('X-Role', 'owner');
        $allowed = $role !== OrganizationRole::Member;
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn($this->context($role));
        $client = $this->createMock(ManagementApiClientInterface::class);
        if ($allowed) {
            $client->expects(self::once())->method('request')->with($operation->method(),
                $operation->path(self::FORM, self::SUBMISSION), self::SUBJECT, self::ORGANIZATION,
                [ManagementScope::SubmissionsRead], $operation === SubmissionOperation::Export ? '{"format":"json"}' : null)
                ->willReturn($this->download());
        } else {
            $client->expects(self::never())->method('request');
        }
        $controller = new ManagementSubmissionsController($resolver, $client);
        $services = $this->createMock(KernelServicesInterface::class);
        $services->expects(self::once())->method('get')->with(ManagementSubmissionsController::class)->willReturn($controller);
        $provider = new AppServiceProvider();
        $provider->setKernelServices($services);
        $router = new WaaseyaaRouter(new RequestContext('', $operation->method()));
        $provider->routes($router);
        $request->attributes->add($router->match($request->getPathInfo()));
        $route = $router->getRouteCollection()->get('goformx.management.submissions.' . $operation->value);
        self::assertTrue($route->getOption('_authenticated'));
        self::assertSame($operation === SubmissionOperation::Export, $route->getOption('_csrf') === true);
        $response = (new ControllerDispatcher([]))->dispatch($request);
        self::assertSame($allowed ? 200 : 403, $response->getStatusCode());
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        self::assertFalse($response->headers->has('Authorization'));
        self::assertFalse($response->headers->has('Set-Cookie'));
        self::assertStringNotContainsString('private-canary', $response->getContent());
        if ($allowed) {
            self::assertSame(self::PAYLOAD, $response->getContent(), 'Never round JSON values in PHP.');
        }
    }

    public static function operationsAndRoles(): iterable
    {
        foreach (SubmissionOperation::cases() as $operation) {
            foreach (OrganizationRole::cases() as $role) {
                yield $operation->value . '-' . $role->value => [$operation, $role];
            }
        }
    }

    public function testMembershipIsResolvedAgainAfterDemotion(): void
    {
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::exactly(2))->method('resolve')->willReturn(
            $this->context(OrganizationRole::Owner), $this->context(OrganizationRole::Member));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn($this->download());
        $controller = new ManagementSubmissionsController($resolver, $client);
        self::assertSame(200, $controller->handle($this->request(SubmissionOperation::Get), SubmissionOperation::Get)->getStatusCode());
        self::assertSame(403, $controller->handle($this->request(SubmissionOperation::Get), SubmissionOperation::Get)->getStatusCode());
    }

    public function testDeliveryAssertionFailureCannotMasqueradeAsAnExpiredBrowserSession(): void
    {
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createStub(ManagementApiClientInterface::class);
        $client->method('request')->willReturn(new HttpResponse(401,
            '{"error":{"code":"invalid_first_party_assertion","message":"private-canary"}}'));

        $response = (new ManagementSubmissionsController($resolver, $client))->handle(
            $this->request(SubmissionOperation::Deliveries), SubmissionOperation::Deliveries);
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(502, $response->getStatusCode());
        self::assertSame('data_plane_authentication_failed', $payload['error']['code']);
        self::assertStringNotContainsString('private-canary', $response->getContent());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testDeliveryDownstreamDenialIsNotBlamedOnPhpMembership(): void
    {
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createStub(ManagementApiClientInterface::class);
        $client->method('request')->willReturn(new HttpResponse(403,
            '{"error":{"code":"forbidden","message":"private-canary"}}'));

        $response = (new ManagementSubmissionsController($resolver, $client))->handle(
            $this->request(SubmissionOperation::Deliveries), SubmissionOperation::Deliveries);
        $payload = json_decode($response->getContent(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('data_plane_access_denied', $payload['error']['code']);
        self::assertStringContainsString('data plane denied', $payload['error']['message']);
        self::assertStringNotContainsString('private-canary', $response->getContent());
    }

    public function testQueryDuplicatesAndExactExportBodyReachTheCanonicalValidatorUnchanged(): void
    {
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $query = 'limit=2&limit=3&receivedFrom=2026-08-30T00%3A00%3A00%2B00%3A00';
        $body = '{"format":"json","format":"csv","schemaVersion":1.00000000000000000001}';
        $client->expects(self::exactly(2))->method('request')->willReturnCallback(
            function ($method, $path, $subject, $organization, $scopes, $sentBody) use ($query, $body): HttpResponse {
                self::assertSame([ManagementScope::SubmissionsRead], $scopes);
                if ($method === 'GET') {
                    self::assertStringEndsWith('?' . $query, $path);
                    self::assertNull($sentBody);
                } else {
                    self::assertSame($body, $sentBody);
                }
                return new HttpResponse(400, '{"error":{"code":"invalid_request"}}');
            });
        $controller = new ManagementSubmissionsController($resolver, $client);
        $request = $this->request(SubmissionOperation::List);
        $request->server->set('QUERY_STRING', $query);
        self::assertSame(400, $controller->handle($request, SubmissionOperation::List)->getStatusCode());
        self::assertSame(400, $controller->handle($this->request(SubmissionOperation::Export, $body), SubmissionOperation::Export)->getStatusCode());
    }

    #[DataProvider('invalidRequests')]
    public function testInvalidRequestCannotIssueAnAssertion(string $scenario, int $status): void
    {
        $request = $this->request(SubmissionOperation::Export, $scenario === 'oversize' ? str_repeat('x', 4097) : '{"format":"json"}');
        match ($scenario) {
            'csrf' => $request->headers->remove('X-XSRF-TOKEN'),
            'type' => $request->headers->set('Content-Type', 'text/plain'),
            'query' => $request->server->set('QUERY_STRING', 'format=csv'),
            'selector' => $request->attributes->set('formId', '../private-canary'),
            default => null,
        };
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::never())->method('request');
        $response = (new ManagementSubmissionsController($resolver, $client))->handle($request, SubmissionOperation::Export);
        self::assertSame($status, $response->getStatusCode());
        self::assertFalse($response->headers->has('Content-Disposition'));
        self::assertStringNotContainsString('private-canary', $response->getContent());
    }

    public static function invalidRequests(): iterable
    {
        yield ['csrf', 403]; yield ['type', 415]; yield ['query', 400]; yield ['selector', 400]; yield ['oversize', 413];
    }

    #[DataProvider('badDownloads')]
    public function testIncompleteOrUnverifiableDownloadsAreNeverOffered(array $override, string $body): void
    {
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createStub(ManagementApiClientInterface::class);
        $headers = array_merge($this->download()->headers, $override);
        $client->method('request')->willReturn(new HttpResponse(200, $body, $headers));
        $response = (new ManagementSubmissionsController($resolver, $client))->handle($this->request(SubmissionOperation::Export), SubmissionOperation::Export);
        self::assertSame(503, $response->getStatusCode());
        self::assertFalse($response->headers->has('Content-Disposition'));
        self::assertFalse($response->headers->has('X-GoFormX-Export-ID'));
        self::assertStringNotContainsString('private-canary', $response->getContent());
    }

    public static function badDownloads(): iterable
    {
        yield 'truncated' => [[], 'private-canary'];
        yield 'missing length' => [['content-length' => ''], self::PAYLOAD];
        yield 'bad id' => [['x-goformx-export-id' => 'private-canary'], self::PAYLOAD];
        yield 'active content' => [['content-type' => 'text/html'], self::PAYLOAD];
        yield 'oversize' => [['content-length' => '8388609'], str_repeat('x', 8388609)];
    }

    public function testCsvFilenameAndHeadersAreReconstructedAndBytesPreserved(): void
    {
        $body = '"\'field"' . "\r\n" . '"\'=1+1"' . "\r\n";
        $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
        $client = $this->createStub(ManagementApiClientInterface::class);
        $client->method('request')->willReturn(new HttpResponse(200, $body, array_merge($this->download()->headers,
            ['content-length' => (string) strlen($body), 'content-type' => 'text/csv; charset=utf-8'])));
        $response = (new ManagementSubmissionsController($resolver, $client))->handle($this->request(SubmissionOperation::Export, '{"format":"csv"}'), SubmissionOperation::Export);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame($body, $response->getContent());
        self::assertSame('attachment; filename="goformx-submissions-' . self::EXPORT . '.csv"', $response->headers->get('Content-Disposition'));
        self::assertSame((string) strlen($body), $response->headers->get('Content-Length'));
    }

    public function testUpstreamDenialAndAuditFailureNeverGainDownloadHeaders(): void
    {
        foreach ([400, 401, 403, 404, 413, 429, 503, 504] as $status) {
            $resolver = $this->createStub(OrganizationRequestContextResolverInterface::class);
            $resolver->method('resolve')->willReturn($this->context(OrganizationRole::Owner));
            $client = $this->createStub(ManagementApiClientInterface::class);
            $client->method('request')->willReturn(new HttpResponse($status, '{"error":{"code":"denied"}}',
                array_merge($this->download()->headers, ['retry-after' => '1'])));
            $response = (new ManagementSubmissionsController($resolver, $client))->handle($this->request(SubmissionOperation::Export), SubmissionOperation::Export);
            self::assertSame($status === 401 ? 502 : $status, $response->getStatusCode());
            self::assertFalse($response->headers->has('Content-Disposition'));
            self::assertFalse($response->headers->has('X-GoFormX-Export-ID'));
            self::assertFalse($response->headers->has('Content-Length'));
            self::assertSame($status === 429 ? '1' : null, $response->headers->get('Retry-After'));
            self::assertStringNotContainsString('private-canary', $response->getContent());
        }
    }

    private function download(): HttpResponse
    {
        return new HttpResponse(200, self::PAYLOAD, ['content-type' => 'application/json',
            'content-length' => (string) strlen(self::PAYLOAD), 'x-goformx-export-id' => self::EXPORT,
            'authorization' => 'private-canary', 'set-cookie' => 'private-canary',
            'content-disposition' => 'attachment; filename="private-canary.html"']);
    }

    private function request(SubmissionOperation $operation, string $body = '{"format":"json"}'): Request
    {
        $request = Request::create('/api/control-plane' . substr($operation->path(self::FORM, self::SUBMISSION), 3), $operation->method(), content: $body);
        $request->attributes->set('formId', self::FORM);
        $request->attributes->set('submissionId', self::SUBMISSION);
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
