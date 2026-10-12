<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Modules\Identity\Role;

final class MembershipRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $tenantId, int $userId): ?Membership
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM memberships WHERE tenant_id = ? AND user_id = ?',
            [$tenantId, $userId],
        );

        return $row === null ? null : Membership::fromRow($row);
    }

    public function add(int $tenantId, int $userId, Role $role): Membership
    {
        $now = $this->clock->sqlNow();
        $id = $this->db->insert(
            'INSERT INTO memberships (tenant_id, user_id, role, status, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $userId, $role->value, Membership::STATUS_ACTIVE, $now, $now],
        );

        return new Membership($id, $tenantId, $userId, $role, Membership::STATUS_ACTIVE);
    }

    public function remove(int $tenantId, int $userId): void
    {
        $this->db->execute(
            'UPDATE memberships SET status = ?, updated_at = ? WHERE tenant_id = ? AND user_id = ?',
            [Membership::STATUS_REMOVED, $this->clock->sqlNow(), $tenantId, $userId],
        );
    }
}
