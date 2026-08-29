<?php

declare(strict_types=1);

namespace App\Entity;

use App\Domain\Organization\OrganizationRole;
use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\Attribute\StorageUniqueKey;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;

#[ContentEntityType(
    id: 'goformx_organization_membership',
    label: 'GoFormX organization membership',
    description: 'Audited user-to-organization authorization grant.',
    api: false,
    storageBackend: PrimaryStorageBackend::SQL_COLUMN,
)]
#[ContentEntityKeys(id: 'membership_id', uuid: 'uuid', label: 'role')]
#[StorageUniqueKey('goformx_membership_org_user_unique', ['organization_uuid', 'user_id'])]
final class OrganizationMembership extends ContentEntityBase
{
    #[Field(label: 'Organization UUID', read: FieldReadLevel::Public, indexed: true)]
    public string $organization_uuid = '';

    #[Field(type: 'integer', label: 'User ID', read: FieldReadLevel::Public, indexed: true)]
    public int $user_id = 0;

    #[Field(label: 'Role', default: 'member', settings: ['allowed_values' => ['owner', 'admin', 'member']], read: FieldReadLevel::Public, indexed: true)]
    public string $role = 'member';

    #[Field(label: 'Status', default: 'active', settings: ['allowed_values' => ['active', 'revoked']], read: FieldReadLevel::Public, indexed: true)]
    public string $status = 'active';

    #[Field(type: 'integer', label: 'Joined', settings: ['subtype' => 'timestamp'], read: FieldReadLevel::Public)]
    public int $joined_at = 0;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = [], array $fieldDefinitions = [])
    {
        $values += ['role' => OrganizationRole::Member->value, 'status' => 'active', 'joined_at' => time()];
        $values['joined_at'] = is_int($values['joined_at']) ? $values['joined_at'] : time();

        if (trim((string) ($values['organization_uuid'] ?? '')) === '') {
            throw new \InvalidArgumentException('Organization UUID is required.');
        }
        if ((int) ($values['user_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Membership user must be authenticated.');
        }
        if (OrganizationRole::tryFrom((string) $values['role']) === null) {
            throw new \InvalidArgumentException('Invalid organization role.');
        }
        if (!in_array($values['status'], ['active', 'revoked'], true)) {
            throw new \InvalidArgumentException('Invalid membership status.');
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
