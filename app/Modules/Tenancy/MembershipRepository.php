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

    /** Re-activates a removed membership with a (possibly new) role. */
    public function reactivate(int $tenantId, int $userId, Role $role): void
    {
        $this->db->execute(
            'UPDATE memberships SET status = ?, role = ?, updated_at = ? WHERE tenant_id = ? AND user_id = ?',
            [Membership::STATUS_ACTIVE, $role->value, $this->clock->sqlNow(), $tenantId, $userId],
        );
    }

    public function changeRole(int $tenantId, int $userId, Role $role): void
    {
        $this->db->execute(
            'UPDATE memberships SET role = ?, updated_at = ? WHERE tenant_id = ? AND user_id = ?',
            [$role->value, $this->clock->sqlNow(), $tenantId, $userId],
        );
    }

    /** Counts active owners, locking those rows so concurrent demotions cannot leave none. Call inside a transaction. */
    public function lockAndCountActiveOwners(int $tenantId): int
    {
        return count($this->db->fetchAll(
            "SELECT id FROM memberships WHERE tenant_id = ? AND role = 'owner' AND status = 'active' FOR UPDATE",
            [$tenantId],
        ));
    }

    /**
     * Active members with their user details, for the People page.
     *
     * @return list<array{user_id: int, name: string, email: string, role: Role, joined_at: string}>
     */
    public function listActive(int $tenantId): array
    {
        $rows = $this->db->fetchAll(
            "SELECT m.user_id, m.role, m.created_at, u.display_name, u.email
             FROM memberships m JOIN users u ON u.id = m.user_id
             WHERE m.tenant_id = ? AND m.status = 'active'
             ORDER BY FIELD(m.role, 'owner', 'admin', 'educator', 'learner', 'guardian'), u.display_name
             LIMIT 1000",
            [$tenantId],
        );

        return array_map(static fn (array $r): array => [
            'user_id' => (int) $r['user_id'],
            'name' => (string) $r['display_name'],
            'email' => (string) $r['email'],
            'role' => Role::from((string) $r['role']),
            'joined_at' => (string) $r['created_at'],
        ], $rows);
    }
}
