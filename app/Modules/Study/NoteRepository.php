<?php

declare(strict_types=1);

namespace App\Modules\Study;

use App\Core\Clock;
use App\Core\Database\Connection;

/** A learner's own bookmark and note on a lesson. Only ever read for the person who wrote it. */
final class NoteRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /** @return ?array{saved: bool, body: string, updated_at: string} */
    public function find(int $tenantId, int $userId, int $lessonId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT is_saved, body, updated_at FROM lesson_notes WHERE tenant_id = ? AND user_id = ? AND lesson_id = ?',
            [$tenantId, $userId, $lessonId],
        );

        return $row === null ? null : ['saved' => (bool) $row['is_saved'], 'body' => (string) ($row['body'] ?? ''), 'updated_at' => (string) $row['updated_at']];
    }

    /** Keeps a row only while there is something in it: a bookmark or some text. */
    public function save(int $tenantId, int $userId, int $lessonId, bool $saved, string $body): void
    {
        if (!$saved && $body === '') {
            $this->db->execute('DELETE FROM lesson_notes WHERE tenant_id = ? AND user_id = ? AND lesson_id = ?', [$tenantId, $userId, $lessonId]);

            return;
        }
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'INSERT INTO lesson_notes (tenant_id, user_id, lesson_id, is_saved, body, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE is_saved = VALUES(is_saved), body = VALUES(body), updated_at = VALUES(updated_at)',
            [$tenantId, $userId, $lessonId, $saved ? 1 : 0, $body === '' ? null : $body, $now, $now],
        );
    }

    /**
     * Everything this person saved or wrote, newest first, in courses that are still published.
     *
     * @return list<array{lesson_id: int, lesson_title: string, lesson_type: string, course_id: int, course_slug: string, course_title: string, saved: bool, body: string, updated_at: string}>
     */
    public function forUser(int $tenantId, int $userId, int $limit = 300): array
    {
        $rows = $this->db->fetchAll(
            "SELECT n.lesson_id, l.title AS lesson_title, l.type AS lesson_type, c.id AS course_id, c.slug AS course_slug, c.title AS course_title,
                    n.is_saved, n.body, n.updated_at
             FROM lesson_notes n
             JOIN lessons l ON l.tenant_id = n.tenant_id AND l.id = n.lesson_id
             JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id AND c.status = 'published'
             WHERE n.tenant_id = ? AND n.user_id = ?
             ORDER BY n.updated_at DESC, n.id DESC LIMIT " . max(1, $limit),
            [$tenantId, $userId],
        );

        return array_map(static fn (array $r): array => [
            'lesson_id' => (int) $r['lesson_id'],
            'lesson_title' => (string) $r['lesson_title'],
            'lesson_type' => (string) $r['lesson_type'],
            'course_id' => (int) $r['course_id'],
            'course_slug' => (string) $r['course_slug'],
            'course_title' => (string) $r['course_title'],
            'saved' => (bool) $r['is_saved'],
            'body' => (string) ($r['body'] ?? ''),
            'updated_at' => (string) $r['updated_at'],
        ], $rows);
    }

    /** @return list<int> saved lesson ids in one course, for marking the outline */
    public function savedInCourse(int $tenantId, int $userId, int $courseId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            'SELECT n.lesson_id FROM lesson_notes n JOIN lessons l ON l.tenant_id = n.tenant_id AND l.id = n.lesson_id
             WHERE n.tenant_id = ? AND n.user_id = ? AND l.course_id = ? AND n.is_saved = 1',
            [$tenantId, $userId, $courseId],
        ), 'lesson_id'));
    }
}
