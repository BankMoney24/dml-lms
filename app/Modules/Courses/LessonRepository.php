<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Modules and lessons of a course. Every query is scoped by tenant and course. */
final class LessonRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    /**
     * Ordered outline: modules with their lessons.
     *
     * @return list<array{id: int, title: string, lessons: list<Lesson>}>
     */
    public function outline(int $tenantId, int $courseId): array
    {
        $modules = $this->db->fetchAll(
            'SELECT id, title FROM course_modules WHERE tenant_id = ? AND course_id = ? ORDER BY position, id',
            [$tenantId, $courseId],
        );
        $lessons = $this->db->fetchAll(
            'SELECT * FROM lessons WHERE tenant_id = ? AND course_id = ? ORDER BY position, id',
            [$tenantId, $courseId],
        );

        $byModule = [];
        foreach ($lessons as $row) {
            $byModule[(int) $row['module_id']][] = Lesson::fromRow($row);
        }

        return array_map(
            static fn (array $m): array => [
                'id' => (int) $m['id'],
                'title' => (string) $m['title'],
                'lessons' => $byModule[(int) $m['id']] ?? [],
            ],
            $modules,
        );
    }

    /** @return list<Lesson> lessons in delivery order */
    public function orderedLessons(int $tenantId, int $courseId): array
    {
        $lessons = [];
        foreach ($this->outline($tenantId, $courseId) as $module) {
            foreach ($module['lessons'] as $lesson) {
                $lessons[] = $lesson;
            }
        }

        return $lessons;
    }

    public function findInCourse(int $tenantId, int $courseId, int $lessonId): ?Lesson
    {
        $row = $this->db->fetchOne(
            'SELECT * FROM lessons WHERE tenant_id = ? AND course_id = ? AND id = ?',
            [$tenantId, $courseId, $lessonId],
        );

        return $row === null ? null : Lesson::fromRow($row);
    }

    public function moduleBelongsToCourse(int $tenantId, int $courseId, int $moduleId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM course_modules WHERE tenant_id = ? AND course_id = ? AND id = ?',
            [$tenantId, $courseId, $moduleId],
        ) !== null;
    }

    public function addModule(int $tenantId, int $courseId, string $title): int
    {
        $now = $this->clock->sqlNow();
        $position = (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM course_modules WHERE tenant_id = ? AND course_id = ?',
            [$tenantId, $courseId],
        );

        return $this->db->insert(
            'INSERT INTO course_modules (tenant_id, course_id, title, position, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$tenantId, $courseId, $title, $position, $now, $now],
        );
    }

    public function addLesson(int $tenantId, int $courseId, int $moduleId, string $type, string $title, string $body, ?string $mediaUrl = null, ?string $labKey = null): int
    {
        $now = $this->clock->sqlNow();
        $position = (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM lessons WHERE tenant_id = ? AND module_id = ?',
            [$tenantId, $moduleId],
        );

        return $this->db->insert(
            'INSERT INTO lessons (tenant_id, course_id, module_id, type, title, body, media_url, lab_key, position, is_mandatory, is_preview, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)',
            [$tenantId, $courseId, $moduleId, $type, $title, $body, $mediaUrl, $labKey, $position, $now, $now],
        );
    }

    /** @return list<int> */
    public function mandatoryLessonIds(int $tenantId, int $courseId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT id FROM lessons WHERE tenant_id = ? AND course_id = ? AND is_mandatory = 1',
            [$tenantId, $courseId],
        );

        return array_map(static fn (array $r): int => (int) $r['id'], $rows);
    }

    /** @return array{id: int, title: string}|null */
    public function findModule(int $tenantId, int $courseId, int $moduleId): ?array
    {
        $row = $this->db->fetchOne(
            'SELECT id, title FROM course_modules WHERE tenant_id = ? AND course_id = ? AND id = ?',
            [$tenantId, $courseId, $moduleId],
        );

        return $row === null ? null : ['id' => (int) $row['id'], 'title' => (string) $row['title']];
    }

    public function renameModule(int $tenantId, int $courseId, int $moduleId, string $title): void
    {
        $this->db->execute(
            'UPDATE course_modules SET title = ?, updated_at = ? WHERE tenant_id = ? AND course_id = ? AND id = ?',
            [$title, $this->clock->sqlNow(), $tenantId, $courseId, $moduleId],
        );
    }

    public function lessonCountInModule(int $tenantId, int $moduleId): int
    {
        return (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM lessons WHERE tenant_id = ? AND module_id = ?',
            [$tenantId, $moduleId],
        );
    }

    public function deleteModule(int $tenantId, int $courseId, int $moduleId): void
    {
        $this->db->execute(
            'DELETE FROM course_modules WHERE tenant_id = ? AND course_id = ? AND id = ?',
            [$tenantId, $courseId, $moduleId],
        );
    }

    public function updateLesson(int $tenantId, int $courseId, int $lessonId, string $title, string $body, ?string $mediaUrl = null, bool $preview = false): void
    {
        $this->db->execute(
            'UPDATE lessons SET title = ?, body = ?, media_url = ?, is_preview = ?, updated_at = ? WHERE tenant_id = ? AND course_id = ? AND id = ?',
            [$title, $body, $mediaUrl, $preview ? 1 : 0, $this->clock->sqlNow(), $tenantId, $courseId, $lessonId],
        );
    }

    /** Whether finishing the course needs this lesson (ADR 0032). */
    public function setMandatory(int $tenantId, int $courseId, int $lessonId, bool $mandatory): void
    {
        $this->db->execute(
            'UPDATE lessons SET is_mandatory = ?, updated_at = ? WHERE tenant_id = ? AND course_id = ? AND id = ?',
            [$mandatory ? 1 : 0, $this->clock->sqlNow(), $tenantId, $courseId, $lessonId],
        );
    }

    /** Points a video or audio lesson at its uploaded media file (null: none). */
    public function setMediaFile(int $tenantId, int $lessonId, ?int $fileId): void
    {
        $this->db->execute(
            'UPDATE lessons SET media_file_id = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$fileId, $this->clock->sqlNow(), $tenantId, $lessonId],
        );
    }

    /** Clears the pointer when that file is removed some other way. */
    public function forgetMediaFile(int $tenantId, int $fileId): void
    {
        $this->db->execute('UPDATE lessons SET media_file_id = NULL WHERE tenant_id = ? AND media_file_id = ?', [$tenantId, $fileId]);
    }

    public function findById(int $tenantId, int $lessonId): ?Lesson
    {
        $row = $this->db->fetchOne('SELECT * FROM lessons WHERE tenant_id = ? AND id = ?', [$tenantId, $lessonId]);

        return $row === null ? null : Lesson::fromRow($row);
    }

    /**
     * Swaps a lesson with its neighbour inside the same module.
     *
     * @return bool false when it is already first/last
     */
    public function move(int $tenantId, Lesson $lesson, string $direction): bool
    {
        $up = $direction === 'up';
        $neighbour = $this->db->fetchOne(
            'SELECT id, position FROM lessons WHERE tenant_id = ? AND module_id = ? AND position ' . ($up ? '<' : '>') . ' ?
             ORDER BY position ' . ($up ? 'DESC' : 'ASC') . ' LIMIT 1 FOR UPDATE',
            [$tenantId, $lesson->moduleId, $lesson->position],
        );
        if ($neighbour === null) {
            return false;
        }
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE lessons SET position = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [(int) $neighbour['position'], $now, $tenantId, $lesson->id],
        );
        $this->db->execute(
            'UPDATE lessons SET position = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$lesson->position, $now, $tenantId, (int) $neighbour['id']],
        );

        return true;
    }

    /** Whether any learner has opened or completed this lesson. */
    public function hasLearnerActivity(int $tenantId, int $lessonId): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM lesson_progress WHERE tenant_id = ? AND lesson_id = ? LIMIT 1',
            [$tenantId, $lessonId],
        ) !== null;
    }

    public function deleteLesson(int $tenantId, int $courseId, int $lessonId): void
    {
        $this->db->execute(
            'DELETE FROM lessons WHERE tenant_id = ? AND course_id = ? AND id = ?',
            [$tenantId, $courseId, $lessonId],
        );
    }
}
