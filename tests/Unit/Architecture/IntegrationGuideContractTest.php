<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class IntegrationGuideContractTest extends TestCase
{
    public function testOperatorGuideTracksTheRenderedControlsAndCanonicalContracts(): void
    {
        $root = dirname(__DIR__, 3);
        $guidePath = $root . '/docs/integrations-workflow.md';
        $templatePath = $root . '/templates/app.html.twig';
        $integrationUiPath = $root . '/ui/integrations-app.js';
        self::assertFileExists($guidePath);
        self::assertFileExists($templatePath);
        self::assertFileExists($integrationUiPath);
        $guide = (string) file_get_contents($guidePath);
        $template = (string) file_get_contents($templatePath);
        $integrationUi = (string) file_get_contents($integrationUiPath);

        foreach ([
            'Manage API access',
            'Create scoped token',
            'Save this token now',
            'Reload token metadata',
            'I have reconciled the change',
            'Load webhook',
            'Enable future deliveries when saving configuration',
            'Save complete webhook configuration',
            'Load delivery history',
            'Pause future deliveries',
            'Rotate signing secret only',
            'Remove webhook endpoint',
        ] as $label) {
            self::assertStringContainsString($label, $guide, "Guide is missing rendered control: {$label}");
            self::assertStringContainsString($label, $template, "Rendered control is missing: {$label}");
        }
        self::assertStringContainsString('Resume future deliveries', $guide);
        self::assertStringContainsString('Resume future deliveries', $integrationUi);

        self::assertStringContainsString(
            'https://github.com/goformx/goformx/blob/main/docs/api-clients.md',
            $guide,
        );
        self::assertStringContainsString(
            'https://github.com/goformx/goformx/blob/main/docs/webhooks.md',
            $guide,
        );
        self::assertStringContainsString(
            '[integration operations workflow](docs/integrations-workflow.md)',
            (string) file_get_contents($root . '/README.md'),
        );
        self::assertStringContainsString(
            '[integration operations workflow](integrations-workflow.md)',
            (string) file_get_contents($root . '/docs/forms-workflow.md'),
        );
    }
}
