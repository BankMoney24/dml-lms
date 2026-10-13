<?php

declare(strict_types=1);

namespace App\Modules\Study;

use App\Core\Database\Connection;

/**
 * Due dates the school set for a learner through their classes (ADR 0013),
 * for courses they have not finished. One row per course, with the earliest date.
 */
final class Deadlines
{
    public function __construct(private readonly Connection $db)
    {
    }

    /** @return list<array{course_id: int, slug: string, title: string, due_on: string, group: string, started: bool}> */
    public function forLearner(int $tenantId, int $userId, ?string $from = null, int $limit = 100): array
    {
        $rows = $this->db->fetchAll(
            "SELECT c.id, c.slug, c.title, MIN(gc.due_on) AS due_on, MIN(g.name) AS group_name, MAX(e.id) AS enrolment_id
             FROM learner_group_courses gc
             JOIN learner_group_members m ON m.tenant_id = gc.tenant_id AND m.group_id = gc.group_id AND m.user_id = ? AND m.role = 'learner'
             JOIN learner_groups g ON g.tenant_id = gc.tenant_id AND g.id = gc.group_id AND g.archived_at IS NULL
             JOIN courses c ON c.tenant_id = gc.tenant_id AND c.id = gc.course_id AND c.status = 'published'
             LEFT JOIN enrolments e ON e.tenant_id = gc.tenant_id AND e.course_id = gc.course_id AND e.user_id = m.user_id
             WHERE gc.tenant_id = ? AND gc.due_on IS NOT NULL AND (e.status IS NULL OR e.status <> 'completed')"
             . ($from !== null ? ' AND gc.due_on >= ?' : '') . "
             GROUP BY c.id, c.slug, c.title
             ORDER BY due_on, c.title LIMIT " . max(1, $limit),
            $from !== null ? [$userId, $tenantId, $from] : [$userId, $tenantId],
        );

        return array_map(static fn (array $r): array => [
            'course_id' => (int) $r['id'],
            'slug' => (string) $r['slug'],
            'title' => (string) $r['title'],
            'due_on' => substr((string) $r['due_on'], 0, 10),
            'group' => (string) $r['group_name'],
            'started' => $r['enrolment_id'] !== null,
        ], $rows);
    }
}
