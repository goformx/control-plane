<?php

declare(strict_types=1);

namespace App\Domain\GoFormX;

use App\Domain\Organization\OrganizationRole;
use Symfony\Component\Uid\Uuid;

enum IntegrationOperation: string
{
    case Tokens = 'tokens';
    case CreateToken = 'create_token';
    case RevokeToken = 'revoke_token';
    case Webhook = 'webhook';
    case PutWebhook = 'put_webhook';
    case PatchWebhook = 'patch_webhook';
    case DeleteWebhook = 'delete_webhook';
    case ReplayDelivery = 'replay_delivery';

    public function allowedFor(OrganizationRole $role): bool
    {
        return in_array($role, [OrganizationRole::Owner, OrganizationRole::Admin], true);
    }

    public function method(): string
    {
        return match ($this) {
            self::Tokens, self::Webhook => 'GET',
            self::CreateToken, self::ReplayDelivery => 'POST',
            self::PutWebhook => 'PUT', self::PatchWebhook => 'PATCH',
            self::RevokeToken, self::DeleteWebhook => 'DELETE',
        };
    }

    public function template(): string
    {
        return match ($this) {
            self::Tokens, self::CreateToken => '/v1/service-tokens',
            self::RevokeToken => '/v1/service-tokens/{tokenId}',
            self::ReplayDelivery => '/v1/forms/{formId}/deliveries/{deliveryId}/replay',
            default => '/v1/forms/{formId}/webhook',
        };
    }

    public function path(string $formId, string $tokenId, string $deliveryId): string
    {
        $path = $this->template();
        if ((str_contains($path, '{formId}') && !Uuid::isValid($formId)) ||
            (str_contains($path, '{deliveryId}') && !Uuid::isValid($deliveryId)) ||
            (str_contains($path, '{tokenId}') && preg_match('/\A[A-Za-z0-9_-]{16}\z/', $tokenId) !== 1)) {
            throw new \InvalidArgumentException('Invalid integration resource selector.');
        }
        return str_replace(['{formId}', '{tokenId}', '{deliveryId}'], [$formId, $tokenId, $deliveryId], $path);
    }

    public function scope(): ManagementScope
    {
        return match ($this) {
            self::Tokens => ManagementScope::TokensRead,
            self::CreateToken, self::RevokeToken => ManagementScope::TokensWrite,
            self::Webhook => ManagementScope::WebhooksRead,
            default => ManagementScope::WebhooksWrite,
        };
    }

    public function hasBody(): bool
    {
        return in_array($this, [self::CreateToken, self::PutWebhook, self::PatchWebhook], true);
    }

    /** Delegation is validated before signing; it is never an arbitrary browser scope grant. */
    public function scopes(?string $body): array
    {
        $scopes = [$this->scope()];
        if ($this !== self::CreateToken) { return $scopes; }
        $request = json_decode($body ?? '', false, 32, JSON_THROW_ON_ERROR);
        $selected = $request instanceof \stdClass ? ($request->scopes ?? null) : null;
        if (!is_array($selected) || !array_is_list($selected) || $selected === [] || count($selected) > count(ManagementScope::cases())) {
            throw new \InvalidArgumentException('Select a bounded list of token scopes.');
        }
        $seen = [];
        foreach ($selected as $value) {
            $scope = is_string($value) ? ManagementScope::tryFrom($value) : null;
            if ($scope === null || isset($seen[$scope->value])) {
                throw new \InvalidArgumentException('Select canonical, duplicate-free token scopes.');
            }
            $seen[$scope->value] = true;
            if (!in_array($scope, $scopes, true)) { $scopes[] = $scope; }
        }
        return $scopes;
    }
}
