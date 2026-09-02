<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class ControlPlaneBoundaryTest extends TestCase
{
    public function testApplicationCodeRespectsDeclaredControlPlaneAuthority(): void
    {
        $root = dirname(__DIR__, 2);
        $manifest = Yaml::parseFile($root . '/.waaseyaa/site.yaml');
        $configuration = require $root . '/config/waaseyaa.php';
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, 32, JSON_THROW_ON_ERROR);

        $source = [];
        $entities = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            $source[$file->getPathname()] = $contents;
            preg_match_all("/#\\[ContentEntityType\\(\\s*id:\\s*'([^']+)'/s", $contents, $matches);
            array_push($entities, ...$matches[1]);
        }

        self::assertSame([], ControlPlaneBoundaryPolicy::violations(
            $source,
            $entities,
            $configuration,
            $manifest['capabilities'] ?? [],
            $composer['require'] ?? [],
        ));
    }

    public function testDirectDataPlaneDatabaseAccessFixtureIsRejected(): void
    {
        $violations = ControlPlaneBoundaryPolicy::violations(
            ['src/Backdoor.php' => '<?php new PDO("pgsql:host=api.goformx.test;dbname=goformx");'],
            [],
            self::disabledAiConfiguration() + ['database' => 'postgresql://api.goformx.test/goformx'],
            [],
            ['ext-pdo_pgsql' => '*'],
        );
        self::assertContains('data-plane database access is forbidden', $violations);
    }

    public function testDuplicatedDataPlaneEntityFixtureIsRejected(): void
    {
        $violations = ControlPlaneBoundaryPolicy::violations(
            [],
            ['goformx_organization', 'goformx_form_submission'],
            self::disabledAiConfiguration(),
            [],
            [],
        );
        self::assertContains('data-plane entity goformx_form_submission duplicates Go authority', $violations);
    }

    public function testUndeclaredAiProviderFixtureIsRejected(): void
    {
        $configuration = self::disabledAiConfiguration();
        $configuration['ai']['embedding_provider'] = 'openai';
        $violations = ControlPlaneBoundaryPolicy::violations([], [], $configuration, [], []);
        self::assertContains('AI/provider features require a declared site capability', $violations);
    }

    /** @return array<string, mixed> */
    private static function disabledAiConfiguration(): array
    {
        return [
            'ai_catalog' => ['enabled' => false],
            'ai' => [
                'embedding_provider' => '',
                'openai_credential_reference' => ['provider' => '', 'identifier' => ''],
            ],
        ];
    }
}

final class ControlPlaneBoundaryPolicy
{
    /**
     * @param array<string, string> $source
     * @param list<string> $entityIds
     * @param array<string, mixed> $configuration
     * @param list<array<string, mixed>> $capabilities
     * @param array<string, string> $requirements
     * @return list<string>
     */
    public static function violations(array $source, array $entityIds, array $configuration, array $capabilities, array $requirements): array
    {
        $violations = [];
        $code = implode("\n", $source);
        $database = $configuration['database'] ?? null;
        if (array_key_exists('ext-pdo_pgsql', $requirements)
            || preg_match('/(?:new\\s+\\?PDO\\s*\\(|pg_(?:connect|query)\\s*\\(|pgsql:|postgres(?:ql)?:\\/\\/|GOFORMX_(?:DB|DATABASE))/i', $code) === 1
            || (is_string($database) && preg_match('/\\A(?:pgsql:|postgres(?:ql)?:\\/\\/)/i', $database) === 1)) {
            $violations[] = 'data-plane database access is forbidden';
        }

        foreach ($entityIds as $entityId) {
            if (preg_match('/(?:^|_)(?:forms?|form_schemas?|submissions?|service_tokens?|webhooks?|deliveries?)(?:_|$)/', $entityId) === 1) {
                $violations[] = 'data-plane entity ' . $entityId . ' duplicates Go authority';
            }
        }

        $declaresAi = false;
        foreach ($capabilities as $capability) {
            if (in_array($capability['id'] ?? null, ['ai', 'ai_catalog', 'embeddings'], true)
                && in_array($capability['state'] ?? null, ['active', 'planned'], true)) {
                $declaresAi = true;
            }
        }
        $ai = $configuration['ai'] ?? [];
        $credential = is_array($ai) ? ($ai['openai_credential_reference'] ?? []) : [];
        $aiEnabled = ($configuration['ai_catalog']['enabled'] ?? false) === true
            || (is_array($ai) && ($ai['embedding_provider'] ?? '') !== '')
            || (is_array($credential) && (($credential['provider'] ?? '') !== '' || ($credential['identifier'] ?? '') !== ''));
        if ($aiEnabled && !$declaresAi) {
            $violations[] = 'AI/provider features require a declared site capability';
        }

        return $violations;
    }
}
