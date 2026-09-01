<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\ManagementFormsController;
use App\Domain\GoFormX\ManagementScope;
use App\Domain\Organization\AuthenticatedAccount;
use App\Domain\Organization\OrganizationContext;
use App\Domain\Organization\OrganizationRequestContext;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use App\Domain\Organization\OrganizationRole;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\HttpClient\HttpResponse;

final class ManagementFormsControllerTest extends TestCase
{
    #[DataProvider('validPagination')]
    public function testTheBrowserReceivesDataButNeverTheServerAssertion(string $query, string $expectedPath): void
    {
        $account = new AuthenticatedAccount(
            7,
            '11111111-1111-4111-8111-111111111111',
            'Person',
            $this->createStub(EntityInterface::class),
        );
        $requestContext = new OrganizationRequestContext(
            $account,
            new OrganizationContext(
                '22222222-2222-4222-8222-222222222222',
                'Workspace',
                OrganizationRole::Owner,
            ),
        );
        $client = new FormsClientStub();
        $controller = new ManagementFormsController(new ContextResolverStub($requestContext), $client);
        $response = $controller->list(Request::create('/api/control-plane/forms' . $query));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($expectedPath, $client->path);
        self::assertSame($account->subjectId, $client->subjectId);
        self::assertSame($requestContext->organization->organizationId, $client->organizationId);
        self::assertSame([ManagementScope::FormsRead], $client->scopes);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('44444444-4444-4444-8444-444444444444', $response->headers->get('X-Trace-Id'));
        self::assertStringContainsString('"data":[]', (string) $response->getContent());
        self::assertStringNotContainsString('server-only-assertion', (string) $response->getContent());
        self::assertFalse($response->headers->has('Authorization'));
    }

    public static function validPagination(): iterable
    {
        yield 'defaults' => ['', '/v1/forms?limit=25&offset=0'];
        yield 'custom page' => ['?limit=10&offset=5', '/v1/forms?limit=10&offset=5'];
        yield 'minimum bounds' => ['?limit=1&offset=0', '/v1/forms?limit=1&offset=0'];
        yield 'maximum bounds' => ['?limit=100&offset=10000', '/v1/forms?limit=100&offset=10000'];
    }

    #[DataProvider('invalidPagination')]
    public function testInvalidPaginationNeverReachesTheCredentialClient(string $query): void
    {
        $client = new FormsClientStub();
        $context = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $context->expects(self::never())->method('resolve');
        $response = (new ManagementFormsController($context, $client))->list(
            Request::create('/api/control-plane/forms?' . $query),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $client->path);
    }

    public static function invalidPagination(): iterable
    {
        yield 'limit above maximum' => ['limit=101'];
        yield 'limit below minimum' => ['limit=0'];
        yield 'offset above maximum' => ['offset=10001'];
        yield 'former offset maximum' => ['offset=100000'];
        yield 'negative offset' => ['offset=-1'];
        yield 'non-integer offset' => ['offset=1.5'];
        yield 'offset array' => ['offset[]=1'];
        yield 'limit array' => ['limit[]=1'];
        yield 'integer overflow' => ['offset=9999999999999999999999999'];
    }

    public function testDownstreamAssertionFailureCannotMasqueradeAsAnExpiredBrowserSession(): void
    {
        $context = new OrganizationRequestContext(
            new AuthenticatedAccount(7, '11111111-1111-4111-8111-111111111111', 'Person', $this->createStub(EntityInterface::class)),
            new OrganizationContext('22222222-2222-4222-8222-222222222222', 'Workspace', OrganizationRole::Owner),
        );
        $client = $this->createStub(ManagementApiClientInterface::class);
        $client->method('request')->willReturn(new HttpResponse(401,
            '{"error":{"message":"server-only-assertion"}}', ['x-trace-id' => '44444444-4444-4444-8444-444444444444']));

        $response = (new ManagementFormsController(new ContextResolverStub($context), $client))->list(
            Request::create('/api/control-plane/forms'));

        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('server-only-assertion', $response->getContent());
        self::assertSame('44444444-4444-4444-8444-444444444444', $response->headers->get('X-Trace-Id'));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }
}

final readonly class ContextResolverStub implements OrganizationRequestContextResolverInterface
{
    public function __construct(private OrganizationRequestContext $context) {}

    public function account(Request $request): AuthenticatedAccount
    {
        return $this->context->account;
    }

    public function resolve(Request $request): OrganizationRequestContext
    {
        return $this->context;
    }
}

final class FormsClientStub implements ManagementApiClientInterface
{
    public string $path = '';
    public string $subjectId = '';
    public string $organizationId = '';

    /** @var list<ManagementScope> */
    public array $scopes = [];

    public function request(
        string $method,
        string $path,
        string $subjectId,
        string $organizationId,
        array $scopes,
        array|string|null $body = null,
        ?string $requestId = null,
        ?string $ifMatch = null,
        \App\Domain\GoFormX\RequestMediaType $mediaType = \App\Domain\GoFormX\RequestMediaType::Json,
    ): HttpResponse {
        $this->path = $path;
        $this->subjectId = $subjectId;
        $this->organizationId = $organizationId;
        $this->scopes = $scopes;

        return new HttpResponse(
            200,
            '{"data":[]}',
            ['x-trace-id' => '44444444-4444-4444-8444-444444444444', 'authorization' => 'server-only-assertion'],
        );
    }
}
