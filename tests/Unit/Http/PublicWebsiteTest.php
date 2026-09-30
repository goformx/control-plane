<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\HomeController;
use App\Site\PublicWebsiteSitemap;
use App\Provider\AppServiceProvider;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RequestContext;
use Waaseyaa\Foundation\Http\ControllerDispatcher;
use Waaseyaa\Foundation\ServiceProvider\KernelServicesInterface;
use Waaseyaa\Routing\WaaseyaaRouter;

final class PublicWebsiteTest extends TestCase
{
    public static function pages(): iterable
    {
        yield ['/', 'home', 'One home for your forms'];
        yield ['/getting-started', 'goformx.public.getting-started', 'Getting started'];
        yield ['/compatibility', 'goformx.public.compatibility', 'Compatibility'];
        yield ['/docs', 'goformx.public.docs', 'Documentation'];
    }

    #[DataProvider('pages')]
    public function testAnonymousRoutesRenderThroughTheProductionProvider(string $path, string $routeName, string $title): void
    {
        $services = $this->createMock(KernelServicesInterface::class);
        $services->expects(self::once())->method('get')->with(HomeController::class)->willReturn(new HomeController('https://public.example.test'));
        $provider = new AppServiceProvider();
        $provider->setKernelServices($services);
        $router = new WaaseyaaRouter(new RequestContext('', 'GET'));
        $provider->routes($router);
        self::assertFalse($router->getRouteCollection()->get($routeName)->getOption('_authenticated') === true);
        $request = Request::create('https://untrusted-host.example' . $path);
        $request->attributes->add($router->match($path));
        $response = (new ControllerDispatcher([]))->dispatch($request);
        self::assertSame(200, $response->getStatusCode());
        $html = $response->getContent();
        self::assertStringContainsString('<title>' . $title . ' | GoFormX</title>', $html);
        self::assertStringContainsString('href="https://public.example.test' . $path . '"', $html);
        self::assertStringContainsString('https://public.example.test/assets/website-og.png', $html);
        self::assertStringNotContainsString('untrusted-host.example', $html);
        self::assertStringNotContainsString('{{ ', $html);
        self::assertStringNotContainsString('/assets/forms.js', $html);
        self::assertSame(1, substr_count($html, '<h1>'));
    }

    public function testDiscoveryIncludesOnlyThePublicWebsiteAndUsesConfiguredAuthority(): void
    {
        $paths = array_map(fn($entry) => $entry->path, iterator_to_array((new PublicWebsiteSitemap())->contributedPaths()));
        self::assertSame(['/', '/getting-started', '/compatibility', '/docs'], $paths);
        self::assertNotContains('/app', $paths);
    }

    public function testCanonicalOriginRejectsUntrustedOrAmbiguousConfiguration(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new HomeController('https://public.example.test/path?query=1');
    }
}
