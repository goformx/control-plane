<?php

declare(strict_types=1);

namespace App\Infrastructure\GoFormX;

use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;

/** Select the bound before opening the upstream response stream. */
final readonly class OperationBudgetTransport implements HttpClientInterface
{
    public function __construct(
        private HttpClientInterface $json,
        private HttpClientInterface $integrations,
        private HttpClientInterface $export,
    ) {}

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $path = (string) parse_url($url, PHP_URL_PATH);
        if (strtoupper($method) === 'POST' && preg_match('#\A/v1/forms/[^/]+/submissions/export\z#', $path) === 1) {
            return $this->export->request($method, $url, $headers, $body);
        }
        if (str_starts_with($path, '/v1/service-tokens') ||
            preg_match('#\A/v1/forms/[^/]+/(?:webhook|deliveries(?:/[^/]+/replay)?)\z#', $path) === 1) {
            return $this->integrations->request($method, $url, $headers, $body);
        }
        return $this->json->request($method, $url, $headers, $body);
    }

    public function get(string $url, array $headers = []): HttpResponse
    {
        return $this->request('GET', $url, $headers);
    }

    public function post(string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        return $this->request('POST', $url, $headers, $body);
    }
}
