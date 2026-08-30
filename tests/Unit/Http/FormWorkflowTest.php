<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\ManagementFormsController;
use App\Domain\GoFormX\FormOperation;
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
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\HttpClient\HttpResponse;

final class FormWorkflowTest extends TestCase
{
    private const SUBJECT = '11111111-1111-4111-8111-111111111111';
    private const ORGANIZATION = '22222222-2222-4222-8222-222222222222';
    private const FORM = '33333333-3333-4333-8333-333333333333';
    private const ETAG = '"form-version-one"';
    private const BODY = '{"schema":{"type":"object","properties":{},"additionalProperties":false}}';

    #[DataProvider('roleMatrix')]
    public function testEveryOperationUsesOnlyItsRoleAuthorizedScope(
        OrganizationRole $role, FormOperation $operation, string $method, string $path, ManagementScope $scope, bool $allowed,
    ): void {
        $request = $this->request($operation);
        // Browser-invented authority is deliberately unrelated to the resolved context.
        $request->query->set('organization_id', 'foreign');
        $request->headers->set('Authorization', 'Bearer attacker');
        $request->headers->set('X-Role', 'owner');
        $client = $this->createMock(ManagementApiClientInterface::class);
        if ($allowed) {
            $client->expects(self::once())->method('request')->with(
                $method, $path, self::SUBJECT, self::ORGANIZATION, [$scope],
                $operation->hasBody() ? self::BODY : null,
                null, $operation === FormOperation::Update ? self::ETAG : null,
            )->willReturn(new HttpResponse(200, '{"data":{}}', ['ETag' => self::ETAG, 'Authorization' => 'server-secret']));
        } else {
            $client->expects(self::never())->method('request');
        }
        $response = (new ManagementFormsController($this->resolver($role), $client))->handle($request, $operation);
        self::assertSame($allowed ? 200 : 403, $response->getStatusCode());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertFalse($response->headers->has('Authorization'));
        if ($allowed) {
            self::assertSame(self::ETAG, $response->headers->get('ETag'));
        }
    }

    public static function roleMatrix(): iterable
    {
        $root = '/v1/forms/' . self::FORM;
        $operations = [
            [FormOperation::List, 'GET', '/v1/forms?limit=25&offset=0', ManagementScope::FormsRead, false],
            [FormOperation::Create, 'POST', '/v1/forms', ManagementScope::FormsWrite, true],
            [FormOperation::Get, 'GET', $root, ManagementScope::FormsRead, false],
            [FormOperation::Update, 'PATCH', $root, ManagementScope::FormsWrite, true],
            [FormOperation::ListVersions, 'GET', $root . '/versions?limit=25&offset=0', ManagementScope::FormsRead, false],
            [FormOperation::CreateVersion, 'POST', $root . '/versions', ManagementScope::FormsWrite, true],
            [FormOperation::GetVersion, 'GET', $root . '/versions/2', ManagementScope::FormsRead, false],
            [FormOperation::PublishVersion, 'POST', $root . '/versions/2/publish', ManagementScope::FormsPublish, true],
        ];
        foreach (OrganizationRole::cases() as $role) {
            foreach ($operations as [$operation, $method, $path, $scope, $write]) {
                yield $role->value . '-' . $operation->value => [$role, $operation, $method, $path, $scope, !$write || $role !== OrganizationRole::Member];
            }
        }
    }

    #[DataProvider('writeOperations')]
    public function testEveryMutationRejectsMissingCsrfBeforeResolvingOrSigning(FormOperation $operation): void
    {
        $request = $this->request($operation);
        $request->headers->remove('X-XSRF-TOKEN');
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::never())->method('resolve');
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::never())->method('request');
        self::assertSame(403, (new ManagementFormsController($resolver, $client))->handle($request, $operation)->getStatusCode());
    }

    public static function writeOperations(): iterable
    {
        foreach ([FormOperation::Create, FormOperation::Update, FormOperation::CreateVersion, FormOperation::PublishVersion] as $operation) {
            yield $operation->value => [$operation];
        }
    }

    #[DataProvider('invalidBodies')]
    public function testInvalidBodiesNeverReachTheSigner(string $body, string $type, int $expected): void
    {
        $request = $this->request(FormOperation::Create, $body);
        $request->headers->set('Content-Type', $type);
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::never())->method('request');
        self::assertSame($expected, (new ManagementFormsController($this->resolver(), $client))->handle($request, FormOperation::Create)->getStatusCode());
    }

    public static function invalidBodies(): iterable
    {
        yield 'broken syntax' => ['{', 'application/json', 400];
        yield 'array instead of object' => ['[]', 'application/json', 400];
        yield 'null' => ['null', 'application/json', 400];
        yield 'form content type' => ['{}', 'application/x-www-form-urlencoded', 415];
        yield 'oversized request' => [str_repeat(' ', 1_048_577), 'application/json', 413];
    }

    #[DataProvider('invalidEtags')]
    public function testConditionalUpdatesRequireOneStrongTag(?string $tag, int $expected): void
    {
        $request = $this->request(FormOperation::Update);
        $request->headers->remove('If-Match');
        if ($tag !== null) {
            $request->headers->set('If-Match', $tag);
        }
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::never())->method('request');
        self::assertSame($expected, (new ManagementFormsController($this->resolver(), $client))->handle($request, FormOperation::Update)->getStatusCode());
    }

    public static function invalidEtags(): iterable
    {
        yield 'missing' => [null, 428];
        yield 'empty' => ['', 428];
        yield 'wildcard' => ['*', 400];
        yield 'weak' => ['W/"old"', 400];
        yield 'multiple' => ['"old", "new"', 400];
        yield 'injected header' => ["\"old\"\r\nAuthorization: attacker", 400];
    }

    public function testInvalidSignerConfigurationIsNotReportedAsBrowserInputOrLeaked(): void
    {
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::once())->method('request')->willThrowException(new \InvalidArgumentException('secret custody diagnostic'));
        $response = (new ManagementFormsController($this->resolver(), $client))->handle($this->request(FormOperation::Get), FormOperation::Get);
        self::assertSame(503, $response->getStatusCode());
        self::assertStringNotContainsString('secret custody diagnostic', $response->getContent());
    }

    #[DataProvider('upstreamFailures')]
    public function testGoValidationAndConcurrencyFailuresPassThroughWithoutCredentialHeaders(int $status): void
    {
        $body = '{"errors":[{"code":"schema_invalid","source":{"pointer":"/schema/properties"}}]}';
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn(new HttpResponse($status, $body, ['authorization' => 'secret']));
        $response = (new ManagementFormsController($this->resolver(), $client))->handle($this->request(FormOperation::Update), FormOperation::Update);
        self::assertSame($status, $response->getStatusCode());
        self::assertSame($body, $response->getContent());
        self::assertFalse($response->headers->has('Authorization'));
    }

    public static function upstreamFailures(): iterable
    {
        foreach ([404, 409, 412, 422, 503] as $status) {
            yield (string) $status => [$status];
        }
    }

    private function resolver(OrganizationRole $role = OrganizationRole::Owner): OrganizationRequestContextResolverInterface
    {
        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(new OrganizationRequestContext(
            new AuthenticatedAccount(7, self::SUBJECT, 'Person', $this->createStub(EntityInterface::class)),
            new OrganizationContext(self::ORGANIZATION, 'Workspace', $role),
        ));
        return $resolver;
    }

    private function request(FormOperation $operation, string $body = self::BODY): Request
    {
        $request = Request::create('/api/control-plane/forms', $operation->method(), content: $body);
        $request->attributes->set('formId', self::FORM);
        $request->attributes->set('version', '2');
        $session = new Session(new MockArraySessionStorage());
        $session->set('_csrf_token', 'test-csrf');
        $request->setSession($session);
        $request->headers->set('X-XSRF-TOKEN', 'test-csrf');
        $request->headers->set('Content-Type', $operation === FormOperation::Update ? 'application/merge-patch+json' : 'application/json');
        $request->headers->set('If-Match', self::ETAG);
        return $request;
    }
}
