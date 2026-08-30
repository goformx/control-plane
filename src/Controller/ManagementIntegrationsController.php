<?php

declare(strict_types=1);

namespace App\Controller;

use App\Domain\GoFormX\IntegrationOperation;
use App\Domain\Organization\OrganizationAccessDenied;
use App\Domain\Organization\OrganizationRequestContextResolverInterface;
use App\Http\IntegrationResponse;
use App\Infrastructure\GoFormX\ManagementApiClientInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ManagementIntegrationsController
{
    public function __construct(private OrganizationRequestContextResolverInterface $organizations, private ManagementApiClientInterface $client) {}

    public function handle(Request $request, IntegrationOperation $operation): Response
    {
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
            // Token inventory is explicitly bounded. No arbitrary query is proxied.
            if ($operation === IntegrationOperation::Tokens) { $path .= '?limit=100'; }
            if ((string) $request->server->get('QUERY_STRING', '') !== '') { return $this->error(400); }
            $body = null;
            if ($operation->hasBody()) {
                if (strtolower(trim(explode(';', $request->headers->get('Content-Type', ''))[0])) !== 'application/json') { return $this->error(415); }
                $body = $request->getContent();
                if (strlen($body) > 16384) { return $this->error(413); }
                if (!(json_decode($body, false, 32, JSON_THROW_ON_ERROR) instanceof \stdClass)) { return $this->error(400); }
            }
            $scopes = $operation->scopes($body);
            $downstream = $this->client->request($operation->method(), $path, $context->account->subjectId,
                $context->organization->organizationId, $scopes, $body);
            if (strlen($downstream->body) > 262144) { throw new \UnexpectedValueException(); }
            if ($downstream->statusCode >= 400) {
                $payload = json_decode($downstream->body, true);
                $auditUnavailable = $downstream->statusCode === 503 && ($payload['error']['code'] ?? '') === 'management_audit_unavailable';
                return $this->error($downstream->statusCode, $auditUnavailable);
            }
            $expectedStatus = match ($operation) {
                IntegrationOperation::CreateToken => 201, IntegrationOperation::ReplayDelivery => 202,
                IntegrationOperation::RevokeToken, IntegrationOperation::DeleteWebhook => 204, default => 200,
            };
            if ($downstream->statusCode !== $expectedStatus) { throw new \UnexpectedValueException(); }
            if ($expectedStatus === 204) { return $this->privateResponse(new Response('', 204)); }
            $headers = array_change_key_case($downstream->headers, CASE_LOWER);
            if (strtolower(trim(explode(';', $headers['content-type'] ?? '')[0])) !== 'application/json') { throw new \UnexpectedValueException(); }
            try { $payload = json_decode($downstream->body, true, 32, JSON_THROW_ON_ERROR); }
            catch (\JsonException) { throw new \UnexpectedValueException(); }
            return $this->privateResponse(new JsonResponse(IntegrationResponse::project($operation, $payload,
                $context->organization->organizationId, $form, $delivery), $expectedStatus));
        } catch (OrganizationAccessDenied) { return $this->error(403);
        } catch (\InvalidArgumentException|\JsonException) { return $this->error(400);
        } catch (\Throwable) { return $this->error(502); }
    }

    private function error(int $status, bool $auditUnavailable = false): Response
    {
        $messages = [400 => 'Check the integration request and selected scopes.', 401 => 'Sign in again.',
            403 => 'Your current workspace membership does not allow integration management.', 404 => 'The integration resource is not available in this workspace.',
            413 => 'Integration requests must not exceed 16 KiB.', 415 => 'Use application/json.',
            422 => 'Check the destination, secret length, name, expiry and scopes.', 429 => 'Too many requests. Wait before retrying.'];
        $safeStatus = array_key_exists($status, $messages) || in_array($status, [500, 502, 503, 504], true) ? $status : 502;
        return $this->privateResponse(new JsonResponse(['error' => [
            'code' => $auditUnavailable ? 'management_audit_unavailable' : 'integration_request_failed',
            'message' => $auditUnavailable ? 'No change was committed because its audit could not be stored.' : ($messages[$safeStatus] ?? 'The outcome may be uncertain. Reload metadata and reconcile before retrying; do not create another credential blindly.'),
        ]], $safeStatus));
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
