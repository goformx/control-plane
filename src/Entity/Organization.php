<?php

declare(strict_types=1);

namespace App\Entity;

use Waaseyaa\Entity\Attribute\ContentEntityKeys;
use Waaseyaa\Entity\Attribute\ContentEntityType;
use Waaseyaa\Entity\Attribute\Field;
use Waaseyaa\Entity\ContentEntityBase;
use Waaseyaa\Entity\FieldReadLevel;
use Waaseyaa\Entity\Storage\PrimaryStorageBackend;

#[ContentEntityType(
    id: 'goformx_organization',
    label: 'GoFormX organization',
    description: 'Application-owned account and resource boundary.',
    api: false,
    storageBackend: PrimaryStorageBackend::SQL_COLUMN,
)]
#[ContentEntityKeys(id: 'organization_id', uuid: 'uuid', label: 'name')]
final class Organization extends ContentEntityBase
{
    #[Field(label: 'Name', read: FieldReadLevel::Public)]
    public string $name = '';

    #[Field(type: 'integer', label: 'Created by user', read: FieldReadLevel::Public, indexed: true)]
    public int $created_by_user_id = 0;

    #[Field(label: 'Status', default: 'active', settings: ['allowed_values' => ['active', 'disabled']], read: FieldReadLevel::Public, indexed: true)]
    public string $status = 'active';

    #[Field(type: 'integer', label: 'Created', settings: ['subtype' => 'timestamp'], read: FieldReadLevel::Public)]
    public int $created_at = 0;

    #[Field(type: 'integer', label: 'Updated', settings: ['subtype' => 'timestamp'], read: FieldReadLevel::Public)]
    public int $updated_at = 0;

    /** @param array<string, mixed> $values */
    public function __construct(array $values = [], string $entityTypeId = '', array $entityKeys = [], array $fieldDefinitions = [])
    {
        $now = time();
        $values += ['status' => 'active', 'created_at' => $now, 'updated_at' => $now];
        $values['created_at'] = is_int($values['created_at']) ? $values['created_at'] : $now;
        $values['updated_at'] = is_int($values['updated_at']) ? $values['updated_at'] : $now;

        if (trim((string) ($values['name'] ?? '')) === '') {
            throw new \InvalidArgumentException('Organization name is required.');
        }
        if ((int) ($values['created_by_user_id'] ?? 0) <= 0) {
            throw new \InvalidArgumentException('Organization creator must be an authenticated user.');
        }
        if (!in_array($values['status'], ['active', 'disabled'], true)) {
            throw new \InvalidArgumentException('Invalid organization status.');
        }

        parent::__construct($values, $entityTypeId, $entityKeys, $fieldDefinitions);
    }
}
