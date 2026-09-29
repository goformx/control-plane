<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\GoFormX;

use PHPUnit\Framework\TestCase;
use Waaseyaa\HttpClient\HttpRequestException;
use Waaseyaa\HttpClient\StreamHttpClient;

final class InstalledStreamTransportTest extends TestCase
{
    private static mixed $server = null;
    private static array $pipes = [];
    private static string $origin;

    public static function setUpBeforeClass(): void
    {
        $script = dirname(__DIR__, 3) . '/fixtures/transport-server.mjs';
        self::$server = proc_open(['node', $script], [
            0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w'],
        ], self::$pipes);
        if (!is_resource(self::$server)) throw new \RuntimeException('Could not start disposable transport server.');
        stream_set_timeout(self::$pipes[1], 5);
        $port = trim((string) fgets(self::$pipes[1]));
        if (preg_match('/\A[0-9]{2,5}\z/', $port) !== 1) {
            throw new \RuntimeException('Disposable transport server did not bind.');
        }
        self::$origin = 'http://127.0.0.1:' . $port;
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) proc_terminate(self::$server);
        foreach (self::$pipes as $pipe) if (is_resource($pipe)) fclose($pipe);
        if (is_resource(self::$server)) proc_close(self::$server);
    }

    public function testCompleteDeclaredBodiesAtAndBelowLimit(): void
    {
        $client = new StreamHttpClient(timeout: 2.0, maxResponseBytes: 4);
        foreach (['/below' => 'abc', '/exact' => 'abcd'] as $path => $body) {
            try {
                self::assertSame($body, $client->get(self::$origin . $path)->body);
            } catch (HttpRequestException $error) {
                throw new \RuntimeException('Installed transport failed at ' . $path, 0, $error);
            }
        }
    }

    public function testCompleteUnknownLengthBody(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Native Windows PHP stream wrapper rejects this close-delimited fixture; Linux CI is authoritative.');
        }
        self::assertSame('abc', (new StreamHttpClient(timeout: 2.0, maxResponseBytes: 4))
            ->get(self::$origin . '/missing')->body);
    }

    public function testCompleteChunkedBodyAtLimit(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Native Windows PHP stream wrapper rejects this chunked fixture; Linux CI is authoritative.');
        }
        self::assertSame('abcd', (new StreamHttpClient(timeout: 2.0, maxResponseBytes: 4))
            ->get(self::$origin . '/chunked')->body);
    }

    public function testOversizeAndPrematureEofNeverReturnAPrefix(): void
    {
        $client = new StreamHttpClient(timeout: 2.0, maxResponseBytes: 4);
        foreach (['/above', '/short', '/chunked-over', '/error-over'] as $path) {
            try {
                $client->get(self::$origin . $path);
                self::fail('An incomplete or oversized response was accepted: ' . $path);
            } catch (HttpRequestException $error) {
                self::assertNull($error->response);
                self::assertStringNotContainsString('abc', $error->getMessage());
            }
        }
    }

    public function testReadTimeoutFailsClosedAndCompleteNon2xxRemainsMappable(): void
    {
        $client = new StreamHttpClient(timeout: 1.0, maxResponseBytes: 4);
        try {
            $client->get(self::$origin . '/timeout');
            self::fail('Timed-out body was accepted.');
        } catch (HttpRequestException $error) {
            self::assertNull($error->response);
        }
        $response = $client->get(self::$origin . '/error');
        self::assertSame(404, $response->statusCode);
        self::assertSame('no', $response->body);
    }
}
