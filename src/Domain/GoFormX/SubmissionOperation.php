<?php

declare(strict_types=1);

namespace App\Domain\GoFormX;

use App\Domain\Organization\OrganizationRole;
use Symfony\Component\Uid\Uuid;

/** Submission access is a separate application policy from form-definition reads. */
enum SubmissionOperation: string
{
    case List = 'list';
    case Get = 'get';
    case Export = 'export';
    case Deliveries = 'deliveries';

    public function method(): string
    {
        return $this === self::Export ? 'POST' : 'GET';
    }

    public function allowedFor(OrganizationRole $role): bool
    {
        return in_array($role, [OrganizationRole::Owner, OrganizationRole::Admin], true);
    }

    public function template(): string
    {
        return '/v1/forms/{formId}/' . match ($this) {
            self::List => 'submissions',
            self::Get => 'submissions/{submissionId}',
            self::Export => 'submissions/export',
            self::Deliveries => 'deliveries',
        };
    }

    public function path(string $formId, string $submissionId = ''): string
    {
        if (!Uuid::isValid($formId) || ($this === self::Get && !Uuid::isValid($submissionId))) {
            throw new \InvalidArgumentException('Form and submission selectors must be UUIDs.');
        }
        return str_replace(['{formId}', '{submissionId}'], [$formId, $submissionId], $this->template());
    }
}
