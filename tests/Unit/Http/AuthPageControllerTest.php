<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use App\Controller\AuthPageController;
use PHPUnit\Framework\TestCase;

final class AuthPageControllerTest extends TestCase
{
    public function testDashboardUsesOnlyTheExplicitPublicOriginAndIsNotCached(): void
    {
        $response = (new AuthPageController('https://public.example.test/'))->dashboard();
        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('name="goformx-api-origin" content="https://public.example.test"', $response->getContent());
        self::assertStringNotContainsString('{{ PUBLIC_API_ORIGIN }}', $response->getContent());
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function testOriginWithCredentialsCannotBeRenderedIntoBrowserHtml(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AuthPageController('https://private:secret@example.test');
    }

    public function testRegistrationPageRedirectMatchesEmailVerificationPolicy(): void
    {
        $unverified = (new AuthPageController('https://public.example.test', false))->register()->getContent();
        self::assertStringContainsString('location.href=\'/app\'', $unverified);
        self::assertStringContainsString('Your dashboard is ready after you create your account.', $unverified);
        self::assertStringNotContainsString('{{ REGISTRATION_', $unverified);

        $verified = (new AuthPageController('https://public.example.test', true))->register()->getContent();
        self::assertStringContainsString('location.href=\'/verify-email\'', $verified);
        self::assertStringContainsString('Verify your email before opening the dashboard.', $verified);
        self::assertStringNotContainsString('{{ REGISTRATION_', $verified);
    }
}
