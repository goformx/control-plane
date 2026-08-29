<?php

declare(strict_types=1);

namespace App\Tests\Unit\Http;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Foundation\Kernel\EnvLoader;

final class PublicEntryPointEnvironmentTest extends TestCase
{
    #[Test]
    public function live_and_golden_entry_points_delegate_environment_loading_to_waaseyaa(): void
    {
        $root = dirname(__DIR__, 3);
        $live = file_get_contents($root . '/public/index.php');
        $golden = file_get_contents($root . '/bin/maintenance/golden-public-index.php');

        self::assertIsString($live);
        self::assertSame($golden, $live);
        self::assertStringContainsString("EnvLoader::load(\$projectRoot . '/.env');", $live);
        self::assertStringNotContainsString('new \\Symfony\\Component\\Dotenv\\Dotenv()', $live);
        self::assertStringNotContainsString('->loadEnv(', $live);
    }

    #[Test]
    public function process_environment_wins_over_dotenv_values(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'goformx-env-');
        self::assertIsString($path);
        file_put_contents($path, "APP_ENV=local\nWAASEYAA_DEV_FALLBACK_ACCOUNT=true\n");

        $names = ['APP_ENV', 'WAASEYAA_DEV_FALLBACK_ACCOUNT'];
        $before = [];
        foreach ($names as $name) {
            $before[$name] = [
                'process' => getenv($name),
                'env_exists' => array_key_exists($name, $_ENV),
                'env' => $_ENV[$name] ?? null,
                'server_exists' => array_key_exists($name, $_SERVER),
                'server' => $_SERVER[$name] ?? null,
            ];
        }

        try {
            putenv('APP_ENV=testing');
            putenv('WAASEYAA_DEV_FALLBACK_ACCOUNT=false');
            $_ENV['APP_ENV'] = $_SERVER['APP_ENV'] = 'testing';
            $_ENV['WAASEYAA_DEV_FALLBACK_ACCOUNT'] = $_SERVER['WAASEYAA_DEV_FALLBACK_ACCOUNT'] = 'false';

            EnvLoader::load($path);

            self::assertSame('testing', getenv('APP_ENV'));
            self::assertSame('false', getenv('WAASEYAA_DEV_FALLBACK_ACCOUNT'));
        } finally {
            @unlink($path);
            foreach ($before as $name => $values) {
                $values['process'] === false ? putenv($name) : putenv($name . '=' . $values['process']);
                if ($values['env_exists']) {
                    $_ENV[$name] = $values['env'];
                } else {
                    unset($_ENV[$name]);
                }
                if ($values['server_exists']) {
                    $_SERVER[$name] = $values['server'];
                } else {
                    unset($_SERVER[$name]);
                }
            }
        }
    }
}
