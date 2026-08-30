<?php

declare(strict_types=1);

namespace App\Domain\GoFormX;

use App\Domain\Organization\OrganizationRole;
use Symfony\Component\Uid\Uuid;

/** Explicit application policy for the canonical forms API, never browser-selected scopes. */
enum FormOperation: string
{
    case List = 'list';
    case Create = 'create';
    case Get = 'get';
    case Update = 'update';
    case ListVersions = 'list_versions';
    case CreateVersion = 'create_version';
    case GetVersion = 'get_version';
    case PublishVersion = 'publish_version';

    public function method(): string
    {
        return match ($this) {
            self::List, self::Get, self::ListVersions, self::GetVersion => 'GET',
            self::Update => 'PATCH',
            self::Create, self::CreateVersion, self::PublishVersion => 'POST',
        };
    }

    public function scope(): ManagementScope
    {
        return match ($this) {
            self::List, self::Get, self::ListVersions, self::GetVersion => ManagementScope::FormsRead,
            self::Create, self::Update, self::CreateVersion => ManagementScope::FormsWrite,
            self::PublishVersion => ManagementScope::FormsPublish,
        };
    }

    public function allowedFor(OrganizationRole $role): bool
    {
        return match ($role) {
            OrganizationRole::Owner, OrganizationRole::Admin => true,
            OrganizationRole::Member => $this->scope() === ManagementScope::FormsRead,
        };
    }

    public function template(): string
    {
        return match ($this) {
            self::List, self::Create => '/v1/forms',
            self::Get, self::Update => '/v1/forms/{formId}',
            self::ListVersions, self::CreateVersion => '/v1/forms/{formId}/versions',
            self::GetVersion => '/v1/forms/{formId}/versions/{version}',
            self::PublishVersion => '/v1/forms/{formId}/versions/{version}/publish',
        };
    }

    public function path(string $formId = '', string $version = ''): string
    {
        $path = $this->template();
        if (str_contains($path, '{formId}') && !Uuid::isValid($formId)) {
            throw new \InvalidArgumentException('formId must be a UUID.');
        }
        if (str_contains($path, '{version}') &&
            filter_var($version, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) {
            throw new \InvalidArgumentException('version must be a positive integer.');
        }
        return str_replace(['{formId}', '{version}'], [$formId, $version], $path);
    }

    public function hasBody(): bool
    {
        return in_array($this, [self::Create, self::Update, self::CreateVersion], true);
    }
}
