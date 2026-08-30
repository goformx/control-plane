<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\GoFormX\FormOperation;
use App\Domain\GoFormX\EntityTag;
use App\Domain\GoFormX\RequestMediaType;
use App\Http\InvalidFormRequest;
use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Waaseyaa\HttpClient\HttpRequestException;

final readonly class ManagementFormsController
{
    public function __construct(
        private OrganizationRequestContextResolverInterface $organizations,
        private ManagementApiClientInterface $client,
    ) {}

    public function list(Request $request): Response
    {
        return $this->handle($request, FormOperation::List);
    }

    public function handle(Request $request, FormOperation $operation): Response
    {
        try {
            try {
                $path = $operation->path((string) $request->attributes->get('formId', ''), (string) $request->attributes->get('version', ''));
                if ($operation === FormOperation::List || $operation === FormOperation::ListVersions) {
                    $pagination = $request->query->all();
                    $limit = $this->boundedInteger($pagination['limit'] ?? null, 25, 1, 100, 'limit');
                    $offset = $this->boundedInteger($pagination['offset'] ?? null, 0, 0, 10000, 'offset');
                    $path .= '?' . http_build_query(['limit' => $limit, 'offset' => $offset]);
                }
            } catch (\InvalidArgumentException $exception) {
                throw new InvalidFormRequest(400, $exception->getMessage());
            }
            if ($operation->method() !== 'GET') {
                $this->assertCsrf($request);
            }
            $context = $this->organizations->resolve($request);
            if (!$operation->allowedFor($context->organization->role)) {
                throw new OrganizationAccessDenied('Your workspace role does not allow this form operation.');
            }
            $body = $operation->hasBody() ? $this->jsonBody($request, $operation) : null;
            $ifMatch = null;
            if ($operation === FormOperation::Update) {
                $ifMatch = $request->headers->get('If-Match');
                if ($ifMatch === null || $ifMatch === '') {
                    throw new InvalidFormRequest(428, 'Reload the form and send its ETag in If-Match before updating it.');
                }
                if (!EntityTag::isStrong($ifMatch)) {
                    throw new InvalidFormRequest(400, 'If-Match must contain one bounded strong entity tag.');
                }
            }
            $downstream = $this->client->request(
                $operation->method(),
                $path,
                $context->account->subjectId,
                $context->organization->organizationId,
                [$operation->scope()],
                $body,
                ifMatch: $ifMatch,
                mediaType: $operation === FormOperation::Update ? RequestMediaType::MergePatch : RequestMediaType::Json,
            );
            $response = new Response($downstream->body, $downstream->statusCode, ['Content-Type' => 'application/json']);
            $response->headers->set('Cache-Control', 'no-store');
            $etag = $downstream->headers['etag'] ?? $downstream->headers['ETag'] ?? null;
            if (is_string($etag) && EntityTag::isStrong($etag)) {
                $response->headers->set('ETag', $etag);
            }
            $traceId = $downstream->headers['x-trace-id'] ?? $downstream->headers['X-Trace-Id'] ?? null;
            if (is_string($traceId) && preg_match('/\A[0-9a-f-]{36}\z/i', $traceId) === 1) {
                $response->headers->set('X-Trace-Id', $traceId);
            }

            return $response;
        } catch (OrganizationAccessDenied $exception) {
            return $this->error(403, 'Forbidden', $exception->getMessage());
        } catch (InvalidFormRequest $exception) {
            return $this->error($exception->status, Response::$statusTexts[$exception->status], $exception->getMessage());
        } catch (HttpRequestException) {
            return $this->error(502, 'Bad Gateway', 'The GoFormX management API is unavailable.');
        } catch (\Throwable) {
            return $this->error(503, 'Service Unavailable', 'The management credential boundary is unavailable.');
        }
    }

    private function assertCsrf(Request $request): void
    {
        $expected = $request->hasSession() ? $request->getSession()->get('_csrf_token') : null;
        $provided = $request->headers->get('X-XSRF-TOKEN');
        if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, rawurldecode($provided))) {
            throw new OrganizationAccessDenied('CSRF token validation failed.');
        }
    }

    private function jsonBody(Request $request, FormOperation $operation): string
    {
        $type = strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0]));
        $expected = $operation === FormOperation::Update ? 'application/merge-patch+json' : 'application/json';
        if ($type !== $expected) {
            throw new InvalidFormRequest(415, 'Content-Type must be ' . $expected . '.');
        }
        $body = $request->getContent();
        if (strlen($body) > 1_048_576) {
            throw new InvalidFormRequest(413, 'Form requests must not exceed 1 MiB.');
        }
        try {
            if (!(json_decode($body, false, 512, JSON_THROW_ON_ERROR) instanceof \stdClass)) {
                throw new InvalidFormRequest(400, 'Request body must be a JSON object.');
            }
        } catch (\JsonException) {
            throw new InvalidFormRequest(400, 'Request body is not valid JSON.');
        }
        // Preserve empty objects, number representations, and schema vocabulary verbatim.
        // Go owns validation and structured field/path errors; PHP is not a second schema engine.
        return $body;
    }

    private function boundedInteger(mixed $value, int $default, int $minimum, int $maximum, string $name): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        if (!is_string($value) || preg_match('/\A\d+\z/', $value) !== 1) {
            throw new \InvalidArgumentException("{$name} must be an integer.");
        }
        $parsed = (int) $value;
        if ($parsed < $minimum || $parsed > $maximum) {
            throw new \InvalidArgumentException("{$name} is outside the supported range.");
        }

        return $parsed;
    }

    private function error(int $status, string $title, string $detail): JsonResponse
    {
        $response = new JsonResponse([
            'errors' => [['status' => (string) $status, 'title' => $title, 'detail' => $detail]],
        ], $status);
        $response->headers->set('Cache-Control', 'no-store');
        return $response;
    }
}
