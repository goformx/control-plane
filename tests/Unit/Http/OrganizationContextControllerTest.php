<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\OrganizationContextController;
use App\Domain\Organization\AuthenticatedOrganizationResolver;
use App\Domain\Organization\OrganizationMembershipService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Waaseyaa\Access\User\UserInternalFieldReaderInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;

final class OrganizationContextControllerTest extends TestCase
{
    public function testContextSwitchRejectsAMissingCsrfHeaderBeforeAuthorization(): void
    {
        $request = $this->request();
        $response = $this->controller()->switch($request);
        $body = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('CSRF token validation failed.', $body['errors'][0]['detail']);
    }

    public function testMatchingCsrfHeaderReachesTheAuthenticationBoundary(): void
    {
        $request = $this->request(['X-XSRF-TOKEN' => 'known-token']);
        $response = $this->controller()->switch($request);
        $body = json_decode((string) $response->getContent(), true, 16, JSON_THROW_ON_ERROR);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('Authentication is required.', $body['errors'][0]['detail']);
    }

    /** @param array<string, string> $headers */
    private function request(array $headers = []): Request
    {
        $server = [];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . str_replace('-', '_', strtoupper($name))] = $value;
        }
        $request = Request::create(
            '/api/control-plane/context/switch',
            'POST',
            server: $server,
            content: '{"organization_id":"example"}',
        );
        $session = new Session(new MockArraySessionStorage());
        $session->set('_csrf_token', 'known-token');
        $request->setSession($session);

        return $request;
    }

    private function controller(): OrganizationContextController
    {
        $manager = $this->createStub(EntityTypeManagerInterface::class);

        return new OrganizationContextController(
            new OrganizationMembershipService($manager),
            $manager,
            new AuthenticatedOrganizationResolver(
                new OrganizationMembershipService($manager),
                $manager,
                $this->createStub(UserInternalFieldReaderInterface::class),
            ),
        );
    }
}
