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
    public function listPublished(int $tenantId, int $limit = 24, int $offset = 0, ?string $track = null): array
    {
        $rows = $track === null
            ? $this->db->fetchAll(
                'SELECT * FROM courses WHERE tenant_id = ? AND status = ? ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
                [$tenantId, Course::STATUS_PUBLISHED, max(1, min($limit, 100)), max(0, $offset)],
            )
            : $this->db->fetchAll(
                'SELECT * FROM courses WHERE tenant_id = ? AND status = ? AND track = ? ORDER BY published_at DESC, id DESC LIMIT ? OFFSET ?',
                [$tenantId, Course::STATUS_PUBLISHED, $track, max(1, min($limit, 100)), max(0, $offset)],
            );

        return array_map(Course::fromRow(...), $rows);
    }

    /** @return array<string, int> published courses per track ("school", "skills"), for the catalogue filter */
    public function publishedTrackCounts(int $tenantId): array
    {
        $counts = [];
        foreach (
            $this->db->fetchAll(
                'SELECT track, COUNT(*) AS n FROM courses WHERE tenant_id = ? AND status = ? AND track IS NOT NULL GROUP BY track',
                [$tenantId, Course::STATUS_PUBLISHED],
            ) as $r
        ) {
            $counts[(string) $r['track']] = (int) $r['n'];
        }

        return $counts;
    }

    /**
     * Courses by id, in the order given; unknown ids are skipped.
     *
     * @param list<int> $ids
     * @return list<Course>
     */
    public function findMany(int $tenantId, array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $rows = $this->db->fetchAll(
            'SELECT * FROM courses WHERE tenant_id = ? AND id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
            array_merge([$tenantId], $ids),
        );
        $byId = [];
        foreach ($rows as $row) {
            $byId[(int) $row['id']] = Course::fromRow($row);
        }

        return array_values(array_filter(array_map(static fn (int $id): ?Course => $byId[$id] ?? null, $ids)));
    }

    /** @return list<Course> courses with a price, for recording payments by hand */
    public function listPaid(int $tenantId): array
    {
        return array_map(
            static fn (array $row): Course => Course::fromRow($row),
            $this->db->fetchAll('SELECT * FROM courses WHERE tenant_id = ? AND price_minor IS NOT NULL ORDER BY title LIMIT 500', [$tenantId]),
        );
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

    public function updateDetails(int $tenantId, int $courseId, string $title, string $summary): void
    {
        $this->db->execute(
            'UPDATE courses SET title = ?, summary = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$title, $summary, $this->clock->sqlNow(), $tenantId, $courseId],
        );
    }

    /** @param list<string> $outcomes */
    public function updateProfile(int $tenantId, int $courseId, ?string $level, ?int $hours, array $outcomes, string $prerequisites, ?string $track = null): void
    {
        $this->db->execute(
            'UPDATE courses SET level = ?, duration_hours = ?, outcomes = ?, prerequisites = ?, track = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$level, $hours, $outcomes === [] ? null : implode("\n", $outcomes), $prerequisites === '' ? null : $prerequisites, $track,
             $this->clock->sqlNow(), $tenantId, $courseId],
        );
    }

    public function authorId(int $tenantId, int $courseId): ?int
    {
        $id = $this->db->fetchValue('SELECT created_by FROM courses WHERE tenant_id = ? AND id = ?', [$tenantId, $courseId]);

        return $id === null ? null : (int) $id;
    }

    public function updatePrice(int $tenantId, int $courseId, ?int $priceMinor, ?string $currency): void
    {
        $this->db->execute(
            'UPDATE courses SET price_minor = ?, currency = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$priceMinor, $currency, $this->clock->sqlNow(), $tenantId, $courseId],
        );
    }

    public function markDraft(int $tenantId, int $courseId): void
    {
        $this->db->execute(
            'UPDATE courses SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [Course::STATUS_DRAFT, $this->clock->sqlNow(), $tenantId, $courseId],
        );
    }

    /** @return list<string> every course title in the school, drafts included */
    public function titles(int $tenantId): array
    {
        return array_map(
            static fn (array $r): string => (string) $r['title'],
            $this->db->fetchAll('SELECT title FROM courses WHERE tenant_id = ?', [$tenantId]),
        );
    }
}
