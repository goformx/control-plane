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
        self::assertFileExists($guidePath);
        self::assertFileExists($templatePath);
        $guide = (string) file_get_contents($guidePath);
        $template = (string) file_get_contents($templatePath);

        foreach ([
            'Connect your website',
            'Allowed browser origins',
            'Copy JavaScript example',
            'Review submissions',
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
        self::assertStringContainsString('same submission must be retried', $guide);
        self::assertStringContainsString('exact request body', $guide);
    }
}
