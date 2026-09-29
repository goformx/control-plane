<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\GoFormX;

use App\Infrastructure\GoFormX\OperationBudgetTransport;
use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpClientInterface;
use Waaseyaa\HttpClient\HttpResponse;

final class OperationBudgetTransportTest extends TestCase
{
    public function testItSelectsTheBoundBeforeTheTransportReadsAnyBody(): void
    {
        $json = new BudgetRecorder();
        $integrations = new BudgetRecorder();
        $export = new BudgetRecorder();
        $transport = new OperationBudgetTransport($json, $integrations, $export);
        $root = 'https://api.goformx.com/v1/forms/33333333-3333-4333-8333-333333333333';

        $transport->get($root . '/submissions?limit=25');
        $transport->get($root . '/submissions/44444444-4444-4444-8444-444444444444');
        $transport->post($root . '/submissions/export', [], '{}');
        $transport->get($root . '/deliveries');
        $transport->post($root . '/deliveries/44444444-4444-4444-8444-444444444444/replay');
        $transport->get($root . '/webhook');
        $transport->get('https://api.goformx.com/v1/service-tokens?limit=100');

        self::assertCount(2, $json->requests);
        self::assertCount(4, $integrations->requests);
        self::assertCount(1, $export->requests);
        self::assertSame('POST', $export->requests[0][0]);
    }
}

final class BudgetRecorder implements HttpClientInterface
{
    public array $requests = [];

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null): HttpResponse
    {
        $this->requests[] = [$method, $url];
        return new HttpResponse(200, '{}');
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
