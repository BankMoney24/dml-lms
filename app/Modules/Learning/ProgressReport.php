<?php

declare(strict_types=1);

namespace App\Modules\Learning;

use App\Core\Database\Connection;

/** Per-course learner progress for educators (ADM-001). Read-only, tenant-scoped. */
final class ProgressReport
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return array{
     *   quizzes: list<array{assessment_id: int, title: string, pass_threshold: int}>,
     *   assignments: list<array{lesson_id: int, title: string}>,
     *   total: int,
     *   rows: list<array{name: string, email: string, status: string, enrolled_at: string, done: int,
     *                    last_activity: ?string, best: array<int, int>, submissions: array<int, string>}>
     * }
     */
    public function forCourse(int $tenantId, int $courseId): array
    {
        $quizzes = array_map(static fn (array $r): array => [
            'assessment_id' => (int) $r['id'],
            'title' => (string) $r['title'],
            'pass_threshold' => (int) $r['pass_threshold'],
        ], $this->db->fetchAll(
            'SELECT a.id, l.title, a.pass_threshold FROM assessments a
             JOIN lessons l ON l.tenant_id = a.tenant_id AND l.id = a.lesson_id
             JOIN course_modules m ON m.tenant_id = l.tenant_id AND m.id = l.module_id
             WHERE a.tenant_id = ? AND l.course_id = ? ORDER BY m.position, l.position',
            [$tenantId, $courseId],
        ));

        $assignments = array_map(static fn (array $r): array => [
            'lesson_id' => (int) $r['id'],
            'title' => (string) $r['title'],
        ], $this->db->fetchAll(
            "SELECT l.id, l.title FROM lessons l
             JOIN course_modules m ON m.tenant_id = l.tenant_id AND m.id = l.module_id
             WHERE l.tenant_id = ? AND l.course_id = ? AND l.type = 'assignment' ORDER BY m.position, l.position",
            [$tenantId, $courseId],
        ));
        $submissions = [];
        $latestRows = $assignments === [] ? [] : $this->db->fetchAll(
            'SELECT s.enrolment_id, s.lesson_id, s.status FROM assignment_submissions s
             JOIN (SELECT enrolment_id, lesson_id, MAX(id) AS id FROM assignment_submissions
                    WHERE tenant_id = ? AND enrolment_id IN (SELECT id FROM enrolments WHERE tenant_id = ? AND course_id = ?)
                    GROUP BY enrolment_id, lesson_id) latest ON latest.id = s.id
             WHERE s.tenant_id = ?',
            [$tenantId, $tenantId, $courseId, $tenantId],
        );
        foreach ($latestRows as $r) {
            $submissions[(int) $r['enrolment_id']][(int) $r['lesson_id']] = (string) $r['status'];
        }

        $total = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM lessons WHERE tenant_id = ? AND course_id = ? AND is_mandatory = 1',
            [$tenantId, $courseId],
        );

        $learners = $this->db->fetchAll(
            "SELECT e.id AS enrolment_id, e.status, e.enrolled_at, u.display_name, u.email,
                    (SELECT COUNT(*) FROM lesson_progress p
                       JOIN lessons l ON l.tenant_id = p.tenant_id AND l.id = p.lesson_id AND l.is_mandatory = 1
                      WHERE p.tenant_id = e.tenant_id AND p.enrolment_id = e.id AND p.status = 'completed') AS done,
                    (SELECT MAX(p2.updated_at) FROM lesson_progress p2
                      WHERE p2.tenant_id = e.tenant_id AND p2.enrolment_id = e.id) AS last_activity
             FROM enrolments e JOIN users u ON u.id = e.user_id
             WHERE e.tenant_id = ? AND e.course_id = ? AND e.status IN ('active', 'completed')
             ORDER BY u.display_name LIMIT 1000",
            [$tenantId, $courseId],
        );

        $bestRows = $this->db->fetchAll(
            'SELECT enrolment_id, assessment_id, MAX(score_percent) AS best FROM quiz_attempts
             WHERE tenant_id = ? AND enrolment_id IN (SELECT id FROM enrolments WHERE tenant_id = ? AND course_id = ?)
             GROUP BY enrolment_id, assessment_id',
            [$tenantId, $tenantId, $courseId],
        );
        $best = [];
        foreach ($bestRows as $r) {
            $best[(int) $r['enrolment_id']][(int) $r['assessment_id']] = (int) $r['best'];
        }

        $rows = array_map(static fn (array $r): array => [
            'name' => (string) $r['display_name'],
            'email' => (string) $r['email'],
            'status' => (string) $r['status'],
            'enrolled_at' => (string) $r['enrolled_at'],
            'done' => (int) $r['done'],
            'last_activity' => $r['last_activity'] === null ? null : (string) $r['last_activity'],
            'best' => $best[(int) $r['enrolment_id']] ?? [],
            'submissions' => $submissions[(int) $r['enrolment_id']] ?? [],
        ], $learners);

        return ['quizzes' => $quizzes, 'assignments' => $assignments, 'total' => $total, 'rows' => $rows];
    }
}
