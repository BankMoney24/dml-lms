<?php

declare(strict_types=1);

namespace App\Modules\Joining;

use App\Core\Clock;
use App\Core\Database\Connection;

final class JoinRequestRepository
{
    private const SELECT = 'SELECT r.*, d.display_name AS decided_by_name FROM join_requests r LEFT JOIN users d ON d.id = r.decided_by';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, string $kind, ?int $userId, string $name, string $email, string $phone, string $childName, string $message): int
    {
        return $this->db->insert(
            'INSERT INTO join_requests (tenant_id, kind, user_id, name, email, phone, child_name, message, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $kind, $userId, $name, $email, $phone, $childName, $message === '' ? null : $message, JoinRequest::PENDING, $this->clock->sqlNow()],
        );
    }

    public function find(int $tenantId, int $id, bool $lock = false): ?JoinRequest
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE r.tenant_id = ? AND r.id = ?' . ($lock ? ' FOR UPDATE' : ''), [$tenantId, $id]);

        return $row === null ? null : JoinRequest::fromRow($row);
    }

    /** @return list<JoinRequest> pending first (oldest first), then the latest decisions */
    public function queue(int $tenantId, int $decidedLimit = 20): array
    {
        $pending = $this->db->fetchAll(self::SELECT . " WHERE r.tenant_id = ? AND r.status = 'pending' ORDER BY r.created_at, r.id LIMIT 200", [$tenantId]);
        $done = $this->db->fetchAll(self::SELECT . " WHERE r.tenant_id = ? AND r.status <> 'pending' ORDER BY r.decided_at DESC, r.id DESC LIMIT " . max(1, $decidedLimit), [$tenantId]);

        return array_map(static fn (array $r): JoinRequest => JoinRequest::fromRow($r), [...$pending, ...$done]);
    }

    public function pendingFor(int $tenantId, int $userId, string $kind): ?JoinRequest
    {
        $row = $this->db->fetchOne(self::SELECT . " WHERE r.tenant_id = ? AND r.user_id = ? AND r.kind = ? ORDER BY r.id DESC LIMIT 1", [$tenantId, $userId, $kind]);

        return $row === null ? null : JoinRequest::fromRow($row);
    }

    public function pendingCount(int $tenantId): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM join_requests WHERE tenant_id = ? AND status = 'pending'", [$tenantId]);
    }

    public function decide(int $tenantId, int $id, string $status, int $deciderId): void
    {
        $this->db->execute(
            "UPDATE join_requests SET status = ?, decided_by = ?, decided_at = ? WHERE tenant_id = ? AND id = ? AND status = 'pending'",
            [$status, $deciderId, $this->clock->sqlNow(), $tenantId, $id],
        );
    }
}
