<?php

declare(strict_types=1);

namespace App\Modules\Guardians;

use App\Core\Database\Connection;

/**
 * What a guardian sees about one learner. Each part is fetched only when the
 * link allows it, so a guardian without "results" never has scores loaded.
 * Read-only and tenant-scoped.
 */
final class ChildReport
{
    public function __construct(private readonly Connection $db)
    {
    }

    /**
     * @return list<array{title: string, slug: string, status: string, done: int, total: int, last_at: ?string, due_on: ?string}>
     */
    public function courses(int $tenantId, int $learnerId): array
    {
        return array_map(static fn (array $r): array => [
            'title' => (string) $r['title'],
            'slug' => (string) $r['slug'],
            'status' => (string) $r['status'],
            'done' => (int) $r['done'],
            'total' => (int) $r['total'],
            'last_at' => $r['last_at'] === null ? null : (string) $r['last_at'],
            'due_on' => $r['due_on'] === null ? null : (string) $r['due_on'],
        ], $this->db->fetchAll(
            "SELECT c.title, c.slug, e.status,
                    (SELECT COUNT(*) FROM lesson_progress p WHERE p.tenant_id = e.tenant_id AND p.enrolment_id = e.id AND p.status = 'completed') AS done,
                    (SELECT COUNT(*) FROM lessons l WHERE l.tenant_id = c.tenant_id AND l.course_id = c.id) AS total,
                    (SELECT MAX(p.updated_at) FROM lesson_progress p WHERE p.tenant_id = e.tenant_id AND p.enrolment_id = e.id) AS last_at,
                    (SELECT MIN(gc.due_on) FROM learner_group_courses gc
                       JOIN learner_group_members gm ON gm.tenant_id = gc.tenant_id AND gm.group_id = gc.group_id AND gm.role = 'learner' AND gm.user_id = e.user_id
                       JOIN learner_groups g ON g.tenant_id = gc.tenant_id AND g.id = gc.group_id AND g.archived_at IS NULL
                      WHERE gc.tenant_id = e.tenant_id AND gc.course_id = e.course_id) AS due_on
             FROM enrolments e JOIN courses c ON c.tenant_id = e.tenant_id AND c.id = e.course_id
             WHERE e.tenant_id = ? AND e.user_id = ? AND c.status = 'published'
             ORDER BY e.status = 'completed', last_at IS NULL, last_at DESC, c.title LIMIT 100",
            [$tenantId, $learnerId],
        ));
    }

    /**
     * @return array{
     *   quizzes: list<array{course: string, lesson: string, best: int, passed: bool, attempts: int}>,
     *   assignments: list<array{course: string, lesson: string, status: string, at: string}>
     * }
     */
    public function results(int $tenantId, int $learnerId): array
    {
        $quizzes = array_map(static fn (array $r): array => [
            'course' => (string) $r['course'],
            'lesson' => (string) $r['lesson'],
            'best' => (int) $r['best'],
            'passed' => (bool) $r['passed'],
            'attempts' => (int) $r['attempts'],
        ], $this->db->fetchAll(
            'SELECT c.title AS course, l.title AS lesson, MAX(q.score_percent) AS best, MAX(q.passed) AS passed, COUNT(*) AS attempts
             FROM quiz_attempts q
             JOIN assessments a ON a.tenant_id = q.tenant_id AND a.id = q.assessment_id
             JOIN lessons l ON l.tenant_id = a.tenant_id AND l.id = a.lesson_id
             JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id
             WHERE q.tenant_id = ? AND q.user_id = ?
             GROUP BY c.title, l.title, l.id ORDER BY MAX(q.submitted_at) DESC LIMIT 50',
            [$tenantId, $learnerId],
        ));
        $assignments = array_map(static fn (array $r): array => [
            'course' => (string) $r['course'],
            'lesson' => (string) $r['lesson'],
            'status' => (string) $r['status'],
            'at' => (string) $r['submitted_at'],
        ], $this->db->fetchAll(
            'SELECT c.title AS course, l.title AS lesson, s.status, s.submitted_at
             FROM assignment_submissions s
             JOIN (SELECT lesson_id, MAX(id) AS id FROM assignment_submissions WHERE tenant_id = ? AND user_id = ? GROUP BY lesson_id) latest ON latest.id = s.id
             JOIN lessons l ON l.tenant_id = s.tenant_id AND l.id = s.lesson_id
             JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id
             WHERE s.tenant_id = ? ORDER BY s.submitted_at DESC LIMIT 50',
            [$tenantId, $learnerId, $tenantId],
        ));

        return ['quizzes' => $quizzes, 'assignments' => $assignments];
    }
}
