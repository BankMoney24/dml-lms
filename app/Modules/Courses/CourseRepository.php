<?php

declare(strict_types=1);

namespace App\Modules\Courses;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Every method takes the tenant id explicitly and filters on it. */
final class CourseRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function findById(int $tenantId, int $courseId): ?Course
    {
        $row = $this->db->fetchOne('SELECT * FROM courses WHERE tenant_id = ? AND id = ?', [$tenantId, $courseId]);

        return $row === null ? null : Course::fromRow($row);
    }

    public function findBySlug(int $tenantId, string $slug): ?Course
    {
        $row = $this->db->fetchOne('SELECT * FROM courses WHERE tenant_id = ? AND slug = ?', [$tenantId, $slug]);

        return $row === null ? null : Course::fromRow($row);
    }

    /** @return list<Course> */
    public function listPublished(int $tenantId, int $limit = 24, int $offset = 0): array
    {
        $rows = $this->db->fetchAll(
            'SELECT * FROM courses WHERE tenant_id = ? AND status = ? ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
            [$tenantId, Course::STATUS_PUBLISHED, max(1, min($limit, 100)), max(0, $offset)],
        );

        return array_map(Course::fromRow(...), $rows);
    }

    /** @return list<array{course: Course, lesson_count: int}> */
    public function listAllWithCounts(int $tenantId): array
    {
        $rows = $this->db->fetchAll(
            'SELECT c.*, (SELECT COUNT(*) FROM lessons l WHERE l.tenant_id = c.tenant_id AND l.course_id = c.id) AS lesson_count
             FROM courses c WHERE c.tenant_id = ? ORDER BY c.updated_at DESC, c.id DESC LIMIT 200',
            [$tenantId],
        );

        return array_map(
            static fn (array $row): array => ['course' => Course::fromRow($row), 'lesson_count' => (int) $row['lesson_count']],
            $rows,
        );
    }

    public function slugExists(int $tenantId, string $slug): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM courses WHERE tenant_id = ? AND slug = ?', [$tenantId, $slug]) !== null;
    }

    public function create(int $tenantId, int $creatorId, string $slug, string $title, string $summary): Course
    {
        $now = $this->clock->sqlNow();
        $id = $this->db->insert(
            'INSERT INTO courses (tenant_id, slug, title, summary, status, created_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $slug, $title, $summary, Course::STATUS_DRAFT, $creatorId, $now, $now],
        );

        return new Course($id, $tenantId, $slug, $title, $summary, Course::STATUS_DRAFT);
    }

    public function markPublished(int $tenantId, int $courseId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE courses SET status = ?, published_at = COALESCE(published_at, ?), updated_at = ? WHERE tenant_id = ? AND id = ?',
            [Course::STATUS_PUBLISHED, $now, $now, $tenantId, $courseId],
        );
    }

    public function touch(int $tenantId, int $courseId): void
    {
        $this->db->execute(
            'UPDATE courses SET updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$this->clock->sqlNow(), $tenantId, $courseId],
        );
    }
}
