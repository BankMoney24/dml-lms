<?php

declare(strict_types=1);

namespace App\Modules\Blog;

use App\Core\Clock;
use App\Core\Database\Connection;

final class BlogRepository
{
    private const SELECT = 'SELECT p.*, u.display_name AS author_name FROM blog_posts p JOIN users u ON u.id = p.author_id';

    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $tenantId, int $id): ?BlogPost
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE p.tenant_id = ? AND p.id = ?', [$tenantId, $id]);

        return $row === null ? null : BlogPost::fromRow($row);
    }

    public function findBySlug(int $tenantId, string $slug): ?BlogPost
    {
        $row = $this->db->fetchOne(self::SELECT . ' WHERE p.tenant_id = ? AND p.slug = ?', [$tenantId, $slug]);

        return $row === null ? null : BlogPost::fromRow($row);
    }

    public function slugExists(int $tenantId, string $slug, ?int $except = null): bool
    {
        return $this->db->fetchValue(
            'SELECT 1 FROM blog_posts WHERE tenant_id = ? AND slug = ?' . ($except !== null ? ' AND id <> ?' : ''),
            $except !== null ? [$tenantId, $slug, $except] : [$tenantId, $slug],
        ) !== null;
    }

    /** @param array{slug: string, title: string, summary: string, body: string, category: string} $p */
    public function create(int $tenantId, array $p, int $authorId): int
    {
        $now = $this->clock->sqlNow();

        return $this->db->insert(
            'INSERT INTO blog_posts (tenant_id, slug, title, summary, body, category, status, author_id, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$tenantId, $p['slug'], $p['title'], $p['summary'], $p['body'], $p['category'], BlogPost::DRAFT, $authorId, $now, $now],
        );
    }

    /** @param array{slug: string, title: string, summary: string, body: string, category: string} $p */
    public function update(int $tenantId, int $id, array $p): void
    {
        $this->db->execute(
            'UPDATE blog_posts SET slug = ?, title = ?, summary = ?, body = ?, category = ?, updated_at = ? WHERE tenant_id = ? AND id = ?',
            [$p['slug'], $p['title'], $p['summary'], $p['body'], $p['category'], $this->clock->sqlNow(), $tenantId, $id],
        );
    }

    public function setStatus(int $tenantId, int $id, string $status): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE blog_posts SET status = ?, published_at = ' . ($status === BlogPost::PUBLISHED ? 'COALESCE(published_at, ?)' : 'published_at') . ', updated_at = ? WHERE tenant_id = ? AND id = ?',
            $status === BlogPost::PUBLISHED ? [$status, $now, $now, $tenantId, $id] : [$status, $now, $tenantId, $id],
        );
    }

    public function delete(int $tenantId, int $id): void
    {
        $this->db->execute('DELETE FROM blog_posts WHERE tenant_id = ? AND id = ?', [$tenantId, $id]);
    }

    /**
     * Published posts, newest first.
     *
     * @return array{total: int, posts: list<BlogPost>}
     */
    public function published(int $tenantId, ?string $category, int $offset, int $limit): array
    {
        $where = 'p.tenant_id = ? AND p.status = ?';
        $params = [$tenantId, BlogPost::PUBLISHED];
        if ($category !== null) {
            $where .= ' AND p.category = ?';
            $params[] = $category;
        }
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) FROM blog_posts p WHERE ' . $where, $params);
        $posts = array_map(BlogPost::fromRow(...), $this->db->fetchAll(
            self::SELECT . ' WHERE ' . $where . ' ORDER BY p.published_at DESC, p.id DESC LIMIT ' . max(1, min($limit, 100)) . ' OFFSET ' . max(0, $offset),
            $params,
        ));

        return ['total' => $total, 'posts' => $posts];
    }

    /** @return array<string, int> category => published posts */
    public function categoryCounts(int $tenantId): array
    {
        $out = [];
        foreach ($this->db->fetchAll("SELECT category, COUNT(*) AS n FROM blog_posts WHERE tenant_id = ? AND status = 'published' GROUP BY category", [$tenantId]) as $r) {
            $out[(string) $r['category']] = (int) $r['n'];
        }

        return $out;
    }

    public function hasPublished(int $tenantId): bool
    {
        return $this->db->fetchValue("SELECT 1 FROM blog_posts WHERE tenant_id = ? AND status = 'published' LIMIT 1", [$tenantId]) !== null;
    }

    /** @return list<BlogPost> every post, drafts first, for the school's list */
    public function all(int $tenantId): array
    {
        return array_map(BlogPost::fromRow(...), $this->db->fetchAll(
            self::SELECT . " WHERE p.tenant_id = ? ORDER BY p.status = 'published', COALESCE(p.published_at, p.updated_at) DESC, p.id DESC LIMIT 500",
            [$tenantId],
        ));
    }
}
