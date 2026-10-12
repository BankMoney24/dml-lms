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

    public function addLesson(int $tenantId, int $courseId, int $moduleId, string $type, string $title, string $body): int
    {
        $now = $this->clock->sqlNow();
        $position = (int) $this->db->fetchValue(
            'SELECT COALESCE(MAX(position), 0) + 1 FROM lessons WHERE tenant_id = ? AND module_id = ?',
            [$tenantId, $moduleId],
        );

        return $this->db->insert(
            'INSERT INTO lessons (tenant_id, course_id, module_id, type, title, body, position, is_mandatory, is_preview, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?)',
            [$tenantId, $courseId, $moduleId, $type, $title, $body, $position, $now, $now],
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
}
