<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** What search engines and link previews see, and what they must not. */
final class SeoTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $course = $this->makeCourse($this->tenant, $teacher, 'networking')['course'];
        $this->app->get(CourseRepository::class)->updatePrice($this->tenant->id, $course->id, 1500000, 'NGN');
    }

    public function testPublicPagesAreDescribedForSearchAndSharing(): void
    {
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('<link rel="canonical" href="http://school.test/">', $home);
        $this->assertStringNotContainsString('noindex', $home);
        $this->assertStringContainsString('<meta property="og:title"', $home);
        $this->assertStringContainsString('"@type":"EducationalOrganization"', $home);
        $this->assertStringContainsString('powered by TridaxLabs', $home);

        $course = $this->get(self::HOST, '/courses/networking')->body;
        $this->assertStringContainsString('<link rel="canonical" href="http://school.test/courses/networking">', $course);
        $this->assertStringContainsString('<meta name="description" content="Summary for school">', $course);
        $this->assertStringContainsString('"@type":"Course"', $course);
        $this->assertStringContainsString('"price":"15000.00","priceCurrency":"NGN"', $course);
        $this->assertStringContainsString('"provider":{"@type":"EducationalOrganization","name":"School Academy"', $course);
    }

    public function testPrivatePagesAskSearchEnginesToStayAway(): void
    {
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $page = $this->get(self::HOST, '/account')->body;
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow">', $page);
        $this->assertStringNotContainsString('application/ld+json', $page);
        $this->newBrowser();
        $this->assertStringContainsString('noindex', $this->get(self::HOST, '/login')->body);
    }

    public function testRobotsAndSitemapFollowTheSchoolsChoice(): void
    {
        $robots = $this->send(new Request('GET', '/robots.txt', self::HOST));
        $this->assertSame('text/plain; charset=utf-8', $robots->header('Content-Type'));
        $this->assertStringContainsString("Disallow: /teach\n", $robots->body);
        $this->assertStringContainsString('Sitemap: http://school.test/sitemap.xml', $robots->body);
        $this->assertNull($robots->header('Set-Cookie'), 'no session for crawlers');

        $sitemap = $this->send(new Request('GET', '/sitemap.xml', self::HOST))->body;
        $this->assertStringContainsString('<loc>http://school.test/courses/networking</loc>', $sitemap);

        $this->db()->execute('UPDATE tenants SET public_catalogue = 0 WHERE id = ?', [$this->tenant->id]);
        $sitemap = $this->send(new Request('GET', '/sitemap.xml', self::HOST))->body;
        $this->assertStringNotContainsString('/courses', $sitemap, 'members-only course lists stay out');
        $this->assertStringContainsString('<loc>http://school.test/</loc>', $sitemap);
    }
}
