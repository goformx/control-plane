<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\GoFormX\ManagementScope;
use App\Domain\GoFormX\SubmissionOperation;
use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use App\Http\InvalidSubmissionRequest;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;
use Waaseyaa\HttpClient\HttpRequestException;

final readonly class ManagementSubmissionsController
{
    private const MAX_EXPORT_REQUEST_BYTES = 4096;
    private const MAX_RESPONSE_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private OrganizationRequestContextResolverInterface $organizations,
        private ManagementApiClientInterface $client,
    ) {}

    public function handle(Request $request, SubmissionOperation $operation): Response
    {
        try {
            $context = $this->organizations->resolve($request);
            if (!$operation->allowedFor($context->organization->role)) {
                throw new OrganizationAccessDenied('Your workspace role does not allow submission access.');
            }
            try {
                $path = $operation->path((string) $request->attributes->get('formId', ''), (string) $request->attributes->get('submissionId', ''));
            } catch (\InvalidArgumentException) {
                throw new InvalidSubmissionRequest(400, 'Form and submission selectors must be UUIDs.');
            }
            // Preserve duplicates and exact filter bytes for Go's canonical strict
            // parser. PHP's parsed query bag would silently collapse repeated keys.
            $query = (string) $request->server->get('QUERY_STRING', '');
            if (strlen($query) > 2048 || ($operation !== SubmissionOperation::List && $query !== '')) {
                throw new InvalidSubmissionRequest(400, 'Unsupported submission query.');
            }
            if ($query !== '') {
                $path .= '?' . $query;
            }
            $body = null;
            if ($operation === SubmissionOperation::Export) {
                $expected = $request->hasSession() ? $request->getSession()->get('_csrf_token') : null;
                $provided = $request->headers->get('X-XSRF-TOKEN');
                if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, rawurldecode($provided))) {
                    throw new OrganizationAccessDenied('CSRF token validation failed.');
                }
                if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0])) !== 'application/json') {
                    throw new InvalidSubmissionRequest(415, 'Export Content-Type must be application/json.');
                }
                $body = $request->getContent();
                if (strlen($body) > self::MAX_EXPORT_REQUEST_BYTES) {
                    throw new InvalidSubmissionRequest(413, 'Export request exceeds 4096 bytes.');
                }
                // Go owns JSON/filter validation. Do not decode/re-encode numeric
                // tokens, collapse duplicate fields, or log a rejected body here.
            }
            $downstream = $this->client->request($operation->method(), $path,
                $context->account->subjectId, $context->organization->organizationId,
                [ManagementScope::SubmissionsRead], $body);
            if (strlen($downstream->body) > self::MAX_RESPONSE_BYTES) {
                throw new \UnexpectedValueException('Oversized management response.');
            }
            $headers = array_change_key_case($downstream->headers, CASE_LOWER);
            $deliveryError = $operation === SubmissionOperation::Deliveries && in_array($downstream->statusCode, [401, 403], true);
            if ($downstream->statusCode === 401 || $deliveryError) {
                $response = $deliveryError ? $this->privateResponse(new JsonResponse(['error' => [
                    'code' => $downstream->statusCode === 401 ? 'data_plane_authentication_failed' : 'data_plane_access_denied',
                    'message' => $downstream->statusCode === 401 ? 'Data-plane authentication is unavailable.' : 'The data plane denied this integration operation.',
                ]], $downstream->statusCode === 401 ? 502 : 403)) :
                    $this->error(502, 'The submissions API could not authenticate with the data plane. Try again later.');
                $trace = $headers['x-trace-id'] ?? '';
                if (Uuid::isValid($trace)) { $response->headers->set('X-Trace-Id', $trace); }
                return $response;
            }
            $response = new Response($downstream->body, $downstream->statusCode, ['Content-Type' => 'application/json']);
            if ($operation === SubmissionOperation::Export && $downstream->statusCode === 200) {
                $exportId = $headers['x-goformx-export-id'] ?? '';
                $type = strtolower(trim(explode(';', $headers['content-type'] ?? '')[0]));
                $length = $headers['content-length'] ?? '';
                if (!Uuid::isValid($exportId) || !in_array($type, ['application/json', 'text/csv'], true)
                    || preg_match('/\A[1-9][0-9]{0,7}\z/', $length) !== 1 || (int) $length !== strlen($downstream->body)) {
                    throw new \UnexpectedValueException('Invalid export response metadata.');
                }
                $extension = $type === 'text/csv' ? 'csv' : 'json';
                $response->headers->set('Content-Type', $type === 'text/csv' ? 'text/csv; charset=utf-8' : $type);
                $response->headers->set('X-GoFormX-Export-ID', $exportId);
                $response->headers->set('Content-Length', $length);
                // Never forward an upstream filename or any arbitrary headers.
                $response->headers->set('Content-Disposition', 'attachment; filename="goformx-submissions-' . $exportId . '.' . $extension . '"');
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
            return $this->error(403, 'Submission access was denied. Check your workspace membership and session.');
        } catch (InvalidSubmissionRequest $exception) {
            return $this->error($exception->status, $exception->getMessage());
        } catch (HttpRequestException) {
            return $this->error(502, 'The submissions API is unavailable. No download was released.');
        } catch (\Throwable) {
            return $this->error(503, 'Submission operations are unavailable. No download was released.');
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
