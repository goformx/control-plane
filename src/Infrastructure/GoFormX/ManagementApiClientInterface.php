<?php

declare(strict_types=1);

namespace App\Infrastructure\GoFormX;

use App\Domain\GoFormX\ManagementScope;
use App\Domain\GoFormX\RequestMediaType;
use Waaseyaa\HttpClient\HttpResponse;

interface ManagementApiClientInterface
{
    /**
     * @param non-empty-list<ManagementScope> $scopes
     * @param array<string, mixed>|string|null $body
     */
    public function request(
        string $method,
        string $path,
        string $subjectId,
        string $organizationId,
        array $scopes,
        array|string|null $body = null,
        ?string $requestId = null,
        ?string $ifMatch = null,
        RequestMediaType $mediaType = RequestMediaType::Json,
    ): HttpResponse;
}
