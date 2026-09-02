<?php

declare(strict_types=1);

namespace App\Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class FormsWorkflowGuideContractTest extends TestCase
{
    public function testSubmissionGuideTracksRenderedControlsAndCanonicalContract(): void
    {
        $root = dirname(__DIR__, 3);
        $guidePath = $root . '/docs/forms-workflow.md';
        $templatePath = $root . '/templates/app.html.twig';
        $formsModelPath = $root . '/ui/forms-model.js';
        self::assertFileExists($guidePath);
        self::assertFileExists($templatePath);
        self::assertFileExists($formsModelPath);
        $guide = (string) file_get_contents($guidePath);
        $template = (string) file_get_contents($templatePath);
        $formsModel = (string) file_get_contents($formsModelPath);

        foreach ([
            'Connect your website',
            'Allowed browser origins',
            'Save details',
            'Copy JavaScript example',
            'Load submissions',
            'Apply submission filters',
            'Exact accepted JSON Schema',
            'Export JSON',
            'Export CSV',
        ] as $label) {
            self::assertStringContainsString($label, $guide, "Guide is missing rendered control: {$label}");
            self::assertStringContainsString($label, $template, "Rendered control is missing: {$label}");
        }

        self::assertStringContainsString(
            'https://github.com/goformx/goformx/blob/main/docs/api-clients.md',
            $guide,
        );
        foreach (['X-GoFormX-Schema-Version', 'Idempotency-Key'] as $header) {
            self::assertStringContainsString($header, $guide);
            self::assertStringContainsString($header, $formsModel);
        }
        self::assertStringContainsString('same submission must be retried', $guide);
        self::assertStringContainsString('Keep this key and body together', $formsModel);
        self::assertStringContainsString('1,000 rows / 8 MiB', $guide);
        self::assertStringContainsString('1,000 rows / 8 MiB', $template);
    }
}
