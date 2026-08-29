<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\GoFormX;

use App\Domain\GoFormX\ManagementScope;
use App\Infrastructure\GoFormX\FirstPartyAssertionIssuer;
use App\Infrastructure\GoFormX\ManagementApiClient;
use App\Infrastructure\GoFormX\SigningKey;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;

final class ManagementApiClientTest extends TestCase
{
    public function testItKeepsTheAssertionInsideTheServerTransportBoundary(): void
    {
        $transport = new RecordingHttpClient();
        $issuer = new FirstPartyAssertionIssuer(
            'https://goformx.com',
            'https://api.goformx.com',
            SigningKey::fromBase64Seed('active', base64_encode(str_repeat("\x66", 32))),
        );
        $client = new ManagementApiClient('https://api.goformx.com', $issuer, $transport);
        $response = $client->request(
            'GET',
            '/v1/forms?limit=25',
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            [ManagementScope::FormsRead],
            requestId: '44444444-4444-4444-8444-444444444444',
        );

        self::assertSame(200, $response->statusCode);
        self::assertSame('https://api.goformx.com/v1/forms?limit=25', $transport->url);
        self::assertStringStartsWith('Bearer ', $transport->headers['Authorization']);
        self::assertSame('44444444-4444-4444-8444-444444444444', $transport->headers['X-Trace-Id']);
        self::assertStringNotContainsString($transport->headers['Authorization'], $response->body);
    }

    public function testItRejectsAnArbitraryOrTraversalTarget(): void
    {
        $issuer = new FirstPartyAssertionIssuer(
            'https://goformx.com',
            'https://api.goformx.com',
            SigningKey::fromBase64Seed('active', base64_encode(str_repeat("\x77", 32))),
        );
        $client = new ManagementApiClient('https://api.goformx.com', $issuer, new RecordingHttpClient());

        $this->expectException(\InvalidArgumentException::class);
        $client->request(
            'GET',
            '/v1/../admin',
            '11111111-1111-4111-8111-111111111111',
            '22222222-2222-4222-8222-222222222222',
            [ManagementScope::FormsRead],
        );
    }
}

final class RecordingHttpClient implements HttpClientInterface
{
    public string $url = '';

    /** @var array<string, string> */
    public array $headers = [];

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $this->url = $url;
        $this->headers = $headers;

        return new HttpResponse(200, '{"data":[]}');
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
