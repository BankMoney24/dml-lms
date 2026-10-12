<?php

declare(strict_types=1);

namespace App\Modules\Assignments;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Assignment submissions. Every query is scoped by tenant. */
final class SubmissionRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $tenantId, int $id, bool $forUpdate = false): ?Submission
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM assignment_submissions WHERE tenant_id = ? AND id = ?' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$tenantId, $id],
        );

        return $row === null ? null : Submission::fromRow($row);
    }

    public function latest(int $tenantId, int $enrolmentId, int $lessonId, bool $forUpdate = false): ?Submission
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM assignment_submissions WHERE tenant_id = ? AND enrolment_id = ? AND lesson_id = ?
             ORDER BY id DESC LIMIT 1' . ($forUpdate ? ' FOR UPDATE' : ''),
            [$tenantId, $enrolmentId, $lessonId],
        );

        return $row === null ? null : Submission::fromRow($row);
    }

    /** @return list<Submission> newest first */
    public function history(int $tenantId, int $enrolmentId, int $lessonId): array
    {
        return array_map(Submission::fromRow(...), $this->db->fetchAll(
            'SELECT * FROM assignment_submissions WHERE tenant_id = ? AND enrolment_id = ? AND lesson_id = ? ORDER BY id DESC LIMIT 50',
            [$tenantId, $enrolmentId, $lessonId],
        ));
    }

    public function insert(int $tenantId, int $lessonId, int $enrolmentId, int $userId, string $body, ?string $link): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO assignment_submissions (tenant_id, lesson_id, enrolment_id, user_id, body, link_url, status, submitted_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $lessonId, $enrolmentId, $userId, $body, $link, Submission::SUBMITTED, $now, $now],
        );
    }

    /** Replaces the text of a submission nobody has reviewed yet. */
    public function replacePending(int $tenantId, int $id, string $body, ?string $link): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE assignment_submissions SET body = ?, link_url = ?, submitted_at = ?, updated_at = ?
             WHERE tenant_id = ? AND id = ? AND status = ?',
            [$body, $link, $now, $now, $tenantId, $id, Submission::SUBMITTED],
        );
    }

    public function review(int $tenantId, int $id, string $status, string $feedback, int $reviewerId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE assignment_submissions SET status = ?, feedback = ?, reviewed_by = ?, reviewed_at = ?, updated_at = ?
             WHERE tenant_id = ? AND id = ?',
            [$status, $feedback === '' ? null : $feedback, $reviewerId, $now, $now, $tenantId, $id],
        );
    }

    /**
     * Submissions waiting for a teacher, oldest first.
     *
     * @return list<array{id: int, submitted_at: string, learner: string, course_id: int, course: string, lesson: string, resubmission: bool}>
     */
    public function queue(int $tenantId, int $limit = 100): array
    {
        $rows = $this->db->fetchAll(
            "SELECT s.id, s.submitted_at, u.display_name, c.id AS course_id, c.title AS course_title, l.title AS lesson_title,
                    (SELECT COUNT(*) FROM assignment_submissions p
                      WHERE p.tenant_id = s.tenant_id AND p.enrolment_id = s.enrolment_id AND p.lesson_id = s.lesson_id AND p.id < s.id) AS earlier
             FROM assignment_submissions s
             JOIN users u ON u.id = s.user_id
             JOIN lessons l ON l.tenant_id = s.tenant_id AND l.id = s.lesson_id
             JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id
             WHERE s.tenant_id = ? AND s.status = 'submitted'
             ORDER BY s.submitted_at, s.id LIMIT " . max(1, min($limit, 500)),
            [$tenantId],
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'submitted_at' => (string) $r['submitted_at'],
            'learner' => (string) $r['display_name'],
            'course_id' => (int) $r['course_id'],
            'course' => (string) $r['course_title'],
            'lesson' => (string) $r['lesson_title'],
            'resubmission' => (int) $r['earlier'] > 0,
        ], $rows);
    }

    public function pendingCount(int $tenantId): int
    {
        return (int) $this->db->fetchValue(
            "SELECT COUNT(*) FROM assignment_submissions WHERE tenant_id = ? AND status = 'submitted'",
            [$tenantId],
        );
    }
}
