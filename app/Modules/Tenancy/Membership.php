<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Identity\Permission;
use App\Modules\Identity\Role;

final class Membership
{
    public const STATUS_ACTIVE = 'active';
    public const STATUS_REMOVED = 'removed';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly Role $role,
        public readonly string $status,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            Role::from((string) $row['role']),
            (string) $row['status'],
        );
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function allows(Permission $permission): bool
    {
        return $this->isActive() && in_array($permission, $this->role->permissions(), true);
    }
}
