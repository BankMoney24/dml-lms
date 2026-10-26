<?php

declare(strict_types=1);

namespace App\Modules\Groups;

use App\Core\Database\Connection;

/**
 * Progress of a group's learners in the group's courses, and of one learner
 * in their group's courses. Read-only and tenant-scoped.
 */
final class GroupProgress
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @param list<int> $userIds
     * @param list<int> $courseIds
     * @return array{
     *   lessons: array<int, int>,
     *   cells: array<int, array<int, array{status: string, done: int}>>,
     *   last_active: array<int, string>
     * } lessons per course; cells[user][course]; last lesson activity per user
     */
    public function matrix(int $tenantId, array $userIds, array $courseIds): array
    {
        $result = ['lessons' => [], 'cells' => [], 'last_active' => []];
        if ($courseIds === []) {
            return $result;
        }
        $in = static fn (array $ids): string => implode(',', array_fill(0, count($ids), '?'));
        foreach (
            $this->db->fetchAll(
                'SELECT course_id, COUNT(*) AS n FROM lessons WHERE tenant_id = ? AND course_id IN (' . $in($courseIds) . ') GROUP BY course_id',
                array_merge([$tenantId], $courseIds),
            ) as $r
        ) {
            $result['lessons'][(int) $r['course_id']] = (int) $r['n'];
        }
        if ($userIds === []) {
            return $result;
        }
        foreach (
            $this->db->fetchAll(
                "SELECT e.user_id, e.course_id, e.status,
                        (SELECT COUNT(*) FROM lesson_progress p WHERE p.tenant_id = e.tenant_id AND p.enrolment_id = e.id AND p.status = 'completed') AS done,
                        (SELECT MAX(p.updated_at) FROM lesson_progress p WHERE p.tenant_id = e.tenant_id AND p.enrolment_id = e.id) AS last_at
                 FROM enrolments e
                 WHERE e.tenant_id = ? AND e.status <> 'withdrawn' AND e.user_id IN (" . $in($userIds) . ') AND e.course_id IN (' . $in($courseIds) . ')',
                array_merge([$tenantId], $userIds, $courseIds),
            ) as $r
        ) {
            $user = (int) $r['user_id'];
            $result['cells'][$user][(int) $r['course_id']] = ['status' => (string) $r['status'], 'done' => (int) $r['done']];
            $last = $r['last_at'] === null ? null : (string) $r['last_at'];
            if ($last !== null && $last > ($result['last_active'][$user] ?? '')) {
                $result['last_active'][$user] = $last;
            }
        }

        return $result;
    }
}
