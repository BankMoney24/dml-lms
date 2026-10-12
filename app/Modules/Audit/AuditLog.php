<?php

declare(strict_types=1);

namespace App\Modules\Audit;

use App\Core\Clock;
use App\Core\Database\Connection;

/**
 * Append-only audit trail for privileged and sensitive actions (ADM-002).
 * Metadata must describe what happened, never carry secrets or learner
 * answers. Client IPs are stored as keyed hashes.
 */
final class AuditLog
{
    public function __construct(
        private readonly Connection $db,
        private readonly Clock $clock,
        private readonly string $hashKey,
    ) {
    }

    /** @param array<string, scalar|null> $metadata */
    public function record(
        ?int $tenantId,
        ?int $actorUserId,
        string $action,
        ?string $subjectType = null,
        ?int $subjectId = null,
        array $metadata = [],
        ?string $clientIp = null,
    ): void {
        $this->db->insert(
            'INSERT INTO audit_events (tenant_id, actor_user_id, action, subject_type, subject_id, metadata, ip_hash, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId,
                $actorUserId,
                $action,
                $subjectType,
                $subjectId,
                $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
                $clientIp === null ? null : hash_hmac('sha256', $clientIp, $this->hashKey),
                $this->clock->sqlNow(),
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    public function recentForTenant(int $tenantId, int $limit = 50): array
    {
        return $this->db->fetchAll(
            'SELECT a.action, a.subject_type, a.subject_id, a.created_at, u.display_name AS actor
             FROM audit_events a LEFT JOIN users u ON u.id = a.actor_user_id
             WHERE a.tenant_id = ? ORDER BY a.id DESC LIMIT ?',
            [$tenantId, max(1, min($limit, 200))],
        );
    }
}
