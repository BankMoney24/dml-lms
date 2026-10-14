<?php

declare(strict_types=1);

namespace App\Modules\Site;

use App\Core\Clock;
use App\Core\Database\Connection;

final class SitePageRepository
{
    public function __construct(private readonly Connection $db, private readonly Clock $clock)
    {
    }

    public function find(int $tenantId, string $slug): ?SitePage
    {
        $row = $this->db->fetchOne('SELECT * FROM site_pages WHERE tenant_id = ? AND slug = ?', [$tenantId, $slug]);

        return $row === null ? null : SitePage::fromRow($row);
    }

    /** @return array<string, SitePage> by slug */
    public function all(int $tenantId): array
    {
        $out = [];
        foreach ($this->db->fetchAll('SELECT * FROM site_pages WHERE tenant_id = ?', [$tenantId]) as $row) {
            $page = SitePage::fromRow($row);
            $out[$page->slug] = $page;
        }

        return $out;
    }

    /** @return list<SitePage> published pages, in the standard order */
    public function published(int $tenantId): array
    {
        $all = $this->all($tenantId);
        $out = [];
        foreach (array_keys(SitePages::PAGES) as $slug) {
            if (isset($all[$slug]) && $all[$slug]->isPublished()) {
                $out[] = $all[$slug];
            }
        }

        return $out;
    }

    public function save(int $tenantId, string $slug, string $title, string $summary, string $body, int $userId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'INSERT INTO site_pages (tenant_id, slug, title, summary, body, status, updated_by, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE title = VALUES(title), summary = VALUES(summary), body = VALUES(body),
                                     updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$tenantId, $slug, $title, $summary, $body, SitePage::DRAFT, $userId, $now, $now],
        );
    }

    public function setStatus(int $tenantId, string $slug, string $status, int $userId): void
    {
        $now = $this->clock->sqlNow();
        $this->db->execute(
            'UPDATE site_pages SET status = ?, published_at = ' . ($status === SitePage::PUBLISHED ? 'COALESCE(published_at, ?)' : '?') . ', updated_by = ?, updated_at = ?
             WHERE tenant_id = ? AND slug = ?',
            [$status, $status === SitePage::PUBLISHED ? $now : null, $userId, $now, $tenantId, $slug],
        );
    }
}
