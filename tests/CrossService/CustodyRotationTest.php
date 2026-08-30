<?php

declare(strict_types=1);

namespace App\Tests\CrossService;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/** Real configured PHP issuance and Go process restarts, not production custody. */
final class CustodyRotationTest extends TestCase
{
    private const API = 'http://127.0.0.1:18092';
    private const UI = 'http://127.0.0.1:18093';
    private const JWKS_PATH = '/.well-known/goformx-control-plane-jwks.json';
    private const DRAIN_SECONDS = 65;

    private string $root;
    private string $directory;
    private string $binary;
    private string $goRoot;
    private string $subject;
    private string $organization;
    private string $serviceToken = '';
    /** @var array<string, resource> */
    private array $processes = [];
    /** @var list<string> */
    private array $sensitive = [];
    /** @var array<string, string> */
    private array $runtime = [];

    protected function setUp(): void
    {
        self::assertSame('1', getenv('GOFORMX_CUSTODY_REHEARSAL'), 'Explicit disposable-rehearsal opt-in is required.');
        self::assertSame('local', getenv('APP_ENV'));
        $this->root = dirname(__DIR__, 2);
        $this->binary = (string) realpath((string) getenv('GOFORMX_ROTATION_API_BINARY'));
        $this->goRoot = (string) realpath((string) getenv('GOFORMX_ROTATION_DATA_PLANE_ROOT'));
        self::assertFileExists($this->binary);
        self::assertFileExists($this->goRoot . '/go.mod');
        $database = (string) realpath((string) getenv('WAASEYAA_DB'));
        self::assertFileExists($database);
        self::assertNotSame(realpath($this->root . '/storage/waaseyaa.sqlite'), $database);
        foreach ([18092, 18093] as $port) {
            $socket = @stream_socket_server('tcp://127.0.0.1:' . $port);
            self::assertIsResource($socket, 'Rehearsal ports must be unused; existing services are never stopped.');
            fclose($socket);
        }
        $this->directory = sys_get_temp_dir() . '/goformx-custody-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        // Inherit OS execution essentials only. In particular, Go never gets PHP signing seeds.
        foreach (['PATH', 'SystemRoot', 'SYSTEMROOT', 'TEMP', 'TMP', 'TMPDIR'] as $name) {
            $value = getenv($name);
            if (is_string($value)) {
                $this->runtime[$name] = $value;
            }
        }
        $this->subject = Uuid::v4()->toRfc4122();
        $this->organization = Uuid::v4()->toRfc4122();
    }

    protected function tearDown(): void
    {
        foreach (array_keys($this->processes) as $name) {
            $this->stop($name);
        }
        if (!isset($this->directory)) {
            return;
        }
        $leaked = false;
        foreach (['php.log', 'go.log'] as $name) {
            $path = $this->directory . '/' . $name;
            if (is_file($path)) {
                $log = (string) file_get_contents($path);
                foreach ($this->sensitive as $secret) {
                    $leaked = $leaked || str_contains($log, $secret);
                }
                $leaked = $leaked || preg_match('/eyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', $log) === 1;
                unlink($path);
            }
        }
        rmdir($this->directory);
        self::assertFalse($leaked, 'A child log contained credential material; raw logs are not published.');
    }

    public function testConfiguredCustodyRotationDrainAndEmergencyRestart(): void
    {
        $old = $this->generateKey('old');
        $next = $this->generateKey('next');
        $replacement = $this->generateKey('replacement');
        $snapshot = $this->publish($old);
        $this->startGo($snapshot);
        $this->expectRead($old, 200);
        $createdForm = $this->request(self::API . '/v1/forms', 'POST', $this->issue($old, ['forms:write']), [
            'name' => 'custody-' . bin2hex(random_bytes(6)), 'title' => 'Disposable custody rehearsal',
            'schema' => ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object',
                'properties' => ['message' => ['type' => 'string']]],
        ]);
        self::assertSame(201, $createdForm['status']);
        $formId = json_decode($createdForm['body'], true, 32, JSON_THROW_ON_ERROR)['data']['id'];
        $created = $this->request(self::API . '/v1/service-tokens', 'POST', $this->issue($old, ['tokens:write', 'forms:read']), [
            'name' => 'disposable-custody-rehearsal', 'scopes' => ['forms:read'], 'expiresInSeconds' => 300,
        ]);
        self::assertSame(201, $created['status']);
        $this->serviceToken = json_decode($created['body'], true, 32, JSON_THROW_ON_ERROR)['data']['token'];
        self::assertTrue(str_starts_with($this->serviceToken, 'gfst_'));
        $this->sensitive[] = $this->serviceToken;
        $this->serviceStillWorks();
        $this->record('initial custody active');

        $snapshot = $this->publish($old, [$this->publicKey($next, 'next')]);
        $this->startGo($snapshot);
        $this->expectRead($old, 200);
        $this->expectRead($next, 200);
        $this->record('next key announced in actual PHP JWKS and Go snapshot');

        // Hold an UNUSED old assertion: its later rejection proves expiry, not replay.
        $lastOldAssertion = $this->issue($old);
        $snapshot = $this->publish($next, [$this->publicKey($old, 'retiring')]);
        $this->startGo($snapshot);
        $this->expectRead($old, 200);
        $this->expectRead($next, 200);
        $start = hrtime(true);
        $this->record('signer switched; real 65-second retiring overlap begins');
        do {
            usleep(1_000_000);
            $elapsed = (hrtime(true) - $start) / 1_000_000_000;
            if ((int) $elapsed % 10 === 0) {
                $this->expectRead($next, 200);
            }
        } while ($elapsed < self::DRAIN_SECONDS);
        self::assertGreaterThanOrEqual(self::DRAIN_SECONDS, $elapsed);
        self::assertSame(401, $this->request(self::API . '/v1/forms', 'GET', $lastOldAssertion)['status']);
        $this->record('unused retiring assertion expired after measured overlap', ['elapsed_seconds' => round($elapsed, 3)]);

        $snapshot = $this->publish($next, [$this->publicKey($old, 'revoked')]);
        $this->startGo($snapshot);
        $this->expectRead($old, 401); // Newly issued: proves revocation rather than expiry.
        $this->expectRead($next, 200);
        $this->record('planned retirement revoked; fresh old-key assertion denied');

        // Disable acceptance using real process configuration before replacing custody.
        $this->stop('php');
        $this->startGo($snapshot, false);
        $this->expectRead($next, 401);
        $this->expectRead($replacement, 401);
        $this->record('emergency acceptance disabled; external service token unaffected');

        $snapshot = $this->publish($replacement, [
            $this->publicKey($old, 'revoked'), $this->publicKey($next, 'revoked'),
        ]);
        // Stop discovery and restart the actual verifier with the complete recovery snapshot.
        $this->stop('php');
        $this->startGo($snapshot, true, 'https://127.0.0.1:18093' . self::JWKS_PATH);
        $this->expectRead($old, 401);
        $this->expectRead($next, 401);
        $this->expectRead($replacement, 200);
        // An unknown key forces discovery during the outage; no test-only refresh setting.
        $this->expectRead($this->generateKey('unknown'), 401);
        $consumed = $this->issue($replacement);
        self::assertSame(200, $this->request(self::API . '/v1/forms', 'GET', $consumed)['status']);
        $this->startGo($snapshot, true, 'https://127.0.0.1:18093' . self::JWKS_PATH);
        self::assertSame(401, $this->request(self::API . '/v1/forms', 'GET', $consumed)['status']);
        $this->expectRead($replacement, 200);
        $this->expectRead($next, 401);
        self::assertSame(403, $this->request(self::API . '/v1/forms', 'GET', $this->issue($replacement, ['forms:write']))['status']);
        self::assertSame(200, $this->request(self::API . '/v1/forms/' . $formId, 'GET', $this->issue($replacement))['status']);
        self::assertSame(404, $this->request(self::API . '/v1/forms/' . $formId, 'GET',
            $this->issue($replacement, ['forms:read'], Uuid::v4()->toRfc4122()))['status']);
        $this->record('recovery snapshot survives process restart and discovery outage; replay remains consumed');

        $this->publish($replacement, [$this->publicKey($old, 'revoked'), $this->publicKey($next, 'revoked')]);
        $this->expectRead($replacement, 200);
        $this->record('replacement PHP custody restored; production rollout remains a separate gate');
    }

    /** @return array<string, string> */
    private function generateKey(string $phase): array
    {
        $output = $this->execute([PHP_BINARY, 'bin/maintenance/goformx-generate-signing-key', 'drill-' . $phase . '-' . bin2hex(random_bytes(4))], $this->phpEnvironment());
        $values = [];
        foreach (explode("\n", trim($output)) as $line) {
            [$name, $value] = explode('=', trim($line), 2);
            $values[$name] = $value;
        }
        self::assertArrayHasKey('GOFORMX_ASSERTION_SIGNING_SEED', $values);
        $this->sensitive[] = $values['GOFORMX_ASSERTION_SIGNING_SEED'];
        return $values;
    }

    /** @param array<string, string> $custody @return array<string, string> */
    private function publicKey(#[\SensitiveParameter] array $custody, string $state): array
    {
        $key = json_decode($custody['GOFORMX_ASSERTION_ACTIVE_JWK'], true, 16, JSON_THROW_ON_ERROR);
        $key['state'] = $state;
        return $key;
    }

    /** @param array<string, string> $custody @param list<array<string, string>> $additional */
    private function publish(#[\SensitiveParameter] array $custody, array $additional = []): string
    {
        $this->stop('php');
        $environment = $this->phpEnvironment() + $custody;
        $environment['GOFORMX_ASSERTION_ADDITIONAL_JWKS'] = json_encode($additional, JSON_THROW_ON_ERROR);
        $this->start('php', [PHP_BINARY, '-S', '127.0.0.1:18093', '-t', 'public', 'public/index.php'], $environment, $this->root);
        $this->awaitReady(self::UI . self::JWKS_PATH, 'php');
        $response = $this->request(self::UI . self::JWKS_PATH);
        self::assertSame(200, $response['status']);
        $document = json_decode($response['body'], true, 32, JSON_THROW_ON_ERROR);
        foreach ($this->sensitive as $secret) {
            self::assertFalse(str_contains($response['body'], $secret), 'Public JWKS must not contain private custody.');
        }
        self::assertTrue([$this->publicKey($custody, 'active'), ...$additional] === $document['keys'], 'Published public-key states must exactly match custody configuration.');
        return json_encode($document, JSON_THROW_ON_ERROR);
    }

    private function startGo(string $snapshot, bool $enabled = true, string $discovery = ''): void
    {
        $this->stop('go');
        $this->start('go', [$this->binary], $this->runtime + [
            'APP_ENVIRONMENT' => 'test', 'APP_DEBUG' => 'false', 'APP_LOG_LEVEL' => 'info',
            'APP_HOST' => '127.0.0.1', 'APP_PORT' => '18092',
            'DB_DRIVER' => 'postgres', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '5432',
            'DB_NAME' => 'goformx', 'DB_USERNAME' => 'goformx', 'DB_PASSWORD' => 'cross-service-only', 'DB_SSL_MODE' => 'disable',
            'FIRST_PARTY_ASSERTION_ENABLED' => $enabled ? 'true' : 'false',
            'FIRST_PARTY_ASSERTION_ISSUER' => 'https://goformx.com',
            'FIRST_PARTY_ASSERTION_AUDIENCE' => 'https://api.goformx.com',
            'FIRST_PARTY_ASSERTION_JWKS_SNAPSHOT' => $snapshot,
            'FIRST_PARTY_ASSERTION_JWKS_URL' => $discovery,
        ], $this->goRoot);
        $this->awaitReady(self::API . '/ready', 'go');
    }

    /** @return array<string, string> */
    private function phpEnvironment(): array
    {
        return $this->runtime + [
            'APP_ENV' => 'local', 'APP_DEBUG' => 'false', 'APP_URL' => 'https://goformx.com',
            'GOFORMX_CUSTODY_REHEARSAL' => '1', 'WAASEYAA_DB' => (string) getenv('WAASEYAA_DB'),
            'WAASEYAA_APP_SECRET' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
            'WAASEYAA_JWT_SECRET' => 'disposable-custody-rehearsal', 'WAASEYAA_DEV_FALLBACK_ACCOUNT' => 'false',
            'GOFORMX_API_URL' => self::API,
            'GOFORMX_ASSERTION_ISSUER' => 'https://goformx.com', 'GOFORMX_ASSERTION_AUDIENCE' => 'https://api.goformx.com',
        ];
    }

    /** @param array<string, string> $custody @param list<string> $scopes */
    private function issue(#[\SensitiveParameter] array $custody, array $scopes = ['forms:read'], ?string $organization = null): string
    {
        $compact = $this->execute([PHP_BINARY, 'tests/CrossService/fixtures/issue-assertion.php'], $this->phpEnvironment() + $custody,
            json_encode(['subject' => $this->subject, 'organization' => $organization ?? $this->organization, 'scopes' => $scopes], JSON_THROW_ON_ERROR));
        self::assertTrue(preg_match('/\AeyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\z/', $compact) === 1, 'Configured issuer must return one compact assertion.');
        $this->sensitive[] = $compact;
        return $compact;
    }

    /** @param array<string, string> $custody */
    private function expectRead(#[\SensitiveParameter] array $custody, int $status): void
    {
        self::assertSame($status, $this->request(self::API . '/v1/forms', 'GET', $this->issue($custody))['status']);
        $this->serviceStillWorks();
    }

    private function serviceStillWorks(): void
    {
        if ($this->serviceToken !== '') {
            self::assertSame(200, $this->request(self::API . '/v1/forms', 'GET', $this->serviceToken)['status']);
        }
    }

    /** @param array<string, mixed>|null $body @return array{status: int, body: string} */
    private function request(string $url, string $method = 'GET', #[\SensitiveParameter] string $credential = '', ?array $body = null): array
    {
        $headers = ['Accept: application/json', 'Content-Type: application/json'];
        if ($credential !== '') {
            $headers[] = 'Authorization: Bearer ' . $credential;
        }
        $stream = @fopen($url, 'rb', false, stream_context_create(['http' => [
            'method' => $method, 'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 5,
        ]]));
        if ($stream === false) {
            return ['status' => 0, 'body' => ''];
        }
        $metadata = stream_get_meta_data($stream);
        $content = stream_get_contents($stream);
        fclose($stream);
        preg_match('/\AHTTP\/\S+ (\d{3})/', $metadata['wrapper_data'][0] ?? '', $match);
        return ['status' => (int) ($match[1] ?? 0), 'body' => $content];
    }

    /** @param list<string> $command @param array<string, string> $environment */
    private function start(string $name, array $command, #[\SensitiveParameter] array $environment, string $cwd): void
    {
        $log = $this->directory . '/' . $name . '.log';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $cwd, $environment);
        self::assertIsResource($process, 'Unable to start rehearsal child.');
        fclose($pipes[0]);
        $this->processes[$name] = $process;
    }

    private function stop(string $name): void
    {
        $process = $this->processes[$name] ?? null;
        if ($process === null) {
            return;
        }
        proc_terminate($process);
        $deadline = microtime(true) + 10;
        while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
            usleep(50_000);
        }
        if (proc_get_status($process)['running']) {
            proc_terminate($process, 9);
        }
        proc_close($process);
        unset($this->processes[$name]);
    }

    private function awaitReady(string $url, string $name): void
    {
        $deadline = microtime(true) + 20;
        do {
            self::assertTrue(proc_get_status($this->processes[$name])['running'], "{$name} process exited before readiness; raw logs withheld.");
            if ($this->request($url)['status'] === 200) {
                return;
            }
            usleep(100_000);
        } while (microtime(true) < $deadline);
        self::fail("{$name} did not become ready; raw logs withheld.");
    }

    /** @param list<string> $command @param array<string, string> $environment */
    private function execute(array $command, #[\SensitiveParameter] array $environment, string $input = ''): string
    {
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $this->root, $environment);
        self::assertIsResource($process);
        fwrite($pipes[0], $input);
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $output = '';
        $deadline = microtime(true) + 15;
        try {
            do {
                $output .= stream_get_contents($pipes[1]);
                stream_get_contents($pipes[2]); // Never emit secret-adjacent exception diagnostics.
                $status = proc_get_status($process);
                self::assertLessThan(1_048_576, strlen($output), 'Child output exceeded the rehearsal bound.');
                if (!$status['running']) {
                    $output .= stream_get_contents($pipes[1]);
                    self::assertSame(0, $status['exitcode'], 'Custody command failed; raw output withheld.');
                    return trim($output);
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::fail('Custody command timed out; raw output withheld.');
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }
    }

    /** @param array<string, int|float> $evidence */
    private function record(string $phase, array $evidence = []): void
    {
        fwrite(STDOUT, json_encode(['custody_rehearsal' => $phase, 'utc' => gmdate(DATE_ATOM)] + $evidence, JSON_THROW_ON_ERROR) . "\n");
    }
}
