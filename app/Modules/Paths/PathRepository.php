<?php

declare(strict_types=1);

namespace App\Modules\Paths;

use App\Core\Clock;
use App\Core\Database\Connection;

/** Learning paths, their ordered courses and their followers. Scoped by school. */
final class PathRepository
{
    private const SELECT = 'SELECT p.*, (SELECT COUNT(*) FROM learning_path_courses pc WHERE pc.tenant_id = p.tenant_id AND pc.path_id = p.id) AS course_count
        FROM learning_paths p';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function create(int $tenantId, int $userId, string $slug, string $title, string $summary): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO learning_paths (tenant_id, slug, title, summary, status, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $slug, $title, $summary, LearningPath::DRAFT, $userId, $now, $now],
        );
    }

    public function find(int $tenantId, int $id): ?LearningPath
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE p.tenant_id = ? AND p.id = ?', [$tenantId, $id]);

        return $row === null ? null : LearningPath::fromRow($row);
    }

    public function findBySlug(int $tenantId, string $slug): ?LearningPath
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE p.tenant_id = ? AND p.slug = ?', [$tenantId, $slug]);

        return $row === null ? null : LearningPath::fromRow($row);
    }

    public function slugExists(int $tenantId, string $slug): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM learning_paths WHERE tenant_id = ? AND slug = ?', [$tenantId, $slug]) !== null;
    }

    /** @return list<LearningPath> */
    public function all(int $tenantId): array
    {
        return array_map(LearningPath::fromRow(...), $this->db->fetchAll(self::SELECT . ' WHERE p.tenant_id = ? ORDER BY p.updated_at DESC LIMIT 200', [$tenantId]));
    }

    /** @return list<LearningPath> */
    public function published(int $tenantId): array
    {
        return array_map(LearningPath::fromRow(...), $this->db->fetchAll(
            self::SELECT . " WHERE p.tenant_id = ? AND p.status = 'published' ORDER BY p.title LIMIT 100",
            [$tenantId],
        ));
    }

    public function update(int $tenantId, int $id, string $title, string $summary, ?string $level): void
    {
        $this->db->execute(
            'UPDATE learning_paths SET title = ?, summary = ?, level = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$title, $summary, $level, $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function setStatus(int $tenantId, int $id, string $status): void
    {
        $this->db->execute('UPDATE learning_paths SET status = ?, updated_at = ? WHERE tenant_id = ? AND id = ?', [$status, $this->clock->sqlNow(), $tenantId, $id]);
    }

    public function delete(int $tenantId, int $id): void
    {
        $this->db->execute('DELETE FROM learning_paths WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /** @return list<int> course ids in order */
    public function courseIds(int $tenantId, int $pathId): array
    {
        return array_map('intval', array_column($this->db->fetchAll(
            'SELECT course_id FROM learning_path_courses WHERE tenant_id = ? AND path_id = ? ORDER BY position, course_id',
            [$tenantId, $pathId],
        ), 'course_id'));
    }

    /** @param list<int> $courseIds the whole new order */
    public function setCourses(int $tenantId, int $pathId, array $courseIds): void
    {
        $this->db->transaction(function () use ($tenantId, $pathId, $courseIds): void {
            $this->db->execute('DELETE FROM learning_path_courses WHERE tenant_id = ? AND path_id = ?', [$tenantId, $pathId]);
            foreach (array_values(array_unique($courseIds)) as $i => $courseId) {
                $this->db->execute(
                    'INSERT INTO learning_path_courses (tenant_id, path_id, course_id, position) VALUES (?, ?, ?, ?)',
                    [$tenantId, $pathId, $courseId, $i + 1],
                );
            }
            $this->db->execute('UPDATE learning_paths SET updated_at = ? WHERE tenant_id = ? AND id = ?', [$this->clock->sqlNow(), $tenantId, $pathId]);
        });
    }

    public function follow(int $tenantId, int $pathId, int $userId): void
    {
        if (!$this->follows($tenantId, $pathId, $userId)) {
            $this->db->execute(
                'INSERT INTO learning_path_followers (tenant_id, path_id, user_id, started_at) VALUES (?, ?, ?, ?)',
                [$tenantId, $pathId, $userId, $this->clock->sqlNow()],
            );
        }
    }

    public function unfollow(int $tenantId, int $pathId, int $userId): void
    {
        $this->db->execute('DELETE FROM learning_path_followers WHERE tenant_id = ? AND path_id = ? AND user_id = ?', [$tenantId, $pathId, $userId]);
    }

    public function follows(int $tenantId, int $pathId, int $userId): bool
    {
        return $this->db->fetchValue('SELECT 1 FROM learning_path_followers WHERE tenant_id = ? AND path_id = ? AND user_id = ?', [$tenantId, $pathId, $userId]) !== null;
    }

    /** @return list<LearningPath> published paths this person follows */
    public function followedBy(int $tenantId, int $userId): array
    {
        return array_map(LearningPath::fromRow(...), $this->db->fetchAll(
            self::SELECT . " JOIN learning_path_followers f ON f.tenant_id = p.tenant_id AND f.path_id = p.id AND f.user_id = ?
             WHERE p.tenant_id = ? AND p.status = 'published' ORDER BY f.started_at DESC LIMIT 20",
            [$userId, $tenantId],
        ));
    }

    public function followerCount(int $tenantId, int $pathId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM learning_path_followers WHERE tenant_id = ? AND path_id = ?', [$tenantId, $pathId]);
    }
}
