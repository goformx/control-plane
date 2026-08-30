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

    public function testItPreservesRawJsonAndConditionalHeadersWithoutGivingCallersArbitraryHeaders(): void
    {
        $transport = new RecordingHttpClient();
        $client = new ManagementApiClient('https://api.goformx.com', new FirstPartyAssertionIssuer(
            'https://goformx.com', 'https://api.goformx.com',
            SigningKey::fromBase64Seed('test', base64_encode(str_repeat("\x55", 32))),
        ), $transport);
        $body = '{"title":"Updated","schema":{"properties":{}}}';
        $client->request('PATCH', '/v1/forms/33333333-3333-4333-8333-333333333333',
            '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222',
            [ManagementScope::FormsWrite], $body, ifMatch: '"form-current"', mediaType: \App\Domain\GoFormX\RequestMediaType::MergePatch);
        self::assertSame('application/merge-patch+json', $transport->headers['Content-Type']);
        self::assertSame('"form-current"', $transport->headers['If-Match']);
        self::assertSame($body, $transport->body);
        self::assertStringStartsWith('Bearer ', $transport->headers['Authorization']);
        $transport->url = '';
        $client->request('PATCH', '/v1/forms/33333333-3333-4333-8333-333333333333/webhook',
            '11111111-1111-4111-8111-111111111111', '22222222-2222-4222-8222-222222222222',
            [ManagementScope::WebhooksWrite], '{"enabled":false}');
        self::assertSame('application/json', $transport->headers['Content-Type']);
        self::assertArrayNotHasKey('If-Match', $transport->headers);
        $transport->url = '';
        try {
            $client->request('PATCH', '/v1/forms/example', 'ignored', 'ignored', [ManagementScope::FormsWrite], ifMatch: '*');
            self::fail('Wildcard If-Match must be rejected before transport or issuance.');
        } catch (\InvalidArgumentException) {
            self::assertSame('', $transport->url);
        }
    }
}

final class RecordingHttpClient implements HttpClientInterface
{
    public string $url = '';
    public array|string|null $body = null;

    /** @var array<string, string> */
    public array $headers = [];

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $this->url = $url;
        $this->headers = $headers;
        $this->body = $body;

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
