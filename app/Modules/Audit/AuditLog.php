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
     * @param list<string> $actions
     * @return list<array{id: int, action: string, actor: ?string, subject_type: ?string, subject_id: ?int,
     *                    subject_name: ?string, metadata: array<string, mixed>, created_at: string}>
     */
    public function page(int $tenantId, ?int $beforeId, int $limit = 50, array $actions = [], string $who = ''): array
    {
        $where = 'a.tenant_id = ? AND a.id < ?';
        $params = [$tenantId, $beforeId ?? PHP_INT_MAX];
        // $actions: "auth.%" style patterns (one kind of activity); $who: words in the person's name or email.
        if ($actions !== []) {
            $where .= ' AND (' . implode(' OR ', array_fill(0, count($actions), 'a.action LIKE ?')) . ')';
            array_push($params, ...$actions);
        }
        foreach (array_slice(array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim($who))) ?: [], static fn (string $w): bool => $w !== '')), 0, 4) as $word) {
            $like = '%' . addcslashes(mb_substr($word, 0, 60), '%_\\') . '%';
            $where .= ' AND (LOWER(actor.display_name) LIKE ? OR LOWER(actor.email) LIKE ?)';
            array_push($params, $like, $like);
        }
        $params[] = max(1, min($limit, 200));
        $rows = $this->db->fetchAll(
            "SELECT a.id, a.action, a.subject_type, a.subject_id, a.metadata, a.created_at, actor.display_name AS actor,
                    CASE a.subject_type
                      WHEN 'user' THEN (SELECT display_name FROM users WHERE id = a.subject_id)
                      WHEN 'course' THEN (SELECT title FROM courses WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                      WHEN 'lesson' THEN (SELECT title FROM lessons WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                      WHEN 'lesson_question' THEN (SELECT l.title FROM lesson_questions q JOIN lessons l ON l.tenant_id = q.tenant_id AND l.id = q.lesson_id
                                                   WHERE q.tenant_id = a.tenant_id AND q.id = a.subject_id)
                      WHEN 'group' THEN (SELECT name FROM learner_groups WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                      WHEN 'learning_path' THEN (SELECT title FROM learning_paths WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                      WHEN 'class_session' THEN (SELECT title FROM class_sessions WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                      WHEN 'meeting' THEN (SELECT title FROM meetings WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                      WHEN 'blog_post' THEN (SELECT title FROM blog_posts WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                      WHEN 'site_page' THEN (SELECT title FROM site_pages WHERE tenant_id = a.tenant_id AND id = a.subject_id)
                    END AS subject_name
             FROM audit_events a LEFT JOIN users actor ON actor.id = a.actor_user_id
             WHERE {$where}
             ORDER BY a.id DESC LIMIT ?",
            $params,
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
