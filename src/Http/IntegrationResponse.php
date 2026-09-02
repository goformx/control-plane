<?php

declare(strict_types=1);

namespace App\Http;

use App\Domain\GoFormX\IntegrationOperation;
use App\Domain\GoFormX\ManagementScope;
use Symfony\Component\Uid\Uuid;

/** Browser projection: stored secrets and arbitrary upstream fields are never forwarded. */
final class IntegrationResponse
{
    public static function project(IntegrationOperation $operation, array $payload, string $organization, string $form, string $delivery): array
    {
        $data = $payload['data'] ?? null;
        if (!is_array($data)) { throw new \UnexpectedValueException(); }
        if ($operation === IntegrationOperation::Tokens) {
            if (!array_is_list($data) || count($data) > 100) { throw new \UnexpectedValueException(); }
            $meta = $payload['meta'] ?? null;
            $next = is_array($meta) ? ($meta['nextCursor'] ?? null) : null;
            if (!is_array($meta) || !array_key_exists('nextCursor', $meta) || ($meta['limit'] ?? null) !== 100 ||
                ($next !== null && (!is_string($next) || preg_match('/\A[A-Za-z0-9_-]{1,1024}\z/', $next) !== 1))) {
                throw new \UnexpectedValueException();
            }
            return ['data' => array_map(fn(array $token): array => self::token($token, $organization), $data),
                'meta' => ['limit' => 100, 'nextCursor' => $next]];
        }
        if ($operation === IntegrationOperation::CreateToken) {
            $token = $data['token'] ?? null;
            if (!is_string($token) || preg_match('/\Agfst_[A-Za-z0-9_-]{43}\z/', $token) !== 1 || !is_array($data['metadata'] ?? null)) { throw new \UnexpectedValueException(); }
            $metadata = self::token($data['metadata'], $organization);
            $lookup = rtrim(strtr(base64_encode(substr(hash('sha256', $token, true), 0, 12)), '+/', '-_'), '=');
            if (!hash_equals($lookup, $metadata['id']) || $metadata['status'] !== 'active') { throw new \UnexpectedValueException(); }
            return ['data' => ['token' => $token, 'metadata' => $metadata]];
        }
        if ($operation === IntegrationOperation::ReplayDelivery) {
            if (($data['id'] ?? '') !== $delivery || ($data['status'] ?? '') !== 'pending') { throw new \UnexpectedValueException(); }
            return ['data' => ['id' => $delivery, 'status' => 'pending']];
        }
        if (!Uuid::isValid($data['id'] ?? '') || ($data['formId'] ?? '') !== $form || !is_bool($data['enabled'] ?? null)) { throw new \UnexpectedValueException(); }
        $origin = $data['origin'] ?? null;
        if (!is_string($origin) || strlen($origin) > 2048) { throw new \UnexpectedValueException(); }
        $parts = parse_url($origin);
        if (($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment']) || isset($parts['path'])) { throw new \UnexpectedValueException(); }
        return ['data' => ['id' => $data['id'], 'formId' => $form, 'origin' => $origin, 'enabled' => $data['enabled'],
            'createdAt' => self::date($data['createdAt'] ?? null), 'updatedAt' => self::date($data['updatedAt'] ?? null)]];
    }

    private static function token(array $data, string $organization): array
    {
        if (!is_string($data['id'] ?? null) || preg_match('/\A[A-Za-z0-9_-]{16}\z/', $data['id']) !== 1 ||
            ($data['organizationId'] ?? '') !== $organization || !is_string($data['name'] ?? null) || strlen($data['name']) > 400 ||
            !in_array($data['status'] ?? '', ['active', 'expired', 'revoked'], true) || !is_array($data['scopes'] ?? null) ||
            !array_is_list($data['scopes']) || count($data['scopes']) < 1 || count($data['scopes']) > 8) { throw new \UnexpectedValueException(); }
        foreach ($data['scopes'] as $scope) { if (!is_string($scope) || ManagementScope::tryFrom($scope) === null) { throw new \UnexpectedValueException(); } }
        $result = array_intersect_key($data, array_flip(['id', 'name', 'organizationId', 'scopes', 'status']));
        foreach (['createdAt', 'expiresAt', 'lastUsedAt', 'revokedAt'] as $key) {
            if (isset($data[$key]) || in_array($key, ['createdAt', 'expiresAt'], true)) { $result[$key] = self::date($data[$key] ?? null); }
        }
        return $result;
    }

    private static function date(mixed $value): string
    {
        if (!is_string($value) || preg_match('/\A\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d(?:\.\d{1,9})?(?:Z|[+-]\d\d:\d\d)\z/', $value) !== 1) { throw new \UnexpectedValueException(); }
        return $value;
    }
}
