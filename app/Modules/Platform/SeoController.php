<?php

declare(strict_types=1);

namespace App\Modules\Platform;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Courses\CourseRepository;
use App\Modules\Tenancy\Tenant;

/**
 * robots.txt and sitemap.xml for each school's address. Only public pages
 * are listed: the welcome page, and the course list and course pages when
 * the school lets visitors browse them. Everything behind sign-in is kept
 * out of search engines (the pages also say noindex).
 */
final class SeoController
{
    private const PRIVATE_PATHS = [
        '/account', '/teach', '/school', '/people', '/activity', '/groups', '/labs', '/setup', '/system', '/payments', '/files',
        '/password', '/login/verify', '/calendar', '/saved', '/search', '/help', '/notifications', '/children',
    ];

    public function __construct(private readonly CourseRepository $courses)
    {
    }

    public function robots(Request $request): Response
    {
        $lines = ['User-agent: *'];
        foreach (self::PRIVATE_PATHS as $path) {
            $lines[] = 'Disallow: ' . $path;
        }
        $lines[] = 'Allow: /';
        $lines[] = '';
        $lines[] = 'Sitemap: ' . $request->baseUrl() . '/sitemap.xml';

        return new Response(implode("\n", $lines) . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }

    public function sitemap(Request $request): Response
    {
        $base = $request->baseUrl();
        $urls = [[$base . '/', null]];
        $tenant = $request->attribute('tenant');
        if ($tenant instanceof Tenant && $tenant->publicCatalogue) {
            $urls[] = [$base . '/courses', null];
            for ($offset = 0; $offset < 5000; $offset += 100) {
                $batch = $this->courses->listPublished($tenant->id, 100, $offset);
                foreach ($batch as $course) {
                    $urls[] = [$base . '/courses/' . rawurlencode($course->slug), null];
                }
                if (count($batch) < 100) {
                    break;
                }
            }
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as [$loc]) {
            $xml .= '  <url><loc>' . htmlspecialchars($loc, ENT_XML1 | ENT_QUOTES, 'UTF-8') . "</loc></url>\n";
        }
        $xml .= "</urlset>\n";

        return new Response($xml, 200, ['Content-Type' => 'application/xml; charset=utf-8', 'Cache-Control' => 'public, max-age=3600']);
    }
}
