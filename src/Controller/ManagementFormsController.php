<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\GoFormX\ManagementScope;
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
        try {
            $pagination = $request->query->all();
            $limit = $this->boundedInteger($pagination['limit'] ?? null, 25, 1, 100, 'limit');
            $offset = $this->boundedInteger($pagination['offset'] ?? null, 0, 0, 10000, 'offset');
            $context = $this->organizations->resolve($request);
            $downstream = $this->client->request(
                'GET',
                '/v1/forms?' . http_build_query(['limit' => $limit, 'offset' => $offset]),
                $context->account->subjectId,
                $context->organization->organizationId,
                [ManagementScope::FormsRead],
            );
            $response = new Response($downstream->body, $downstream->statusCode, ['Content-Type' => 'application/json']);
            $response->headers->set('Cache-Control', 'no-store');
            $traceId = $downstream->headers['x-trace-id'] ?? $downstream->headers['X-Trace-Id'] ?? null;
            if (is_string($traceId) && preg_match('/\A[0-9a-f-]{36}\z/i', $traceId) === 1) {
                $response->headers->set('X-Trace-Id', $traceId);
            }

            return $response;
        } catch (OrganizationAccessDenied $exception) {
            return $this->error(403, 'Forbidden', $exception->getMessage());
        } catch (\InvalidArgumentException $exception) {
            return $this->error(400, 'Bad Request', $exception->getMessage());
        } catch (HttpRequestException) {
            return $this->error(502, 'Bad Gateway', 'The GoFormX management API is unavailable.');
        } catch (\Throwable) {
            return $this->error(503, 'Service Unavailable', 'The management credential boundary is unavailable.');
        }
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
        return new JsonResponse([
            'errors' => [['status' => (string) $status, 'title' => $title, 'detail' => $detail]],
        ], $status);
    }
}
