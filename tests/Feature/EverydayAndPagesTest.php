<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Modules\Courses\CoursePackageImporter;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Joining\LearnerProfile;
use App\Modules\Joining\Recommendations;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Update 33 (ADR 0033): full drafts of the school's pages, platform pages, and everyday (informal) learning. */
final class EverydayAndPagesTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->db()->execute("UPDATE tenants SET contact_email = 'office@school.test', contact_phone = '0800 000 0000' WHERE id = ?", [$this->tenant->id]);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    /** @return array{course: \App\Modules\Courses\Course, modules: int, lessons: int, questions: int, files: int} */
    private function importEveryday(): array
    {
        $membership = $this->app->get(MembershipRepository::class)->find($this->tenant->id, $this->teacher->id) ?? throw new \LogicException();

        return $this->app->get(CoursePackageImporter::class)->import(
            $this->tenant->id,
            new CurrentUser($this->teacher, $membership),
            Application::basePath('courses/everyday-digital-skills'),
            true,
        );
    }

    public function testFullDraftsHaveNoGapsUseTheSchoolsFactsAndStillNeedAPersonToPublish(): void
    {
        $this->importEveryday();
        $this->as('owner@school.test');
        $edit = $this->get(self::HOST, '/school/website/refunds')->body;
        $this->assertStringContainsString('Start from a full draft', $edit);
        $this->assertStringContainsString('A full refund within 7 days of paying', $edit, 'the choices are listed before using it');

        foreach (['admissions', 'contact', 'privacy', 'terms', 'refunds', 'safeguarding'] as $slug) {
            $this->assertRedirectTo('/school/website/' . $slug, $this->post(self::HOST, '/school/website/' . $slug . '/full-draft'));
            $row = $this->db()->fetchOne('SELECT status, summary, body FROM site_pages WHERE slug = ?', [$slug]) ?? [];
            $this->assertSame('draft', $row['status'] ?? null, $slug . ' is saved as a draft, not published');
            $this->assertStringNotContainsString('[[', (string) ($row['body'] ?? ''), $slug . ' has no gaps');
            $this->assertGreaterThanOrEqual(50, mb_strlen((string) ($row['summary'] ?? '')));
        }
        $privacy = (string) $this->db()->fetchValue("SELECT body FROM site_pages WHERE slug = 'privacy'");
        $this->assertStringContainsString('by email at office@school.test, or by phone on 0800 000 0000', $privacy);
        $admissions = (string) $this->db()->fetchValue("SELECT body FROM site_pages WHERE slug = 'admissions'");
        $this->assertStringContainsString('[Everyday Digital Skills: Your Phone, Your Money, Your Business](/courses/', $admissions, 'lists the real courses');

        $this->assertRedirectTo('/school/website/refunds', $this->post(self::HOST, '/school/website/refunds/publish'));
        $this->newBrowser();
        $page = $this->get(self::HOST, '/refunds');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('within 7 days of paying', $page->body);

        // A published page is never replaced by a draft.
        $this->as('owner@school.test');
        $this->assertSame(404, $this->post(self::HOST, '/school/website/refunds/full-draft')->status);
        $this->as('learner@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/school/website/privacy/full-draft')->status);
    }

    public function testDevelopersAccessibilityAndSecurityTxt(): void
    {
        $dev = $this->get(self::HOST, '/developers');
        $this->assertSame(200, $dev->status);
        $this->assertStringContainsString('designed and built by TridaxLabs', $dev->body);
        $this->assertStringContainsString('href="https://tridax.org"', $dev->body);
        $this->assertSame(200, $this->get(self::HOST, '/accessibility')->status);
        $this->assertStringContainsString('href="/developers">Developers</a>', $this->get(self::HOST, '/login')->body, 'in the footer');

        $txt = $this->get(self::HOST, '/.well-known/security.txt');
        $this->assertSame(200, $txt->status);
        $this->assertStringStartsWith('text/plain', (string) $txt->header('Content-Type'));
        $this->assertMatchesRegularExpression('/^Contact: https:\/\/tridax\.org$/m', $txt->body);
        $this->assertMatchesRegularExpression('/^Expires: \d{4}-\d\d-\d\dT00:00:00Z$/m', $txt->body);
        $this->assertStringContainsString('<loc>http://school.test/developers</loc>', $this->get(self::HOST, '/sitemap.xml')->body);
    }

    public function testTheEverydayCourseImportsOpenFreeAndRecommendedToEverydayLearners(): void
    {
        $result = $this->importEveryday();
        $this->assertSame([4, 17, 24], [$result['modules'], $result['lessons'], $result['questions']]);
        $course = $result['course'];
        $row = $this->db()->fetchOne('SELECT track, access, price_minor, status FROM courses WHERE id = ?', [$course->id]) ?? [];
        $free = array_key_exists('price_minor', $row) && $row['price_minor'] === null;
        $this->assertSame(['everyday', 'open', true, 'published'], [$row['track'] ?? '', $row['access'] ?? '', $free, $row['status'] ?? '']);
        $this->assertSame(3, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lessons WHERE course_id = ? AND is_mandatory = 0', [$course->id]), 'assignments and the extra reading are optional');
        $this->assertSame(0, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM questions q JOIN assessments a ON a.id = q.assessment_id JOIN lessons l ON l.id = a.lesson_id
             WHERE l.course_id = ? AND (q.explanation IS NULL OR q.explanation = '')",
            [$course->id],
        ));

        // Anyone can read it; the home page and the course list show it as everyday learning.
        $this->newBrowser();
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('Learn for everyday life', $home);
        $list = $this->get(self::HOST, '/courses?track=everyday')->body;
        $this->assertStringContainsString('aria-current="page">Everyday learning <span class="tab-count">(1)</span>', $list);
        $first = (int) $this->db()->fetchValue('SELECT id FROM lessons WHERE course_id = ? ORDER BY position, id LIMIT 1', [$course->id]);
        $this->assertSame(200, $this->get(self::HOST, '/courses/' . $course->slug . '/preview/' . $first)->status);

        $profile = new LearnerProfile($this->tenant->id, 1, ['everyday'], 'new', 3, null, null, 'self', null);
        $courses = $this->app->get(\App\Modules\Courses\CourseRepository::class)->listPublished($this->tenant->id);
        $picks = Recommendations::rank($profile, $courses);
        $this->assertNotEmpty($picks);
        $this->assertSame($course->id, $picks[0]['course']->id);
        $this->assertContains('Everyday digital skills', $picks[0]['reasons'] ?? []);
    }
}
