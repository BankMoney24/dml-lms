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

    public function updateAccess(int $tenantId, int $courseId, string $access): void
    {
        $this->db->execute(
            'UPDATE courses SET access = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$access === Course::ACCESS_OPEN ? Course::ACCESS_OPEN : Course::ACCESS_MEMBERS, $this->clock->sqlNow(), $tenantId, $courseId],
        );
    }

    public function updateEnrolment(int $tenantId, int $courseId, string $mode, ?int $capacity): void
    {
        $this->db->execute(
            'UPDATE courses SET enrolment = ?, capacity = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$mode === Course::ENROL_APPLY ? Course::ENROL_APPLY : Course::ENROL_OPEN, $capacity, $this->clock->sqlNow(), $tenantId, $courseId],
        );
    }

    /** The course's completion rule beyond its required lessons (ADR 0032). */
    public function updateCompletion(int $tenantId, int $courseId, ?int $minAverage): void
    {
        $this->db->execute(
            'UPDATE courses SET completion_min_average = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$minAverage, $this->clock->sqlNow(), $tenantId, $courseId],
        );
    }

    /** Learners holding a place now (not withdrawn), counted against the capacity. */
    public function placesTaken(int $tenantId, int $courseId): int
    {
        return (int) $this->db->fetchValue("SELECT COUNT(*) FROM enrolments WHERE tenant_id = ? AND course_id = ? AND status <> 'withdrawn'", [$tenantId, $courseId]);
    }

    /** SQL for "free" (no price), "paid" and "open" (free and open to everyone), matching Course::isPaid() / isOpen(). */
    private const PRICE_SQL = [
        'free' => '(c.price_minor IS NULL OR c.price_minor <= 0 OR c.currency IS NULL)',
        'paid' => '(c.price_minor > 0 AND c.currency IS NOT NULL)',
        'open' => "(c.access = 'open' AND (c.price_minor IS NULL OR c.price_minor <= 0 OR c.currency IS NULL))",
    ];

    /**
     * The course list with filters (ADR 0021). $f: track (Course::TRACKS), show
     * (free, open or paid), level (Course::LEVELS), q (words in the title or
     * summary), openOnly (visitors when the school's list is for members only).
     *
     * @param array{track?: ?string, show?: ?string, level?: ?string, q?: ?string, openOnly?: bool} $f
     * @return list<Course>
     */
    public function browse(int $tenantId, array $f, int $limit = 24, int $offset = 0): array
    {
        [$where, $params] = $this->browseWhere($tenantId, $f);
        $params[] = max(1, min($limit, 100));
        $params[] = max(0, $offset);

        return array_map(Course::fromRow(...), $this->db->fetchAll(
            'SELECT c.* FROM courses c WHERE ' . $where . ' ORDER BY c.published_at DESC, c.id DESC LIMIT ? OFFSET ?',
            $params,
        ));
    }

    /**
     * How many published courses each filter would show, for the filter labels.
     *
     * @return array{free: int, open: int, paid: int, levels: array<string, int>, tracks: array<string, int>, all: int}
     */
    public function browseCounts(int $tenantId, bool $openOnly = false): array
    {
        [$where, $params] = $this->browseWhere($tenantId, ['openOnly' => $openOnly]);
        $row = $this->db->fetchOne(
            'SELECT COUNT(*) AS all_n, SUM(' . self::PRICE_SQL['free'] . ') AS free_n, SUM(' . self::PRICE_SQL['open'] . ') AS open_n,'
            . ' SUM(' . self::PRICE_SQL['paid'] . ') AS paid_n,'
            . " SUM(c.level = 'beginner') AS l_beginner, SUM(c.level = 'intermediate') AS l_intermediate, SUM(c.level = 'advanced') AS l_advanced,"
            . " SUM(c.track = 'school') AS t_school, SUM(c.track = 'skills') AS t_skills"
            . ' FROM courses c WHERE ' . $where,
            $params,
        ) ?? [];
        $levels = [];
        foreach (array_keys(Course::LEVELS) as $level) {
            $levels[$level] = (int) ($row['l_' . $level] ?? 0);
        }
        $tracks = [];
        foreach (array_keys(Course::TRACKS) as $track) {
            $tracks[$track] = (int) ($row['t_' . $track] ?? 0);
        }

        return [
            'all' => (int) ($row['all_n'] ?? 0),
            'free' => (int) ($row['free_n'] ?? 0),
            'open' => (int) ($row['open_n'] ?? 0),
            'paid' => (int) ($row['paid_n'] ?? 0),
            'levels' => $levels,
            'tracks' => $tracks,
        ];
    }

    /**
     * Real figures for the public home page: published courses, their lessons
     * and labs (ADR 0024). With $openOnly, only open courses count.
     *
     * @return array{courses: int, lessons: int, labs: int, learners: int}
     */
    public function publicFigures(int $tenantId, bool $openOnly = false): array
    {
        $where = "c.tenant_id = ? AND c.status = 'published'" . ($openOnly ? ' AND ' . self::PRICE_SQL['open'] : '');
        $row = $this->db->fetchOne(
            'SELECT COUNT(DISTINCT c.id) AS courses, COUNT(l.id) AS lessons, COALESCE(SUM(l.type = \'lab\'), 0) AS labs
             FROM courses c LEFT JOIN lessons l ON l.tenant_id = c.tenant_id AND l.course_id = c.id WHERE ' . $where,
            [$tenantId],
        ) ?? [];

        return [
            'courses' => (int) ($row['courses'] ?? 0),
            'lessons' => (int) ($row['lessons'] ?? 0),
            'labs' => (int) ($row['labs'] ?? 0),
            'learners' => (int) $this->db->fetchValue("SELECT COUNT(*) FROM memberships WHERE tenant_id = ? AND status = 'active' AND role = 'learner'", [$tenantId]),
        ];
    }

    /**
     * Published courses with the most learners, for "Popular" on the home page.
     *
     * @return list<Course>
     */
    public function popular(int $tenantId, int $limit = 6, bool $openOnly = false): array
    {
        return array_map(Course::fromRow(...), $this->db->fetchAll(
            "SELECT c.* FROM courses c
             WHERE c.tenant_id = ? AND c.status = 'published'" . ($openOnly ? ' AND ' . self::PRICE_SQL['open'] : '') . "
             ORDER BY (SELECT COUNT(*) FROM enrolments e WHERE e.tenant_id = c.tenant_id AND e.course_id = c.id AND e.status <> 'withdrawn') DESC,
                      c.published_at DESC, c.id DESC LIMIT " . max(1, min($limit, 24)),
            [$tenantId],
        ));
    }

    /** Whether the school has at least one published open course (they are visible even when its course list is for members only). */
    public function hasOpen(int $tenantId): bool
    {
        return $this->db->fetchValue(
            "SELECT 1 FROM courses c WHERE c.tenant_id = ? AND c.status = 'published' AND " . self::PRICE_SQL['open'] . ' LIMIT 1',
            [$tenantId],
        ) !== null;
    }

    /**
     * @param array{track?: ?string, show?: ?string, level?: ?string, q?: ?string, openOnly?: bool} $f
     * @return array{0: string, 1: list<int|string>}
     */
    private function browseWhere(int $tenantId, array $f): array
    {
        $where = ['c.tenant_id = ?', 'c.status = ?'];
        $params = [$tenantId, Course::STATUS_PUBLISHED];
        if (($f['openOnly'] ?? false) === true) {
            $where[] = self::PRICE_SQL['open'];
        }
        $show = $f['show'] ?? null;
        if ($show !== null && isset(self::PRICE_SQL[$show])) {
            $where[] = self::PRICE_SQL[$show];
        }
        $track = $f['track'] ?? null;
        if ($track !== null && isset(Course::TRACKS[$track])) {
            $where[] = 'c.track = ?';
            $params[] = $track;
        }
        $level = $f['level'] ?? null;
        if ($level !== null && isset(Course::LEVELS[$level])) {
            $where[] = 'c.level = ?';
            $params[] = $level;
        }
        $words = array_slice(array_values(array_filter(preg_split('/\s+/u', mb_strtolower(trim((string) ($f['q'] ?? '')))) ?: [], static fn (string $w): bool => $w !== '')), 0, 6);
        foreach ($words as $word) {
            $like = '%' . addcslashes(mb_substr($word, 0, 40), '%_\\') . '%';
            $where[] = '(LOWER(c.title) LIKE ? OR LOWER(c.summary) LIKE ?)';
            $params[] = $like;
            $params[] = $like;
        }

        return [implode(' AND ', $where), $params];
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
