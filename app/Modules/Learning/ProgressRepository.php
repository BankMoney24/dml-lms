<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Core\Clock;
use App\Core\Database\Connection;

final class ProgressRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function markStarted(int $tenantId, int $enrolmentId, int $lessonId): void
    {
        $this->db->execute(
            'INSERT IGNORE INTO lesson_progress (tenant_id, enrolment_id, lesson_id, status, updated_at) VALUES (?, ?, ?, \'started\', ?)',
            [$tenantId, $enrolmentId, $lessonId, $this->clock->sqlNow()],
        );
    }

    /** Idempotent; completion is never downgraded. */
    /**
     * Average of this enrolment's best scores across the course's required quizzes,
     * counting a quiz not yet tried as 0. Null when the course has no required quiz.
     *
     * @return array{average: int, quizzes: int}|null
     */
    public function quizAverage(int $tenantId, int $enrolmentId, int $courseId): ?array
    {
        $rows = $this->db->fetchAll(
            'SELECT a.id, COALESCE(MAX(q.score_percent), 0) AS best
             FROM assessments a
             JOIN lessons l ON l.tenant_id = a.tenant_id AND l.id = a.lesson_id
             LEFT JOIN quiz_attempts q ON q.tenant_id = a.tenant_id AND q.assessment_id = a.id AND q.enrolment_id = ?
             WHERE a.tenant_id = ? AND l.course_id = ? AND l.is_mandatory = 1
             GROUP BY a.id',
            [$enrolmentId, $tenantId, $courseId],
        );
        if ($rows === []) {
            return null;
        }
        $sum = array_sum(array_map(static fn (array $r): int => (int) $r['best'], $rows));

        return ['average' => (int) floor($sum / count($rows)), 'quizzes' => count($rows)];
    }

    public function markCompleted(int $tenantId, int $enrolmentId, int $lessonId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'INSERT INTO lesson_progress (tenant_id, enrolment_id, lesson_id, status, completed_at, updated_at)
             VALUES (?, ?, ?, \'completed\', ?, ?)
             ON DUPLICATE KEY UPDATE status = \'completed\', completed_at = COALESCE(completed_at, ?), updated_at = ?',
            [$tenantId, $enrolmentId, $lessonId, $now, $now, $now, $now],
        );
    }

    /** @return list<int> */
    public function completedLessonIds(int $tenantId, int $enrolmentId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT lesson_id FROM lesson_progress WHERE tenant_id = ? AND enrolment_id = ? AND status = \'completed\'',
            [$tenantId, $enrolmentId],
        );

        return array_map(static fn (array $r): int => (int) $r['lesson_id'], $rows);
    }
}
