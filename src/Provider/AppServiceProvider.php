<?php

declare(strict_types=1);

namespace App\Provider;

use App\Controller\AuthPageController;
use App\Controller\HomeController;
use App\Controller\OrganizationContextController;
use App\Domain\Organization\OrganizationMembershipService;
use App\Entity\Organization;
use App\Entity\OrganizationMembership;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityType;
use Waaseyaa\Entity\EntityTypeManager;
use Waaseyaa\Foundation\Diagnostic\CleanUrlProbe;
use Waaseyaa\Foundation\ServiceProvider\ServiceProvider;
use Waaseyaa\Routing\RouteBuilder;
use Waaseyaa\Routing\WaaseyaaRouter;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->entityType(EntityType::fromClass(Organization::class, discoverable: false));
        $this->entityType(EntityType::fromClass(OrganizationMembership::class, discoverable: false));

        $this->singleton(OrganizationMembershipService::class, fn() => new OrganizationMembershipService(
            $this->resolve(EntityTypeManager::class),
        ));
        $this->singleton(OrganizationContextController::class, fn() => new OrganizationContextController(
            $this->resolve(OrganizationMembershipService::class),
            $this->resolve(EntityTypeManager::class),
            $this->resolve(UserInternalFieldReaderInterface::class),
        ));
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
            'goformx.context.show',
            RouteBuilder::create('/api/control-plane/context')
                ->controller(fn(Request $request) => $this->resolve(OrganizationContextController::class)->show($request))
                ->requireAuthentication()
                ->methods('GET')
                ->build(),
        );
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
