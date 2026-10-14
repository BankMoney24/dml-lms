<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Database\Connection;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Site\SitePageRepository;
use App\Modules\Tenancy\CanonicalUrl;
use App\Modules\Tenancy\Tenant;

/**
 * robots.txt and sitemap.xml for each school's address (ADR 0014, 0019).
 * Only public pages are listed, on the school's canonical address: the
 * welcome page, published website pages, and (when the school lets visitors
 * browse) the course list, course pages and free preview lessons, each with
 * the date its content last changed. Everything behind sign-in is kept out
 * (those pages also say noindex, and they need a sign-in anyway; robots.txt
 * is not access control). On an alias address everything is disallowed, so
 * only the canonical copy is crawled.
 */
final class SeoController
{
    public const PRIVATE_PATHS = [
        '/account', '/teach', '/school', '/people', '/activity', '/groups', '/labs', '/setup', '/system', '/payments', '/files',
        '/password', '/login', '/calendar', '/saved', '/search', '/help', '/notifications', '/children', '/certificates', '/webhooks',
        // Certificate check results carry the holder's name: reachable by code, never crawled.
        '/verify/',
    ];

    public function __construct(
        private readonly Connection $db,
        private readonly CanonicalUrl $canonical,
        private readonly SitePageRepository $pages,
    ) {
    }

    public function robots(Request $request): Response
    {
        $tenant = $request->attribute('tenant');
        $base = $this->canonical->base($tenant instanceof Tenant ? $tenant : null, $request);
        $primary = $tenant instanceof Tenant ? $this->canonical->primaryHost($tenant) : null;
        if ($primary !== null && $request->host !== $primary) {
            $body = "# This address is an alias of {$base}/ ; please crawl that one.\nUser-agent: *\nDisallow: /\n";
        } else {
            $lines = ['User-agent: *'];
            foreach (self::PRIVATE_PATHS as $path) {
                $lines[] = 'Disallow: ' . $path;
            }
            // Styles, scripts, images and share cards stay crawlable so pages render as visitors see them.
            $lines[] = 'Allow: /';
            $lines[] = '';
            $lines[] = 'Sitemap: ' . $base . '/sitemap.xml';
            $body = implode("\n", $lines) . "\n";
        }

        return new Response($body, 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(Request $request): Response
    {
        $tenant = $request->attribute('tenant');
        $tenant = $tenant instanceof Tenant ? $tenant : null;
        $base = $this->canonical->base($tenant, $request);
        /** @var list<array{string, ?string}> $urls */
        $urls = [[$base . '/', null]];
        if ($tenant !== null) {
            foreach ($this->pages->published($tenant->id) as $page) {
                $urls[] = [$base . $page->path(), $page->updatedAt];
            }
            if ($tenant->publicCatalogue) {
                $courses = $this->db->fetchAll(
                    "SELECT id, slug, updated_at FROM courses WHERE tenant_id = ? AND status = 'published' ORDER BY title LIMIT 5000",
                    [$tenant->id],
                );
                $latest = $courses === [] ? null : max(array_map(static fn (array $c): string => (string) $c['updated_at'], $courses));
                $urls[] = [$base . '/courses', $latest];
                foreach ($courses as $c) {
                    $urls[] = [$base . '/courses/' . rawurlencode((string) $c['slug']), (string) $c['updated_at']];
                }
                $previews = $this->db->fetchAll(
                    "SELECT l.id, l.updated_at, c.slug FROM lessons l
                     JOIN courses c ON c.tenant_id = l.tenant_id AND c.id = l.course_id AND c.status = 'published'
                     WHERE l.tenant_id = ? AND l.is_preview = 1 AND l.type IN ('text', 'video') ORDER BY c.title, l.position LIMIT 5000",
                    [$tenant->id],
                );
                foreach ($previews as $p) {
                    $urls[] = [$base . '/courses/' . rawurlencode((string) $p['slug']) . '/preview/' . (int) $p['id'], (string) $p['updated_at']];
                }
            }
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc, $modified]) {
            $xml .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</loc>';
            if ($modified !== null && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $modified) === 1) {
                $xml .= '<lastmod>' . str_replace(' ', 'T', $modified) . '+00:00</lastmod>';
            }
            $xml .= "</url>\n";
        }
        $xml .= "</urlset>\n";

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600', 'X-Robots-Tag' => 'noindex']);
    }
}
