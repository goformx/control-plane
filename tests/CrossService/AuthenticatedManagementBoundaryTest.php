<?php

declare(strict_types=1);

namespace App\Tests\CrossService;

use App\Domain\GoFormX\ManagementScope;
use App\Domain\GoFormX\FormOperation;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use PHPUnit\Framework\TestCase;
use Waaseyaa\Entity\EntityInterface;
use Waaseyaa\Entity\EntityTypeManagerInterface;
use Waaseyaa\Foundation\Kernel\HttpKernel;
use Waaseyaa\User\DevAdminAccount;
use Waaseyaa\User\User;
use Symfony\Component\Yaml\Yaml;

/**
 * Cross-repository release gate for goformx/goformx#120.
 *
 * Intentionally outside the default suites: the dedicated workflow supplies
 * real PHP and Go HTTP servers, disposable databases, and ephemeral custody.
 */
final class AuthenticatedManagementBoundaryTest extends TestCase
{
    public function testAuthenticatedBrowserSessionTraversesTheRealTenantBoundary(): void
    {
        self::assertSame('local', $this->requiredEnvironment('APP_ENV'));
        self::assertSame('http://127.0.0.1:18090', $this->requiredEnvironment('GOFORMX_API_URL'));
        $uiUrl = $this->requiredEnvironment('GOFORMX_CROSS_SERVICE_UI_URL');
        self::assertSame('http://127.0.0.1:18091', $uiUrl);
        $databasePath = $this->requiredEnvironment('WAASEYAA_DB');
        self::assertNotSame(realpath(dirname(__DIR__, 2) . '/storage/waaseyaa.sqlite'), realpath($databasePath));
        $apiLogPath = $this->requiredEnvironment('GOFORMX_CROSS_SERVICE_LOG');
        $uiLogPath = $this->requiredEnvironment('GOFORMX_CROSS_SERVICE_UI_LOG');
        $signingSeed = $this->requiredEnvironment('GOFORMX_ASSERTION_SIGNING_SEED');

        $kernel = new HttpKernel(dirname(__DIR__, 2));
        $kernel->bootForCli();
        $manager = $kernel->getEntityTypeManager();
        $client = $kernel->getHttpServiceResolver()->resolve(ManagementApiClientInterface::class);
        self::assertInstanceOf(ManagementApiClientInterface::class, $client);
        $accountContext = $kernel->accountContext();
        $accountContext->set(new DevAdminAccount());
        $password = 'Cross-service-' . bin2hex(random_bytes(16));
        $email = 'member-' . bin2hex(random_bytes(6)) . '@example.test';
        $foreignEmail = 'foreign-' . bin2hex(random_bytes(6)) . '@example.test';
        $user = $this->createVerifiedUser($manager, 'Member', $email, $password);
        $foreignUser = $this->createVerifiedUser($manager, 'Foreign', $foreignEmail, $password);
        $readerEmail = 'reader-' . bin2hex(random_bytes(6)) . '@example.test';
        $reader = $this->createVerifiedUser($manager, 'Reader', $readerEmail, $password);
        $organizationIds = [];

        try {
            $anonymousCookies = [];
            $anonymous = $this->browserRequest($uiUrl, 'GET', '/api/control-plane/forms', $anonymousCookies);
            self::assertSame(401, $anonymous['status'], $anonymous['body']);

            $cookies = $this->login($uiUrl, $email, $password);
            $context = $this->browserRequest($uiUrl, 'GET', '/api/control-plane/context', $cookies);
            self::assertSame(200, $context['status'], $context['body']);
            $organizationId = $this->resourceId($context['body']);
            $organizationIds[] = $organizationId;

            $foreignCookies = $this->login($uiUrl, $foreignEmail, $password);
            $foreignContext = $this->browserRequest($uiUrl, 'GET', '/api/control-plane/context', $foreignCookies);
            self::assertSame(200, $foreignContext['status'], $foreignContext['body']);
            $foreignOrganizationId = $this->resourceId($foreignContext['body']);
            $organizationIds[] = $foreignOrganizationId;
            self::assertNotSame($organizationId, $foreignOrganizationId);

            $formTitle = 'Owned confidential ' . bin2hex(random_bytes(8));
            $foreignTitle = 'Foreign confidential ' . bin2hex(random_bytes(8));
            $formId = $this->createForm($client, $user->uuid(), $organizationId, $formTitle);
            $foreignFormId = $this->createForm($client, $foreignUser->uuid(), $foreignOrganizationId, $foreignTitle);

            $browserResponse = $this->browserRequest($uiUrl, 'GET', '/api/control-plane/forms?limit=25&offset=0', $cookies);
            self::assertSame(200, $browserResponse['status'], $browserResponse['body']);
            self::assertArrayNotHasKey('authorization', $browserResponse['headers']);
            self::assertStringContainsString('no-store', $browserResponse['headers']['cache-control'] ?? '');
            $payload = json_decode($browserResponse['body'], true, 32, JSON_THROW_ON_ERROR);
            $visibleIds = array_column($payload['data'] ?? [], 'id');
            self::assertContains($formId, $visibleIds);
            self::assertNotContains($foreignFormId, $visibleIds);
            self::assertStringNotContainsString($foreignTitle, $browserResponse['body']);
            self::assertStringNotContainsString($signingSeed, $browserResponse['body']);
            self::assertDoesNotMatchRegularExpression($this->compactJwsPattern(), $browserResponse['body']);

            $forged = $this->browserRequest($uiUrl, 'POST', '/api/control-plane/context/switch', $cookies, [
                'organization_id' => $foreignOrganizationId,
            ]);
            self::assertSame(403, $forged['status'], $forged['body']);
            $afterDeniedSwitch = $this->browserRequest($uiUrl, 'GET', '/api/control-plane/forms', $cookies);
            self::assertSame(200, $afterDeniedSwitch['status'], $afterDeniedSwitch['body']);
            self::assertStringContainsString($formId, $afterDeniedSwitch['body']);
            self::assertStringNotContainsString($foreignFormId, $afterDeniedSwitch['body']);

            $this->exerciseFormWorkflow($uiUrl, $cookies, $foreignCookies, $organizationId, $foreignOrganizationId);
            $membership = $manager->getRepository('goformx_organization_membership')->create([
                'organization_uuid' => $organizationId, 'user_id' => (int) $reader->id(), 'role' => 'member',
                'joined_at' => time(),
            ]);
            $manager->getRepository('goformx_organization_membership')->save($membership);
            $readerCookies = $this->login($uiUrl, $readerEmail, $password);
            self::assertSame(200, $this->browserRequest($uiUrl, 'GET', '/api/control-plane/forms', $readerCookies)['status']);
            $publish = '/api/control-plane/forms/' . $formId . '/versions/1/publish';
            self::assertSame(403, $this->browserRequest($uiUrl, 'POST', $publish, $readerCookies, [], ['X-Role: owner'])['status']);
            // The same live session observes promotions, demotions, and revoked membership.
            $membership->set('role', 'admin');
            $manager->getRepository('goformx_organization_membership')->save($membership);
            self::assertSame(200, $this->browserRequest($uiUrl, 'POST', $publish, $readerCookies, [])['status']);
            $membership->set('role', 'member');
            $manager->getRepository('goformx_organization_membership')->save($membership);
            self::assertSame(403, $this->browserRequest($uiUrl, 'POST', $publish, $readerCookies, [])['status']);
            $membership->set('status', 'revoked');
            $manager->getRepository('goformx_organization_membership')->save($membership);
            self::assertSame(403, $this->browserRequest($uiUrl, 'GET', '/api/control-plane/forms/' . $formId, $readerCookies)['status']);

            $traceId = $browserResponse['headers']['x-trace-id'] ?? '';
            self::assertMatchesRegularExpression('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/i', $traceId);
            $apiLog = $this->awaitLogEvidence($apiLogPath, $traceId);
            self::assertStringContainsString($traceId, $apiLog);
            $uiLog = (string) file_get_contents($uiLogPath);
            foreach ([$apiLog, $uiLog] as $log) {
                self::assertStringNotContainsStringIgnoringCase('authorization', $log);
                self::assertStringNotContainsString($signingSeed, $log);
                self::assertStringNotContainsString($password, $log);
                self::assertStringNotContainsString($formTitle, $log);
                self::assertStringNotContainsString($foreignTitle, $log);
                self::assertDoesNotMatchRegularExpression($this->compactJwsPattern(), $log);
            }
        } finally {
            $accountContext->set(new DevAdminAccount());
            foreach ($organizationIds as $organizationId) {
                $this->deleteMatching($manager, 'goformx_organization_membership', 'organization_uuid', $organizationId);
                $this->deleteMatching($manager, 'goformx_organization', 'uuid', $organizationId);
            }
            $manager->getRepository('user')->delete($user);
            $manager->getRepository('user')->delete($foreignUser);
            // A revoked account may lazily acquire a new personal workspace before
            // refusing the previously selected organization. Remove that fixture too.
            $readerMemberships = $manager->getRepository('goformx_organization_membership');
            $ids = $readerMemberships->getQuery()->accessCheck(false)->condition('user_id', (int) $reader->id())->execute();
            foreach ($readerMemberships->findMany($ids) as $remaining) {
                $this->deleteMatching($manager, 'goformx_organization', 'uuid', (string) $remaining->get('organization_uuid'));
                $readerMemberships->delete($remaining);
            }
            $manager->getRepository('user')->delete($reader);
            $accountContext->set(null);
        }
    }

    public function testFormOperationScopesMatchThePinnedCanonicalOpenApi(): void
    {
        $path = getenv('GOFORMX_CONTRACT_PATH') ?: dirname(__DIR__, 2) . '/.ci/goformx/goforms/contracts/openapi.v1.yaml';
        self::assertFileExists($path);
        $contract = Yaml::parseFile($path);
        $expected = [];
        foreach ($contract['paths'] as $path => $methods) {
            if (preg_match('~\A/v1/forms(?:/\{formId\}(?:/versions(?:/\{version\}(?:/publish)?)?)?)?\z~', $path) !== 1) {
                continue;
            }
            foreach ($methods as $method => $specification) {
                if (isset($specification['operationId'])) {
                    $expected[strtoupper($method) . ' ' . $path] = $specification['x-goformx-required-scopes'];
                }
            }
        }
        $actual = [];
        foreach (FormOperation::cases() as $operation) {
            $actual[$operation->method() . ' ' . $operation->template()] = [$operation->scope()->value];
        }
        ksort($expected);
        ksort($actual);
        self::assertCount(8, $expected);
        self::assertSame($expected, $actual);
    }

    public function testManagementScopeInventoryMatchesThePinnedCanonicalOpenApi(): void
    {
        $path = getenv('GOFORMX_CONTRACT_PATH') ?: dirname(__DIR__, 2) . '/.ci/goformx/goforms/contracts/openapi.v1.yaml';
        self::assertFileExists($path);
        $contract = Yaml::parseFile($path);
        $actual = array_map(static fn (ManagementScope $scope): string => $scope->value, ManagementScope::cases());
        foreach (['CreateServiceToken', 'ServiceToken'] as $schema) {
            $expected = $contract['components']['schemas'][$schema]['properties']['scopes']['items']['enum'] ?? null;
            self::assertSame($actual, $expected, $schema . ' must project the complete PHP management-scope inventory');
        }
    }

    /** @param array<string, string> $cookies @param array<string, string> $foreignCookies */
    private function exerciseFormWorkflow(string $url, array &$cookies, array &$foreignCookies, string $organization, string $foreign): void
    {
        $schema = ['$schema' => 'https://json-schema.org/draft/2020-12/schema', 'type' => 'object',
            'properties' => ['message' => ['type' => 'string'], 'anything' => new \stdClass()]];
        $input = ['name' => 'browser-' . bin2hex(random_bytes(6)), 'title' => 'Browser workflow', 'schema' => $schema,
            'allowedOrigins' => ['https://example.test']];
        $withoutCsrf = $cookies;
        unset($withoutCsrf['XSRF-TOKEN']);
        self::assertSame(403, $this->browserRequest($url, 'POST', '/api/control-plane/forms', $withoutCsrf, $input)['status']);
        $created = $this->browserRequest($url, 'POST', '/api/control-plane/forms?organization_id=' . $foreign, $cookies, $input);
        self::assertSame(201, $created['status'], $created['body']);
        $form = json_decode($created['body'], true, 32, JSON_THROW_ON_ERROR)['data'];
        self::assertSame($organization, $form['organizationId']);
        self::assertSame('draft', $form['status']);
        self::assertSame($input['allowedOrigins'], $form['allowedOrigins']);
        $path = '/api/control-plane/forms/' . $form['id'];
        $detail = $this->browserRequest($url, 'GET', $path, $cookies);
        self::assertSame(200, $detail['status']);
        self::assertSame($input['allowedOrigins'], json_decode($detail['body'], true, 32, JSON_THROW_ON_ERROR)['data']['allowedOrigins']);
        $etag = $detail['headers']['etag'];
        self::assertSame(428, $this->browserRequest($url, 'PATCH', $path, $cookies, ['title' => 'Updated title'])['status']);
        $updated = $this->browserRequest($url, 'PATCH', $path, $cookies,
            ['title' => 'Updated title', 'allowedOrigins' => ['https://updated.example.test']], ['If-Match: ' . $etag]);
        self::assertSame(200, $updated['status'], $updated['body']);
        self::assertSame(['https://updated.example.test'], json_decode($updated['body'], true, 32, JSON_THROW_ON_ERROR)['data']['allowedOrigins']);
        self::assertNotSame($etag, $updated['headers']['etag']);
        self::assertSame(412, $this->browserRequest($url, 'PATCH', $path, $cookies, ['title' => 'Stale overwrite'], ['If-Match: ' . $etag])['status']);
        $cleared = $this->browserRequest($url, 'PATCH', $path, $cookies, ['allowedOrigins' => []], ['If-Match: ' . $updated['headers']['etag']]);
        self::assertSame(200, $cleared['status'], $cleared['body']);
        self::assertSame([], json_decode($cleared['body'], true, 32, JSON_THROW_ON_ERROR)['data']['allowedOrigins']);
        $afterClear = $this->browserRequest($url, 'GET', $path, $cookies);
        self::assertSame(200, $afterClear['status']);
        self::assertSame([], json_decode($afterClear['body'], true, 32, JSON_THROW_ON_ERROR)['data']['allowedOrigins']);
        $invalid = $this->browserRequest($url, 'POST', $path . '/versions', $cookies, ['schema' => ['type' => 'array']]);
        self::assertSame(422, $invalid['status']);
        self::assertStringContainsString('/schema', $invalid['body']);
        $schema['properties']['message']['minLength'] = 3;
        $version = $this->browserRequest($url, 'POST', $path . '/versions', $cookies, ['schema' => $schema]);
        self::assertSame(201, $version['status'], $version['body']);
        self::assertSame(2, json_decode($version['body'], true, 32, JSON_THROW_ON_ERROR)['data']['version']);
        $versions = $this->browserRequest($url, 'GET', $path . '/versions', $cookies);
        self::assertSame(200, $versions['status']);
        self::assertCount(2, json_decode($versions['body'], true, 32, JSON_THROW_ON_ERROR)['data']);
        $published = $this->browserRequest($url, 'POST', $path . '/versions/2/publish', $cookies, []);
        self::assertSame(200, $published['status'], $published['body']);
        self::assertSame('published', json_decode($published['body'], true, 32, JSON_THROW_ON_ERROR)['data']['state']);
        $original = $this->browserRequest($url, 'GET', $path . '/versions/1', $cookies);
        self::assertSame(200, $original['status']);
        $originalSchema = json_decode($original['body'], false, 32, JSON_THROW_ON_ERROR)->data->schema;
        self::assertInstanceOf(\stdClass::class, $originalSchema->properties->anything);
        self::assertObjectNotHasProperty('minLength', $originalSchema->properties->message);
        self::assertSame(404, $this->browserRequest($url, 'GET', $path, $foreignCookies)['status']);
        self::assertSame(404, $this->browserRequest($url, 'POST', $path . '/versions/1/publish', $foreignCookies, [])['status']);
        self::assertDoesNotMatchRegularExpression($this->compactJwsPattern(), $published['body']);
    }

    private function createVerifiedUser(EntityTypeManagerInterface $manager, string $name, string $email, string $password): User
    {
        $user = new User([
            'name' => 'Cross-service ' . $name,
            'mail' => $email,
            'pass' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 1,
            'roles' => ['authenticated'],
            'created' => time(),
        ]);
        $user->setEmailVerified(true);
        $manager->getRepository('user')->save($user);
        self::assertGreaterThan(0, (int) $user->id());

        return $user;
    }

    /** @return array<string, string> */
    private function login(string $baseUrl, string $email, string $password): array
    {
        $cookies = [];
        $page = $this->browserRequest($baseUrl, 'GET', '/login', $cookies);
        self::assertSame(200, $page['status'], $page['body']);
        self::assertArrayHasKey('XSRF-TOKEN', $cookies);
        $login = $this->browserRequest($baseUrl, 'POST', '/api/auth/login', $cookies, [
            'username' => $email,
            'password' => $password,
        ]);
        self::assertSame(200, $login['status'], $login['body']);

        return $cookies;
    }

    private function createForm(ManagementApiClientInterface $client, string $subjectId, string $organizationId, string $title): string
    {
        $created = $client->request('POST', '/v1/forms', $subjectId, $organizationId, [ManagementScope::FormsWrite], [
            'name' => 'cross-service-' . bin2hex(random_bytes(6)),
            'title' => $title,
            'schema' => [
                '$schema' => 'https://json-schema.org/draft/2020-12/schema',
                'type' => 'object',
                'properties' => ['message' => ['type' => 'string']],
                'required' => ['message'],
                'additionalProperties' => false,
            ],
        ]);
        self::assertSame(201, $created->statusCode, $created->body);

        return $this->resourceId($created->body);
    }

    private function resourceId(string $body): string
    {
        $payload = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
        $id = $payload['data']['id'] ?? null;
        self::assertIsString($id);

        return $id;
    }

    /**
     * @param array<string, string> $cookies
     * @param array<string, mixed>|null $body
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function browserRequest(string $baseUrl, string $method, string $path, array &$cookies, ?array $body = null, array $extraHeaders = []): array
    {
        $headers = ['Accept: application/json'];
        if ($cookies !== []) {
            $headers[] = 'Cookie: ' . implode('; ', array_map(
                static fn(string $name, string $value): string => $name . '=' . $value,
                array_keys($cookies),
                array_values($cookies),
            ));
        }
        if ($body !== null) {
            $formMetadataPatch = $method === 'PATCH' && preg_match(
                '#\A/api/control-plane/forms/[0-9a-f-]{36}\z#i',
                $path,
            ) === 1;
            $headers[] = 'Content-Type: ' . ($formMetadataPatch ? 'application/merge-patch+json' : 'application/json');
            $headers[] = 'X-XSRF-TOKEN: ' . rawurldecode($cookies['XSRF-TOKEN'] ?? '');
        }
        $headers = [...$headers, ...$extraHeaders];
        $stream = fopen($baseUrl . $path, 'rb', false, stream_context_create(['http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true,
            'follow_location' => 0,
            'timeout' => 10,
        ]]));
        self::assertIsResource($stream);
        $metadata = stream_get_meta_data($stream);
        $responseBody = stream_get_contents($stream);
        fclose($stream);
        self::assertIsString($responseBody);
        $status = 0;
        $responseHeaders = [];
        foreach ($metadata['wrapper_data'] ?? [] as $line) {
            if (preg_match('/\AHTTP\/\S+ (\d{3})/', $line, $match) === 1) {
                $status = (int) $match[1];
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $name = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            $responseHeaders[$name] = $value;
            if ($name === 'set-cookie') {
                $cookie = explode('=', explode(';', $value, 2)[0], 2);
                if (count($cookie) === 2) {
                    $cookies[$cookie[0]] = $cookie[1];
                }
            }
        }

        return ['status' => $status, 'headers' => $responseHeaders, 'body' => $responseBody];
    }

    private function requiredEnvironment(string $name): string
    {
        $value = getenv($name);
        self::assertIsString($value, "{$name} is required by the cross-service test workflow.");
        self::assertNotSame('', $value, "{$name} must not be empty.");

        return $value;
    }

    private function awaitLogEvidence(string $path, string $traceId): string
    {
        for ($attempt = 0; $attempt < 20; ++$attempt) {
            clearstatcache(true, $path);
            $contents = is_file($path) ? file_get_contents($path) : false;
            if (is_string($contents) && str_contains($contents, $traceId)) {
                return $contents;
            }
            usleep(100_000);
        }

        self::fail("GoFormX API log did not contain correlation id {$traceId}.");
    }

    private function compactJwsPattern(): string
    {
        return '/eyJ[A-Za-z0-9_-]*\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/';
    }

    private function deleteMatching(EntityTypeManagerInterface $manager, string $entityType, string $field, string $value): void
    {
        $repository = $manager->getRepository($entityType);
        $ids = $repository->getQuery()->accessCheck(false)->condition($field, $value)->execute();
        foreach ($repository->findMany($ids) as $entity) {
            if ($entity instanceof EntityInterface) {
                $repository->delete($entity);
            }
        }
    }
}
