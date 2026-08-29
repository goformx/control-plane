<?php

declare(strict_types=1);

namespace App\Tests\Unit\Config;

use PHPUnit\Framework\TestCase;

final class AuthenticationConfigTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $original = [];

    protected function setUp(): void
    {
        foreach (['APP_ENV', 'GOFORMX_REGISTRATION_MODE', 'SENDGRID_API_KEY', 'GOFORMX_MAIL_FROM_ADDRESS'] as $name) {
            $this->original[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->original as $name => $value) {
            $value === false ? putenv($name) : putenv($name . '=' . $value);
        }
    }

    public function testProductionRegistrationFailsClosedWithoutAnExplicitMode(): void
    {
        putenv('APP_ENV=production');

        self::assertSame('admin', $this->config()['auth']['registration']);
    }

    public function testLocalRegistrationDefaultsOpen(): void
    {
        putenv('APP_ENV=local');

        self::assertSame('open', $this->config()['auth']['registration']);
    }

    public function testExplicitProductionModeAndMailSettingsArePassedThrough(): void
    {
        putenv('APP_ENV=production');
        putenv('GOFORMX_REGISTRATION_MODE=open');
        putenv('SENDGRID_API_KEY=not-a-real-secret');
        putenv('GOFORMX_MAIL_FROM_ADDRESS=forms@example.test');

        $config = $this->config();
        self::assertSame('open', $config['auth']['registration']);
        self::assertSame('not-a-real-secret', $config['mail']['sendgrid_api_key']);
        self::assertSame('forms@example.test', $config['mail']['from_address']);
    }

    /** @return array<string, mixed> */
    private function config(): array
    {
        return require dirname(__DIR__, 3) . '/config/waaseyaa.php';
    }
}
