<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Core\Clock;
use App\Core\Database\Connection;

final class EnrolmentRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $tenantId, int $courseId, int $userId): ?Enrolment
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM enrolments WHERE tenant_id = ? AND course_id = ? AND user_id = ?',
            [$tenantId, $courseId, $userId],
        );

        return $row === null ? null : Enrolment::fromRow($row);
    }

    /** Idempotent: a repeated enrol request returns the existing enrolment. */
    public function enrol(int $tenantId, int $courseId, int $userId): Enrolment
    {
        $this->db->execute(
            'INSERT IGNORE INTO enrolments (tenant_id, course_id, user_id, status, enrolled_at) VALUES (?, ?, ?, ?, ?)',
            [$tenantId, $courseId, $userId, Enrolment::STATUS_ACTIVE, $this->clock->sqlNow()],
        );
        $enrolment = $this->find($tenantId, $courseId, $userId);
        if ($enrolment === null) {
            throw new \LogicException('Enrolment insert did not persist.');
        }

        return $enrolment;
    }

    public function markCompleted(int $tenantId, int $enrolmentId): void
    {
        $this->db->execute(
            'UPDATE enrolments SET status = ?, completed_at = COALESCE(completed_at, ?) WHERE tenant_id = ? AND id = ?',
            [Enrolment::STATUS_COMPLETED, $this->clock->sqlNow(), $tenantId, $enrolmentId],
        );
    }

    /**
     * Learner dashboard rows with progress counts.
     *
     * @return list<array{course_id: int, slug: string, title: string, status: string, done: int, total: int}>
     */
    public function dashboardFor(int $tenantId, int $userId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT c.id AS course_id, c.slug, c.title, e.status,
                    (SELECT COUNT(*) FROM lesson_progress p
                       JOIN lessons l ON l.tenant_id = p.tenant_id AND l.id = p.lesson_id AND l.is_mandatory = 1
                      WHERE p.tenant_id = e.tenant_id AND p.enrolment_id = e.id AND p.status = \'completed\') AS done,
                    (SELECT COUNT(*) FROM lessons l2
                      WHERE l2.tenant_id = c.tenant_id AND l2.course_id = c.id AND l2.is_mandatory = 1) AS total
             FROM enrolments e JOIN courses c ON c.tenant_id = e.tenant_id AND c.id = e.course_id
             WHERE e.tenant_id = ? AND e.user_id = ? AND e.status IN (\'active\', \'completed\')
             ORDER BY e.enrolled_at DESC LIMIT 50',
            [$tenantId, $userId],
        );

        return array_map(static fn (array $r): array => [
            'course_id' => (int) $r['course_id'],
            'slug' => (string) $r['slug'],
            'title' => (string) $r['title'],
            'status' => (string) $r['status'],
            'done' => (int) $r['done'],
            'total' => (int) $r['total'],
        ], $rows);
    }
}
