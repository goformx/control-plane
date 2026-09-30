<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\GoFormX\SiteOperation;
use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\HttpClient\HttpRequestException;

final readonly class ManagementSitesController
{
    public function __construct(
        private OrganizationRequestContextResolverInterface $organizations,
        private ManagementApiClientInterface $client,
    ) {}

    public function handle(Request $request, SiteOperation $operation): Response
    {
        try {
            if ($operation === SiteOperation::Create) {
                $this->assertCsrf($request);
            }
            $context = $this->organizations->resolve($request);
            if (!$operation->allowedFor($context->organization->role)) {
                throw new OrganizationAccessDenied('Your workspace role does not allow site creation.');
            }
            try {
                $path = $operation->path((string) $request->attributes->get('siteId', ''));
            } catch (\InvalidArgumentException) {
                return $this->error(400, 'Site selector must be a UUID.');
            }
            $query = (string) $request->server->get('QUERY_STRING', '');
            if ($operation === SiteOperation::List) {
                // Go validates the raw query, including duplicate and unknown keys.
                if (strlen($query) > 2048) {
                    return $this->error(400, 'Unsupported site query.');
                }
                if ($query !== '') {
                    $path .= '?' . $query;
                }
            } elseif ($query !== '') {
                return $this->error(400, 'Unsupported site query.');
            }
            $body = null;
            if ($operation === SiteOperation::Create) {
                $type = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0]));
                if ($type !== 'application/json') {
                    return $this->error(415, 'Content-Type must be application/json.');
                }
                $body = $request->getContent();
                if (strlen($body) > 16384) {
                    return $this->error(413, 'Site request exceeds 16 KiB.');
                }
                try {
                    if (!(json_decode($body, false, 32, JSON_THROW_ON_ERROR) instanceof \stdClass)) {
                        return $this->error(400, 'Site request must be a JSON object.');
                    }
                } catch (\JsonException) {
                    return $this->error(400, 'Site request must be valid JSON.');
                }
            }
            $downstream = $this->client->request(
                $operation->method(), $path, $context->account->subjectId,
                $context->organization->organizationId, [$operation->scope()], $body,
                operationId: $operation->operationId(),
            );
            if (strlen($downstream->body) > 1024 * 1024) {
                throw new \UnexpectedValueException('Oversized site response.');
            }
            $headers = array_change_key_case($downstream->headers, CASE_LOWER);
            $response = $downstream->statusCode === 401
                ? $this->error(502, 'The sites API could not authenticate with the data plane.')
                : new Response($downstream->body, $downstream->statusCode, ['Content-Type' => 'application/json']);
            $location = $headers['location'] ?? '';
            if ($operation === SiteOperation::Create && $downstream->statusCode === 201
                && preg_match('~\A/v1/sites/[0-9a-f-]{36}\z~i', $location) === 1) {
                $response->headers->set('Location', '/api/control-plane' . substr($location, strlen('/v1')));
            }
            $trace = $headers['x-trace-id'] ?? '';
            if (Uuid::isValid($trace)) {
                $response->headers->set('X-Trace-Id', $trace);
            }
            if ($downstream->statusCode === 429 && preg_match('/\A[1-9][0-9]{0,2}\z/', $headers['retry-after'] ?? '') === 1) {
                $response->headers->set('Retry-After', $headers['retry-after']);
            }
            return $this->privateResponse($response);
        } catch (OrganizationAccessDenied) {
            return $this->error(403, 'Site access was denied. Check your workspace membership and session.');
        } catch (HttpRequestException) {
            return $this->error(502, 'The sites API is unavailable.');
        } catch (\Throwable) {
            return $this->error(503, 'Site operations are unavailable.');
        }
    }

    private function assertCsrf(Request $request): void
    {
        $expected = $request->hasSession() ? $request->getSession()->get('_csrf_token') : null;
        $provided = $request->headers->get('X-XSRF-TOKEN');
        if (!is_string($expected) || $expected === '' || !is_string($provided)
            || !hash_equals($expected, rawurldecode($provided))) {
            throw new OrganizationAccessDenied('CSRF token validation failed.');
        }
    }

    private function error(int $status, string $detail): Response
    {
        return $this->privateResponse(new JsonResponse([
            'errors' => [['status' => (string) $status, 'title' => Response::$statusTexts[$status], 'detail' => $detail]],
        ], $status));
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        return $response;
    }
}
