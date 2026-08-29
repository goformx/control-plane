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
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\HttpClient\HttpResponse;

final class ManagementFormsControllerTest extends TestCase
{
    public function testTheBrowserReceivesDataButNeverTheServerAssertion(): void
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
        $response = $controller->list(Request::create('/api/control-plane/forms?limit=10&offset=5'));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('/v1/forms?limit=10&offset=5', $client->path);
        self::assertSame($account->subjectId, $client->subjectId);
        self::assertSame($requestContext->organization->organizationId, $client->organizationId);
        self::assertSame([ManagementScope::FormsRead], $client->scopes);
        self::assertSame('no-store, private', $response->headers->get('Cache-Control'));
        self::assertSame('44444444-4444-4444-8444-444444444444', $response->headers->get('X-Trace-Id'));
        self::assertStringContainsString('"data":[]', (string) $response->getContent());
        self::assertStringNotContainsString('server-only-assertion', (string) $response->getContent());
        self::assertFalse($response->headers->has('Authorization'));
    }

    public function testInvalidPaginationNeverReachesTheCredentialClient(): void
    {
        $client = new FormsClientStub();
        $context = $this->createStub(OrganizationRequestContextResolverInterface::class);
        $response = (new ManagementFormsController($context, $client))->list(
            Request::create('/api/control-plane/forms?limit=1000'),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('', $client->path);
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
