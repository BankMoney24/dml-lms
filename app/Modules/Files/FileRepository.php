<?php

declare(strict_types=1);

namespace App\Modules\Files;

use App\Core\Clock;
use App\Core\Database\Connection;

final class FileRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $tenantId, int $id): ?StoredFile
    {
        $row = $this->db->fetchOne('SELECT * FROM files WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);

        return $row === null ? null : StoredFile::fromRow($row);
    }

    /** @return list<StoredFile> */
    public function forLesson(int $tenantId, int $lessonId): array
    {
        return array_map(StoredFile::fromRow(...), $this->db->fetchAll(
            'SELECT * FROM files WHERE tenant_id = ? AND lesson_id = ? ORDER BY position, id',
            [$tenantId, $lessonId],
        ));
    }

    /**
     * @param list<int> $submissionIds
     * @return array<int, list<StoredFile>> submission id => files
     */
    public function forSubmissions(int $tenantId, array $submissionIds): array
    {
        if ($submissionIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($submissionIds), '?'));
        $rows = $this->db->fetchAll(
            "SELECT * FROM files WHERE tenant_id = ? AND submission_id IN ($placeholders) ORDER BY id",
            array_merge([$tenantId], $submissionIds),
        );
        $grouped = [];
        foreach ($rows as $row) {
            $file = StoredFile::fromRow($row);
            $grouped[(int) $file->submissionId][] = $file;
        }

        return $grouped;
    }

    /**
     * @param list<int> $messageIds
     * @return array<int, list<StoredFile>> message id => files
     */
    public function forSupportMessages(int $tenantId, array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }
        $out = [];
        foreach (
            $this->db->fetchAll(
                'SELECT * FROM files WHERE tenant_id = ? AND support_message_id IN (' . implode(',', array_fill(0, count($messageIds), '?')) . ') ORDER BY id',
                array_merge([$tenantId], $messageIds),
            ) as $row
        ) {
            $file = StoredFile::fromRow($row);
            $out[(int) $file->supportMessageId][] = $file;
        }

        return $out;
    }

    /** @return list<StoredFile> a class's materials and recordings, oldest first */
    public function forClassSession(int $tenantId, int $sessionId): array
    {
        return array_map(StoredFile::fromRow(...), $this->db->fetchAll(
            'SELECT * FROM files WHERE tenant_id = ? AND class_session_id = ? ORDER BY id',
            [$tenantId, $sessionId],
        ));
    }

    /**
     * How many materials each class has, for lists.
     *
     * @param list<int> $sessionIds
     * @return array<int, int>
     */
    public function countsForClassSessions(int $tenantId, array $sessionIds): array
    {
        if ($sessionIds === []) {
            return [];
        }
        $out = [];
        foreach (
            $this->db->fetchAll(
                'SELECT class_session_id, COUNT(*) AS n FROM files WHERE tenant_id = ? AND class_session_id IN ('
                . implode(',', array_fill(0, count($sessionIds), '?')) . ') GROUP BY class_session_id',
                array_merge([$tenantId], $sessionIds),
            ) as $row
        ) {
            $out[(int) $row['class_session_id']] = (int) $row['n'];
        }

        return $out;
    }

    public function countForSubmission(int $tenantId, int $submissionId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM files WHERE tenant_id = ? AND submission_id = ?', [$tenantId, $submissionId]);
    }

    public function tenantUsage(int $tenantId): int
    {
        return (int) $this->db->fetchValue('SELECT COALESCE(SUM(size_bytes), 0) FROM files WHERE tenant_id = ?', [$tenantId]);
    }

    /**
     * @param array{lesson_id: ?int, submission_id: ?int, support_message_id?: ?int, class_session_id?: ?int, bank_question_id?: ?int, uploaded_by: int, original_name: string, extension: string,
     *              mime_type: string, size_bytes: int, sha256: string, storage_key: string, scan_status: string} $f
     */
    public function insert(int $tenantId, array $f): int
    {
        $position = 0;
        if ($f['lesson_id'] !== null) {
            $position = (int) $this->db->fetchValue(
                'SELECT COALESCE(MAX(position), 0) + 1 FROM files WHERE tenant_id = ? AND lesson_id = ?',
                [$tenantId, $f['lesson_id']],
            );
        }

        return $this->db->insert(
            'INSERT INTO files (tenant_id, lesson_id, submission_id, support_message_id, class_session_id, bank_question_id, uploaded_by, original_name, extension, mime_type,
                                size_bytes, sha256, storage_key, scan_status, position, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [
                $tenantId, $f['lesson_id'], $f['submission_id'], $f['support_message_id'] ?? null, $f['class_session_id'] ?? null, $f['bank_question_id'] ?? null,
                $f['uploaded_by'], $f['original_name'], $f['extension'],
                $f['mime_type'], $f['size_bytes'], $f['sha256'], $f['storage_key'], $f['scan_status'], $position, $this->clock->sqlNow(),
            ],
        );
    }

    public function delete(int $tenantId, int $id): void
    {
        $this->db->execute('DELETE FROM files WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return list<string> storage keys of a lesson's files (before the lesson is deleted) */
    public function storageKeysForLesson(int $tenantId, int $lessonId): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['storage_key'],
            $this->db->fetchAll('SELECT storage_key FROM files WHERE tenant_id = ? AND lesson_id = ?', [$tenantId, $lessonId]),
        );
    }
}
