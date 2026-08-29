<?php

declare(strict_types=1);

namespace App\Infrastructure\GoFormX;

use App\Domain\GoFormX\ManagementScope;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;

final readonly class ManagementApiClient implements ManagementApiClientInterface
{
    private string $baseUrl;

    public function __construct(
        string $baseUrl,
        private FirstPartyAssertionIssuer $assertions,
        private HttpClientInterface $transport,
    ) {
        $parts = parse_url($baseUrl);
        $host = strtolower((string) ($parts['host'] ?? ''));
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $loopback = in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
        if (($scheme !== 'https' && !($scheme === 'http' && $loopback)) || $host === '' ||
            (isset($parts['user']) || isset($parts['pass'])) || isset($parts['query']) || isset($parts['fragment']) ||
            !in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
            throw new \InvalidArgumentException('The GoFormX API URL must be an HTTPS origin or a loopback HTTP origin.');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
    }

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
    ): HttpResponse {
        if (preg_match('#\A/v1(?:/|\z)#', $path) !== 1 || str_contains($path, '..') ||
            preg_match('/[\x00-\x1f\x7f#]/', $path) === 1) {
            throw new \InvalidArgumentException('Management API paths must remain under /v1.');
        }
        $issued = $this->assertions->issue($subjectId, $organizationId, $scopes, $requestId);

        return $this->transport->request(strtoupper($method), $this->baseUrl . $path, [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $issued->compact,
            'X-Trace-Id' => $issued->requestId,
        ], $body);
    }
}
