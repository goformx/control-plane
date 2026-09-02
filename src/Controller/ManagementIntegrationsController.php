<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\GoFormX\IntegrationOperation;
use App\Domain\GoFormX\RequestMediaType;
use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use App\Http\IntegrationResponse;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final readonly class ManagementIntegrationsController
{
    public function __construct(private OrganizationRequestContextResolverInterface $organizations, private ManagementApiClientInterface $client) {}

    public function handle(Request $request, IntegrationOperation $operation): Response
    {
        $headers = [];
        try {
            if ($operation->method() !== 'GET') {
                $expected = $request->hasSession() ? $request->getSession()->get('_csrf_token') : null;
                $provided = $request->headers->get('X-XSRF-TOKEN');
                if (!is_string($expected) || $expected === '' || !is_string($provided) || !hash_equals($expected, rawurldecode($provided))) { throw new OrganizationAccessDenied(); }
            }
            $context = $this->organizations->resolve($request);
            if (!$operation->allowedFor($context->organization->role)) { throw new OrganizationAccessDenied(); }
            $form = (string) $request->attributes->get('formId', '');
            $delivery = (string) $request->attributes->get('deliveryId', '');
            $path = $operation->path($form, (string) $request->attributes->get('tokenId', ''), $delivery);
            // Token inventory is explicitly bounded. Only Go's opaque cursor is proxied;
            // the browser cannot select a limit or add arbitrary query parameters.
            $query = (string) $request->server->get('QUERY_STRING', '');
            if ($operation === IntegrationOperation::Tokens) {
                if ($query === '') { $path .= '?limit=100';
                } elseif (strlen($query) <= 1031 && preg_match('/\Acursor=([A-Za-z0-9_-]{1,1024})\z/', $query, $matches) === 1) {
                    $path .= '?limit=100&cursor=' . $matches[1];
                } else { return $this->error(400); }
            } elseif ($query !== '') { return $this->error(400); }
            $body = null;
            if ($operation->hasBody()) {
                if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0])) !== 'application/json') { return $this->error(415); }
                $body = $request->getContent();
                if (strlen($body) > 16384) { return $this->error(413); }
                if (!(json_decode($body, false, 32, JSON_THROW_ON_ERROR) instanceof \stdClass)) { return $this->error(400); }
            }
            $scopes = $operation->scopes($body);
            $downstream = $this->client->request($operation->method(), $path, $context->account->subjectId,
                $context->organization->organizationId, $scopes, $body, mediaType: RequestMediaType::Json);
            if (strlen($downstream->body) > 262144) { throw new \UnexpectedValueException(); }
            $headers = array_change_key_case($downstream->headers, CASE_LOWER);
            if ($downstream->statusCode >= 400) {
                $payload = json_decode($downstream->body, true);
                $downstreamCode = is_string($payload['error']['code'] ?? null) ? $payload['error']['code'] : null;
                $assertionFailure = $downstream->statusCode === 401;
                $noCommitCode = $assertionFailure ? 'data_plane_authentication_failed' :
                    ($downstream->statusCode === 503 && in_array($downstreamCode,
                        ['management_audit_unavailable', 'webhooks_disabled', 'service_unavailable'], true) ? $downstreamCode : null);
                // A 401 here is from Go's first-party assertion boundary, not the browser's PHP session.
                return $this->safeDownstreamHeaders($this->error($assertionFailure ? 502 : $downstream->statusCode,
                    $noCommitCode, true), $headers, $downstream->statusCode);
            }
            $expectedStatus = match ($operation) {
                IntegrationOperation::CreateToken => 201, IntegrationOperation::ReplayDelivery => 202,
                IntegrationOperation::RevokeToken, IntegrationOperation::DeleteWebhook => 204, default => 200,
            };
            if ($downstream->statusCode !== $expectedStatus) { throw new \UnexpectedValueException(); }
            if ($expectedStatus === 204) { return $this->safeDownstreamHeaders($this->privateResponse(new Response('', 204)),
                $headers, $downstream->statusCode); }
            if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') { throw new \UnexpectedValueException(); }
            try { $payload = json_decode($downstream->body, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new \UnexpectedValueException(); }
            return $this->safeDownstreamHeaders($this->privateResponse(new JsonResponse(IntegrationResponse::project($operation, $payload,
                $context->organization->organizationId, $form, $delivery), $expectedStatus)), $headers, $downstream->statusCode);
        } catch (OrganizationAccessDenied) { return $this->error(403);
        } catch (\InvalidArgumentException|\JsonException) { return $this->error(400);
        } catch (\Throwable) { return $this->safeDownstreamHeaders($this->error(502), $headers, 0); }
    }

    private function error(int $status, ?string $noCommitCode = null, bool $downstream = false): Response
    {
        $messages = [400 => 'Check the integration request and selected scopes.', 401 => 'Sign in again.',
            403 => $downstream ? 'The data plane denied this integration operation.' : 'Your current workspace membership does not allow integration management.',
            404 => 'The integration resource is not available in this workspace.',
            409 => 'The integration changed concurrently. Reload metadata before retrying.',
            412 => 'The integration precondition is stale. Reload metadata before retrying.',
            413 => 'Integration requests must not exceed 16 KiB.', 415 => 'Use application/json.',
            422 => 'Check the destination, secret length, name, expiry and scopes.', 429 => 'Too many requests. Wait before retrying.'];
        $noCommitMessages = [
            'data_plane_authentication_failed' => 'No change was committed because data-plane authentication is unavailable.',
            'management_audit_unavailable' => 'No change was committed because its audit could not be stored.',
            'webhooks_disabled' => 'No change was committed because webhook management is not available.',
            'service_unavailable' => 'No change was committed because service-token management is not available.',
        ];
        $noCommitMessage = $noCommitCode !== null ? ($noCommitMessages[$noCommitCode] ?? null) : null;
        $safeStatus = array_key_exists($status, $messages) || in_array($status, [500, 502, 503, 504], true) ? $status : 502;
        $errorCode = $noCommitMessage !== null ? $noCommitCode : ($downstream && $safeStatus === 403 ? 'data_plane_access_denied' : 'integration_request_failed');
        return $this->privateResponse(new JsonResponse(['error' => [
            'code' => $errorCode,
            'message' => $noCommitMessage ?? ($messages[$safeStatus] ?? 'The outcome may be uncertain. Reload metadata and reconcile before retrying; do not create another credential blindly.'),
        ]], $safeStatus));
    }

    /** @param array<string, string> $headers */
    private function safeDownstreamHeaders(Response $response, array $headers, int $status): Response
    {
        $trace = $headers['x-trace-id'] ?? '';
        if (Uuid::isValid($trace)) { $response->headers->set('X-Trace-Id', $trace); }
        if ($status === 429 && preg_match('/\A[1-9][0-9]{0,2}\z/', $headers['retry-after'] ?? '') === 1) {
            $response->headers->set('Retry-After', $headers['retry-after']);
        }
        return $response;
    }

    private function privateResponse(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        return $response;
    }
}
