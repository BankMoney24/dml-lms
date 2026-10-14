<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use Tests\Support\AppTestCase;

/** Update 19 (ADR 0019): canonical domain, metadata, structured data, website pages, share images, sitemap. */
final class WebsiteTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->course = $this->makeCourse($this->tenant, $teacher, 'networking');
        $this->db()->execute("UPDATE tenants SET tagline = 'Learn by doing', contact_email = 'office@school.test' WHERE id = ?", [$this->tenant->id]);
        $this->db()->execute('UPDATE lessons SET is_preview = 1 WHERE id = ?', [$this->course['reading']->id]);
    }

    private function signIn(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    private function fetch(string $path, string $host = self::HOST, bool $secure = false, string $method = 'GET'): Response
    {
        return $this->send(new Request($method, $path, $host, [], [], [], [], '0.0.0.0', $secure));
    }

    /** @return array<string, string> meta name/property => content */
    private static function metas(string $html): array
    {
        preg_match_all('/<meta (?:name|property)="([^"]+)" content="([^"]*)">/', $html, $m, PREG_SET_ORDER);
        $out = [];
        foreach ($m as $row) {
            $out[$row[1]] = html_entity_decode($row[2], ENT_QUOTES);
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private static function jsonLd(string $html): array
    {
        self::assertSame(1, preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m), 'one JSON-LD block');
        $data = json_decode($m[1] ?? '', true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param array<string, mixed> $graph
     * @return list<string>
     */
    private static function types(array $graph): array
    {
        $nodes = $graph['@graph'] ?? [];

        return is_array($nodes) ? array_values(array_map(static fn (mixed $n): string => is_array($n) ? (string) ($n['@type'] ?? '') : '', $nodes)) : [];
    }

    public function testPublicPagesHaveCompleteUniqueMetadataAndValidStructuredData(): void
    {
        $pages = ['/', '/courses', '/courses/networking', '/courses/networking/preview/' . $this->course['reading']->id];
        $titles = [];
        $descriptions = [];
        foreach ($pages as $path) {
            $html = $this->fetch($path)->body;
            $this->assertStringContainsString('<html lang="en-NG"', $html, $path);
            $this->assertSame(1, preg_match('#<title>([^<]+)</title>#', $html, $t), $path);
            $titles[] = $t[1] ?? '';
            $meta = self::metas($html);
            $descriptions[] = $meta['description'];
            $this->assertLessThanOrEqual(160, mb_strlen($meta['description']), $path);
            $this->assertSame('index, follow, max-image-preview:large', $meta['robots'], $path);
            foreach (['og:title', 'og:description', 'og:url', 'og:image', 'og:image:alt', 'og:locale', 'twitter:card', 'twitter:title', 'twitter:image', 'twitter:image:alt'] as $key) {
                $this->assertArrayHasKey($key, $meta, $path . ' has ' . $key);
            }
            $this->assertSame('en_NG', $meta['og:locale']);
            $this->assertSame('summary_large_image', $meta['twitter:card']);
            $this->assertStringStartsWith('http://school.test/', $meta['og:image']);
            $this->assertSame(1, substr_count($html, 'rel="canonical"'), $path);
            self::jsonLd($html);
        }
        $this->assertSame(count($pages), count(array_unique($titles)), 'titles are unique');
        $this->assertSame(count($pages), count(array_unique($descriptions)), 'descriptions are unique');

        $home = self::jsonLd($this->fetch('/')->body);
        $this->assertSame('https://schema.org', $home['@context']);
        $this->assertSame(['EducationalOrganization', 'WebSite'], self::types($home));
        $this->assertSame('http://school.test/#organization', $home['@graph'][0]['@id']);
        $this->assertArrayNotHasKey('address', $home['@graph'][0], 'no address the school did not give');
        $this->assertSame('office@school.test', $home['@graph'][0]['contactPoint'][0]['email']);

        $course = self::jsonLd($this->fetch('/courses/networking')->body);
        $this->assertSame(['Course', 'BreadcrumbList'], self::types($course));
        $crumbs = $course['@graph'][1]['itemListElement'];
        $this->assertSame([1, 2, 3], array_column($crumbs, 'position'));
        $this->assertSame('http://school.test/courses/networking', $crumbs[2]['item']);
        $this->assertArrayNotHasKey('courseWorkload', $course['@graph'][0]['hasCourseInstance'][0], 'no invented workload');

        // Filtered, paged and private pages are not indexed and carry no canonical or structured data.
        $filtered = $this->send(new Request('GET', '/courses', self::HOST, ['track' => 'skills']))->body;
        $this->assertStringContainsString('content="noindex, nofollow"', $filtered);
        $this->assertStringNotContainsString('rel="canonical"', $filtered);
        $this->assertStringNotContainsString('application/ld+json', $filtered);
        $this->assertStringContainsString('noindex', $this->fetch('/login')->body);
    }

    public function testThePrimaryDomainIsCanonicalAndAliasesRedirect(): void
    {
        // Before the switch, the temporary address (here on port 8088) is the primary one and keeps its port.
        $temporary = $this->send(new Request('GET', '/courses/networking', self::HOST, [], [], ['host' => 'school.test:8088']))->body;
        $this->assertStringContainsString('<link rel="canonical" href="http://school.test:8088/courses/networking">', $temporary);

        $domains = $this->app->get(TenantRepository::class);
        $domains->addDomain($this->tenant->id, 'school.org', true);
        $domains->addDomain($this->tenant->id, 'www.school.org', true);
        $this->assertTrue($domains->setPrimaryDomain($this->tenant->id, 'school.org'));
        $this->assertFalse($domains->setPrimaryDomain($this->tenant->id, 'evil.example'), 'only verified domains');
        $this->config['PUBLIC_SCHEME'] = 'https';

        // Without redirects switched on, every address works but names the primary one as canonical.
        $html = $this->fetch('/courses/networking')->body;
        $this->assertStringContainsString('<link rel="canonical" href="https://school.org/courses/networking">', $html);
        $this->assertStringContainsString('"@id":"https://school.org/#organization"', $html);
        $robots = $this->fetch('/robots.txt')->body;
        $this->assertSame("Disallow: /\n", substr($robots, -12), 'aliases ask not to be crawled');
        $this->assertStringContainsString('Sitemap: https://school.org/sitemap.xml', $this->fetch('/robots.txt', 'school.org', true)->body);
        $this->assertStringContainsString('<loc>https://school.org/courses/networking</loc>', $this->fetch('/sitemap.xml', 'school.org', true)->body);

        $this->config['CANONICAL_REDIRECT'] = 'true';
        $moved = $this->send(new Request('GET', '/courses', 'www.school.org', ['page' => '2', 'utm_source' => 'x<y']));
        $this->assertSame(301, $moved->status);
        $this->assertSame('https://school.org/courses?page=2&utm_source=x%3Cy', $moved->header('Location'));
        $this->assertSame(301, $this->fetch('/about', 'school.org')->status, 'plain HTTP moves to HTTPS');
        $this->assertSame('https://school.org/', $this->fetch('/', self::HOST)->header('Location'));
        $this->assertSame(200, $this->fetch('/', 'school.org', true)->status, 'no loop on the canonical address');
        $this->assertNotSame(301, $this->fetch('/login', self::HOST, false, 'POST')->status, 'forms posted to an alias are not redirected');
        $this->assertSame(200, $this->fetch('/health')->status);
    }

    public function testHstsOnlyOnHttpsAndWithoutSubdomainsByDefault(): void
    {
        $this->assertNull($this->fetch('/')->header('Strict-Transport-Security'));
        $this->assertSame('max-age=31536000', $this->fetch('/', self::HOST, true)->header('Strict-Transport-Security'));
        $this->config['HSTS_INCLUDE_SUBDOMAINS'] = 'true';
        $this->assertSame('max-age=31536000; includeSubDomains', $this->fetch('/', self::HOST, true)->header('Strict-Transport-Security'));
        $this->config['HSTS_MAX_AGE'] = '0';
        $this->assertNull($this->fetch('/', self::HOST, true)->header('Strict-Transport-Security'));

        $headers = $this->fetch('/courses');
        $this->assertStringContainsString("frame-ancestors 'none'", (string) $headers->header('Content-Security-Policy'));
        $this->assertStringContainsString("script-src 'self'", (string) $headers->header('Content-Security-Policy'));
        $this->assertSame('nosniff', $headers->header('X-Content-Type-Options'));
        $this->assertSame('strict-origin-when-cross-origin', $headers->header('Referrer-Policy'));
        $this->assertNotNull($headers->header('Permissions-Policy'));
    }

    public function testOnlyApprovedWordsGoLiveOnTheSchoolsPages(): void
    {
        $this->signIn('learner@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/school/website')->status);

        $this->signIn('admin@school.test');
        $this->assertStringContainsString('Privacy policy', $this->get(self::HOST, '/school/website')->body);
        $draft = $this->get(self::HOST, '/school/website/privacy')->body;
        $this->assertStringContainsString('[[The legal name of the organisation', $draft, 'starter draft with gaps');
        $this->assertStringContainsString('Card and bank details are entered on Paystack', $draft);

        // Save the starter as it is: it cannot be published while gaps remain.
        preg_match('#<textarea[^>]*name="body"[^>]*>(.*?)</textarea>#s', $draft, $m);
        $starter = html_entity_decode($m[1] ?? '', ENT_QUOTES);
        $summary = 'How School Academy looks after the personal information of learners, parents and staff.';
        $this->post(self::HOST, '/school/website/privacy', ['title' => 'Privacy policy', 'summary' => $summary, 'body' => $starter]);
        $this->post(self::HOST, '/school/website/privacy/publish');
        $this->assertSame('draft', $this->db()->fetchValue("SELECT status FROM site_pages WHERE slug = 'privacy'"));
        $this->assertStringContainsString('double brackets', $this->get(self::HOST, '/school/website/privacy')->body);
        $this->newBrowser();
        $this->assertSame(404, $this->get(self::HOST, '/privacy')->status, 'drafts are not public');

        $this->signIn('admin@school.test');
        $body = "We keep only what we need to teach you.\n\n## What we keep\n\nYour name, email and progress. <script>alert(1)</script> [Click](javascript:alert(1))\n\n"
            . 'Ask the office about anything else, any time you like.';
        $this->post(self::HOST, '/school/website/privacy', ['title' => 'Privacy policy', 'summary' => $summary, 'body' => $body]);
        $this->post(self::HOST, '/school/website/privacy/publish');
        $this->assertSame('published', $this->db()->fetchValue("SELECT status FROM site_pages WHERE slug = 'privacy'"));

        $this->newBrowser();
        $page = $this->get(self::HOST, '/privacy');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('We keep only what we need to teach you.', $page->body);
        $this->assertStringNotContainsString('<script>alert', $page->body, 'author HTML is escaped');
        $this->assertStringNotContainsString('href="javascript:', $page->body, 'unsafe links are not made');
        $this->assertStringContainsString('<link rel="canonical" href="http://school.test/privacy">', $page->body);
        $this->assertStringContainsString('href="/privacy">Privacy policy</a>', $this->get(self::HOST, '/')->body, 'linked from the footer');
        $this->assertStringContainsString('<loc>http://school.test/privacy</loc><lastmod>', $this->fetch('/sitemap.xml')->body);
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action IN ('site.page_saved') "));

        // The contact page needs real contact details; About credits the developer.
        $this->signIn('admin@school.test');
        $this->post(self::HOST, '/school/website/about', [
            'title' => 'About us', 'summary' => 'School Academy teaches practical technology skills to teenagers and adults.',
            'body' => str_repeat('We teach networking and programming in small classes. ', 4),
        ]);
        $this->post(self::HOST, '/school/website/about/publish');
        $this->newBrowser();
        $about = $this->get(self::HOST, '/about')->body;
        $this->assertStringContainsString('developed and are powered by <a href="https://tridax.org">TridaxLabs</a>', $about);
        $this->assertSame(['AboutPage', 'BreadcrumbList'], self::types(self::jsonLd($about)));

        $this->signIn('admin@school.test');
        $this->post(self::HOST, '/school/website/about/unpublish');
        $this->newBrowser();
        $this->assertSame(404, $this->get(self::HOST, '/about')->status);
    }

    public function testProfilesAndVerificationCodesAreChecked(): void
    {
        $this->signIn('admin@school.test');
        $bad = $this->post(self::HOST, '/school/website-settings', ['social_facebook' => 'http://evil.example/page', 'verify_google' => '"><script>alert(1)</script>']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('starting with https://facebook.com/', $bad->body);

        $tag = '<meta name="google-site-verification" content="AbCdEfGhIjKlMnOpQrStUvWxYz_0123456789-abc" />';
        $this->assertRedirectTo('/school/website', $this->post(self::HOST, '/school/website-settings', [
            'social_facebook' => 'https://www.facebook.com/schoolacademy/', 'social_x' => 'https://x.com/schoolacademy', 'verify_google' => $tag,
        ]));
        $this->newBrowser();
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('<meta name="google-site-verification" content="AbCdEfGhIjKlMnOpQrStUvWxYz_0123456789-abc">', $home);
        $this->assertSame(['https://www.facebook.com/schoolacademy', 'https://x.com/schoolacademy'], self::jsonLd($home)['@graph'][0]['sameAs']);
        $this->assertStringNotContainsString('google-site-verification', $this->get(self::HOST, '/courses')->body, 'only on the home page');
    }

    public function testShareImagesArePublicCookieFreeAndOnlyForPublicThings(): void
    {
        $site = $this->fetch('/share/site.png');
        $this->assertSame(200, $site->status);
        $this->assertSame('image/png', $site->header('Content-Type'));
        $this->assertSame('public, max-age=86400', $site->header('Cache-Control'));
        $this->assertNull($site->header('Set-Cookie'));
        $size = getimagesize((string) $site->filePath());
        $this->assertSame([1200, 630], [$size[0] ?? 0, $size[1] ?? 0]);

        $this->assertSame(200, $this->fetch('/share/courses/networking.png')->status);
        $this->assertSame(404, $this->fetch('/share/courses/nothing-here.png')->status);
        $this->assertSame(404, $this->fetch('/share/pages/privacy.png')->status, 'unpublished pages have no card');
        $teacherId = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'teacher@school.test'");
        $draft = $this->app->get(CourseRepository::class)->create($this->tenant->id, $teacherId, 'secret-plans', 'Secret plans', '');
        $this->assertSame(404, $this->fetch('/share/courses/' . $draft->slug . '.png')->status);
        $this->db()->execute('UPDATE tenants SET public_catalogue = 0 WHERE id = ?', [$this->tenant->id]);
        $this->assertSame(404, $this->fetch('/share/courses/networking.png')->status, 'members-only catalogues stay private');
    }

    public function testTheSitemapIsValidXmlWithOnlyPublicCanonicalAddresses(): void
    {
        $response = $this->fetch('/sitemap.xml');
        $this->assertSame('application/xml; charset=utf-8', $response->header('Content-Type'));
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response->body, \SimpleXMLElement::class, LIBXML_NONET);
        libxml_use_internal_errors($previous);
        $this->assertNotFalse($xml, 'well-formed');
        $locs = [];
        foreach ($xml->url as $url) {
            $loc = (string) $url->loc;
            $locs[] = $loc;
            $this->assertStringStartsWith('http://school.test/', $loc);
            if (isset($url->lastmod)) {
                $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\+00:00$/', (string) $url->lastmod);
            }
            foreach (\App\Modules\Platform\SeoController::PRIVATE_PATHS as $private) {
                $this->assertStringNotContainsString('school.test' . $private, $loc);
            }
        }
        $this->assertContains('http://school.test/courses/networking/preview/' . $this->course['reading']->id, $locs, 'free previews are listed');
        $this->assertSame(count($locs), count(array_unique($locs)));
        $robots = $this->fetch('/robots.txt')->body;
        $this->assertStringContainsString("Disallow: /verify/\n", $robots, 'certificate results carry names');
        $this->assertStringNotContainsString('Disallow: /assets', $robots, 'styles and scripts stay crawlable');
    }

    public function testInternalLinksOnPublicPagesWork(): void
    {
        $queue = ['/'];
        $seen = [];
        while ($queue !== [] && count($seen) < 60) {
            $path = array_shift($queue);
            if (isset($seen[$path])) {
                continue;
            }
            $response = $this->fetch($path);
            $seen[$path] = $response->status;
            $this->assertContains($response->status, [200, 301, 302, 303], $path . ' answers');
            if ($response->status !== 200 || !str_contains((string) $response->header('Content-Type'), 'text/html')) {
                continue;
            }
            preg_match_all('#href="(/[^"/][^"]*|/)"#', $response->body, $m);
            foreach ($m[1] as $href) {
                $href = html_entity_decode($href, ENT_QUOTES);
                $clean = (string) parse_url($href, PHP_URL_PATH);
                // Static files (styles, icons, the web manifest) are served by the web server, not the app.
                $static = $clean !== '/' && is_file(\App\Bootstrap\Application::basePath('public' . $clean));
                if ($clean !== '' && !$static && !isset($seen[$clean]) && !str_starts_with($clean, '/share/')) {
                    $queue[] = $clean;
                }
            }
        }
        $this->assertGreaterThan(5, count($seen));
    }
}
