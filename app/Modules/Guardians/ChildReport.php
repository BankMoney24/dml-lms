<?php

declare(strict_types=1);

namespace App\Modules\Guardians;

use App\Core\Clock;
use App\Core\Database\Connection;

/**
 * What a guardian sees about one learner. Each part is fetched only when the
 * link allows it, so a guardian without "results" never has scores loaded.
 * Read-only and tenant-scoped.
 */
final class ChildReport
{
    public function __construct(private readonly Connection $db, private readonly ?Clock $clock = null)
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
             WHERE e.tenant_id = ? AND e.user_id = ? AND e.status <> 'withdrawn' AND c.status = 'published'
             ORDER BY e.status = 'completed', last_at IS NULL, last_at DESC, c.title LIMIT 100",
            [$tenantId, $learnerId],
        ));
    }

    /**
     * @return array{
     *   quizzes: list<array{course: string, lesson: string, best: int, passed: bool, attempts: int}>,
     *   assignments: list<array{course: string, lesson: string, status: string, at: string}>,
     *   exams: list<array{title: string, at: string, total: int, max: int, percent: int, sections: list<array{label: string, scaled: int}>}>
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

        return ['quizzes' => $quizzes, 'assignments' => $assignments, 'exams' => $this->exams($tenantId, $learnerId)];
    }

    /**
     * CBT exam results the learner can see themselves (ADR 0044): never held-back or hidden
     * results, never practice tests or a teacher trying a paper. The latest attempt at each exam.
     *
     * @return list<array{title: string, at: string, total: int, max: int, percent: int, sections: list<array{label: string, scaled: int}>}>
     */
    public function exams(int $tenantId, int $learnerId): array
    {
        $now = ($this->clock?->now() ?? new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
        $rows = $this->db->fetchAll(
            "SELECT e.title, a.submitted_at, a.result, a.percent
             FROM cbt_attempts a
             JOIN (SELECT exam_id, MAX(id) AS id FROM cbt_attempts WHERE tenant_id = ? AND user_id = ? AND exam_id IS NOT NULL AND submitted_at IS NOT NULL GROUP BY exam_id) latest
               ON latest.id = a.id
             JOIN cbt_exams e ON e.tenant_id = a.tenant_id AND e.id = a.exam_id
             WHERE a.tenant_id = ?
               AND (e.results = 'immediately' OR (e.results = 'after_close' AND (e.status = 'closed' OR (e.closes_at IS NOT NULL AND e.closes_at <= ?))))
             ORDER BY a.submitted_at DESC LIMIT 30",
            [$tenantId, $learnerId, $tenantId, $now],
        );
        $out = [];
        foreach ($rows as $r) {
            $result = json_decode((string) $r['result'], true);
            $sections = [];
            foreach (is_array($result['sections'] ?? null) ? $result['sections'] : [] as $s) {
                $sections[] = ['label' => (string) ($s['label'] ?? ''), 'scaled' => (int) ($s['scaled'] ?? 0)];
            }
            $out[] = [
                'title' => (string) $r['title'], 'at' => (string) $r['submitted_at'],
                'total' => (int) ($result['total'] ?? 0), 'max' => (int) ($result['max'] ?? 0), 'percent' => (int) $r['percent'], 'sections' => $sections,
            ];
        }

        return $out;
    }
}
