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

    /**
     * Newest first, paged by id so new events do not shift pages.
     *
     * @return list<array{id: int, action: string, actor: ?string, subject_type: ?string, subject_id: ?int,
     *                    subject_name: ?string, metadata: array<string, mixed>, created_at: string}>
     */
    public function page(int $tenantId, ?int $beforeId, int $limit = 50): array
    {
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.action, a.subject_type, a.subject_id, a.metadata, a.created_at, actor.display_name AS actor,
                    CASE a.subject_type
                      WHEN 'user' THEN (SELECT display_name FROM users WHERE id = a.subject_id)
                      WHEN 'course' THEN (SELECT title FROM courses WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                      WHEN 'lesson' THEN (SELECT title FROM lessons WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                    END AS subject_name
             FROM audit_events a LEFT JOIN users actor ON actor.id = a.actor_user_id
             WHERE a.tenant_id = ? AND a.id < ?
             ORDER BY a.id DESC LIMIT ?",
            [$tenantId, $beforeId ?? PHP_INT_MAX, max(1, min($limit, 200))],
        );

        return array_map(static function (array $r): array {
            $meta = is_string($r['metadata']) ? json_decode($r['metadata'], true) : null;

            return [
                'id' => (int) $r['id'],
                'action' => (string) $r['action'],
                'actor' => $r['actor'] === null ? null : (string) $r['actor'],
                'subject_type' => $r['subject_type'] === null ? null : (string) $r['subject_type'],
                'subject_id' => $r['subject_id'] === null ? null : (int) $r['subject_id'],
                'subject_name' => $r['subject_name'] === null ? null : (string) $r['subject_name'],
                'metadata' => is_array($meta) ? $meta : [],
                'created_at' => (string) $r['created_at'],
            ];
        }, $rows);
    }
}
