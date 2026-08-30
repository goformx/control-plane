<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\ManagementFormsController;
use App\Domain\GoFormX\FormOperation;
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

final class FormRouteCompositionTest extends TestCase
{
    #[DataProvider('operations')]
    public function testProductionRouteClosuresAcceptTheFrameworksNamedParameters(FormOperation $operation): void
    {
        $formId = '33333333-3333-4333-8333-333333333333';
        $path = '/api/control-plane' . substr($operation->path($formId, '2'), strlen('/v1'));
        $request = Request::create($path, $operation->method(), content: '{}');
        $session = new Session(new MockArraySessionStorage());
        $session->set('_csrf_token', 'test-token');
        $request->setSession($session);
        $request->headers->set('X-XSRF-TOKEN', 'test-token');
        $request->headers->set('If-Match', '"form-current"');
        $request->headers->set('Content-Type', $operation === FormOperation::Update ? 'application/merge-patch+json' : 'application/json');

        $resolver = $this->createMock(OrganizationRequestContextResolverInterface::class);
        $resolver->expects(self::once())->method('resolve')->willReturn(new OrganizationRequestContext(
            new AuthenticatedAccount(7, '11111111-1111-4111-8111-111111111111', 'Person', $this->createStub(EntityInterface::class)),
            new OrganizationContext('22222222-2222-4222-8222-222222222222', 'Workspace', OrganizationRole::Owner),
        ));
        $client = $this->createMock(ManagementApiClientInterface::class);
        $client->expects(self::once())->method('request')->willReturn(new HttpResponse(200, '{"data":{}}'));
        $controller = new ManagementFormsController($resolver, $client);
        $services = $this->createMock(KernelServicesInterface::class);
        $services->expects(self::once())->method('get')->with(ManagementFormsController::class)->willReturn($controller);
        $provider = new AppServiceProvider();
        $provider->setKernelServices($services);
        $router = new WaaseyaaRouter(new RequestContext('', $operation->method()));
        $provider->routes($router);
        $request->attributes->add($router->match($path));
        $route = $router->getRouteCollection()->get('goformx.management.forms.' . $operation->value);
        self::assertTrue($route->getOption('_authenticated'));
        self::assertSame($operation->method() !== 'GET', $route->getOption('_csrf') === true);
        $response = (new ControllerDispatcher([]))->dispatch($request);
        self::assertSame(200, $response->getStatusCode(), $response->getContent());
    }

    public static function operations(): iterable
    {
        foreach (FormOperation::cases() as $operation) {
            yield $operation->value => [$operation];
        }
    }
}
