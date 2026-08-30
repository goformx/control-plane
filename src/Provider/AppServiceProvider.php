<?php

declare(strict_types=1);

namespace App\Provider;

use App\Controller\AuthPageController;
use App\Controller\FirstPartyJwksController;
use App\Controller\HomeController;
use App\Controller\ManagementFormsController;
use App\Controller\OrganizationContextController;
use App\Domain\GoFormX\FormOperation;
use App\Domain\Organization\AuthenticatedOrganizationResolver;
use App\Domain\Organization\OrganizationMembershipService;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use App\Infrastructure\GoFormX\FirstPartyAssertionIssuer;
use App\Infrastructure\GoFormX\JwksDocument;
use App\Infrastructure\GoFormX\ManagementApiClient;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use App\Infrastructure\GoFormX\SigningKey;
use App\Infrastructure\Audit\AuthLifecycleAuditListener;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Audit\Storage\AppendOnlyAuditDatabase;
use Waaseyaa\Audit\Writer\AuditEventWriter;
use Waaseyaa\Auth\Event\AuthLifecycleEvent;
use Waaseyaa\Database\DatabaseInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Diagnostic\CleanUrlProbe;
use Waaseyaa\Foundation\Event\EventDispatcherInterface;
use Waaseyaa\Foundation\Log\LoggerInterface;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\HttpClient\StreamHttpClient;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->singleton(AuthPageController::class, fn() => new AuthPageController(
            (string) $this->config['goformx']['public_api_url'],
        ));
        $this->entityType(EntityType::fromClass(Organization::class, discoverable: false));
        $this->entityType(EntityType::fromClass(OrganizationMembership::class, discoverable: false));

        $this->singleton(OrganizationMembershipService::class, fn() => new OrganizationMembershipService(
            $this->resolve(EntityTypeManager::class),
            $this->resolve(DatabaseInterface::class),
        ));
        $this->singleton(AuthLifecycleAuditListener::class, fn() => new AuthLifecycleAuditListener(
            new AuditEventWriter(
                new AppendOnlyAuditDatabase($this->resolve(DatabaseInterface::class)),
                $this->resolve(LoggerInterface::class),
            ),
        ));
        $this->singleton(AuthenticatedOrganizationResolver::class, fn() => new AuthenticatedOrganizationResolver(
            $this->resolve(OrganizationMembershipService::class),
            $this->resolve(EntityTypeManager::class),
            $this->resolve(UserInternalFieldReaderInterface::class),
        ));
        $this->singleton(OrganizationRequestContextResolverInterface::class, fn() => $this->resolve(
            AuthenticatedOrganizationResolver::class,
        ));
        $this->singleton(OrganizationContextController::class, fn() => new OrganizationContextController(
            $this->resolve(OrganizationMembershipService::class),
            $this->resolve(AuthenticatedOrganizationResolver::class),
        ));
        $this->singleton(SigningKey::class, fn() => SigningKey::fromBase64Seed(
            (string) ($this->config['goformx']['first_party']['key_id'] ?? ''),
            (string) ($this->config['goformx']['first_party']['signing_seed'] ?? ''),
        ));
        $this->singleton(JwksDocument::class, fn() => JwksDocument::fromConfiguration(
            $this->resolve(SigningKey::class),
            (string) ($this->config['goformx']['first_party']['additional_jwks'] ?? '[]'),
        ));
        $this->singleton(FirstPartyAssertionIssuer::class, fn() => new FirstPartyAssertionIssuer(
            (string) ($this->config['goformx']['first_party']['issuer'] ?? ''),
            (string) ($this->config['goformx']['first_party']['audience'] ?? ''),
            $this->resolve(SigningKey::class),
        ));
        $this->singleton(ManagementApiClient::class, fn() => new ManagementApiClient(
            (string) ($this->config['goformx']['api_url'] ?? ''),
            $this->resolve(FirstPartyAssertionIssuer::class),
            new StreamHttpClient(timeout: 10.0, maxResponseBytes: 8 * 1024 * 1024),
        ));
        $this->singleton(ManagementApiClientInterface::class, fn() => $this->resolve(ManagementApiClient::class));
        $this->singleton(ManagementFormsController::class, fn() => new ManagementFormsController(
            $this->resolve(OrganizationRequestContextResolverInterface::class),
            $this->resolve(ManagementApiClientInterface::class),
        ));
        $this->singleton(FirstPartyJwksController::class, fn() => new FirstPartyJwksController(
            fn(): JwksDocument => $this->resolve(JwksDocument::class),
        ));
    }

    public function boot(): void
    {
        $events = $this->resolve(EventDispatcherInterface::class);
        $listener = $this->resolve(AuthLifecycleAuditListener::class);
        assert($events instanceof EventDispatcherInterface);
        assert($listener instanceof AuthLifecycleAuditListener);
        $events->addListener(AuthLifecycleEvent::NAME, [$listener, 'record']);
    }

    public function routes(WaaseyaaRouter $router, ?\Waaseyaa\Entity\EntityTypeManager $entityTypeManager = null): void
    {
        $router->addRoute(
            'home',
            RouteBuilder::create('/')
                ->controller([HomeController::class, 'index'])
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $pages = [
            'register' => ['register', false],
            'login' => ['login', false],
            'verify-email' => ['verifyEmail', false],
            'forgot-password' => ['forgotPassword', false],
            'reset-password' => ['resetPassword', false],
            'app' => ['dashboard', true],
        ];
        foreach ($pages as $path => [$method, $authenticated]) {
            $builder = RouteBuilder::create('/' . $path)
                ->controller([AuthPageController::class, $method])
                ->methods('GET');
            $builder = $authenticated ? $builder->requireAuthentication() : $builder->allowAll();
            $router->addRoute('goformx.page.' . str_replace('-', '_', $path), $builder->build());
        }

        $router->addRoute(
            'goformx.first_party_jwks',
            RouteBuilder::create('/.well-known/goformx-control-plane-jwks.json')
                ->controller(fn() => $this->resolve(FirstPartyJwksController::class)->show())
                ->allowAll()
                ->methods('GET')
                ->build(),
        );

        $router->addRoute(
            'goformx.context.show',
            RouteBuilder::create('/api/control-plane/context')
                ->controller(fn(Request $request) => $this->resolve(OrganizationContextController::class)->show($request))
                ->requireAuthentication()
                ->methods('GET')
                ->build(),
        );
        foreach (FormOperation::cases() as $operation) {
            $builder = RouteBuilder::create('/api/control-plane' . substr($operation->template(), strlen('/v1')))
                // The dispatcher also forwards named path parameters; the controller validates their request attributes.
                ->controller(fn(Request $request, string ...$routeParameters) => $this->resolve(ManagementFormsController::class)->handle($request, $operation))
                ->requireAuthentication()
                ->methods($operation->method());
            if ($operation->method() !== 'GET') {
                $builder->requireCsrf();
            }
            $router->addRoute('goformx.management.forms.' . $operation->value, $builder->build());
        }
        $router->addRoute(
            'goformx.context.switch',
            RouteBuilder::create('/api/control-plane/context/switch')
                ->controller(fn(Request $request) => $this->resolve(OrganizationContextController::class)->switch($request))
                ->requireAuthentication()
                ->requireCsrf()
                ->methods('POST')
                ->build(),
        );
        $router->addRoute(
            'goformx.organization.leave',
            RouteBuilder::create('/api/control-plane/organizations/leave')
                ->controller(fn(Request $request) => $this->resolve(OrganizationContextController::class)->leave($request))
                ->requireAuthentication()
                ->requireCsrf()
                ->methods('POST')
                ->build(),
        );
        $router->addRoute(
            'goformx.account.delete',
            RouteBuilder::create('/api/control-plane/account')
                ->controller(fn(Request $request) => $this->resolve(OrganizationContextController::class)->deleteAccount($request))
                ->requireAuthentication()
                ->requireCsrf()
                ->methods('DELETE')
                ->build(),
        );

        $router->addRoute(
            'waaseyaa.clean_url_probe',
            RouteBuilder::create(CleanUrlProbe::PATH)
                ->controller(static fn() => new Response(
                    CleanUrlProbe::SENTINEL,
                    200,
                    ['Content-Type' => 'text/plain; charset=UTF-8'],
                ))
                ->allowAll()
                ->methods('GET')
                ->build(),
        );
    }
}
