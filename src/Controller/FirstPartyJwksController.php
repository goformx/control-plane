<?php

declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\GoFormX\JwksDocument;
use Symfony\Component\HttpFoundation\JsonResponse;

final readonly class FirstPartyJwksController
{
    /** @param \Closure(): JwksDocument $document */
    public function __construct(private \Closure $document) {}

    public function show(): JsonResponse
    {
        try {
            $response = new JsonResponse(($this->document)()->toArray());
            $response->headers->set('Cache-Control', 'public, max-age=15, stale-if-error=60');

            return $response;
        } catch (\Throwable) {
            return new JsonResponse([
                'errors' => [[
                    'status' => '503',
                    'title' => 'Service Unavailable',
                    'detail' => 'First-party verification keys are unavailable.',
                ]],
            ], 503);
        }
    }
}
