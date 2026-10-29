<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Update 39 (ADR 0039): About us from the school's own facts, and putting every page on the website in two steps. */
final class WebsitePagesReadyTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeCourse($this->tenant, $teacher, 'networking');
        $this->db()->execute("UPDATE tenants SET tagline = 'Learn by doing', contact_email = 'office@school.test', kind = 'training_centre' WHERE id = ?", [$this->tenant->id]);
        $this->db()->execute("UPDATE courses SET track = 'skills', summary = 'Cables, addresses and routes, with labs.' WHERE tenant_id = ?", [$this->tenant->id]);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    public function testEveryPageGoesOnTheWebsiteInTwoSteps(): void
    {
        $this->assertSame(404, $this->get(self::HOST, '/about')->status, 'nothing is public before a person publishes it');
        $this->as('owner@school.test');
        $this->assertStringContainsString('Your website has no About us, Contact or policy pages yet.', $this->get(self::HOST, '/school/website')->body);
        $ready = $this->get(self::HOST, '/school/website/ready')->body;
        $this->assertStringContainsString('Write the 9 drafts', $ready);
        $this->assertStringContainsString('Not written yet.', $ready);
        $this->assertStringContainsString('News on your blog', $ready, 'the blog is part of the website too');

        $this->assertRedirectTo('/school/website/ready', $this->post(self::HOST, '/school/website/ready/drafts'));
        $this->assertSame(9, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM site_pages WHERE tenant_id = ? AND status = 'draft'", [$this->tenant->id]));
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM site_pages WHERE body LIKE '%[[%'"), 'no gaps left');
        $this->assertSame(404, $this->get(self::HOST, '/about')->status, 'drafts are not public');
        $ready = $this->get(self::HOST, '/school/website/ready')->body;
        $this->assertStringContainsString('Ready to publish', $ready);
        $this->assertStringContainsString('What the draft decides', $ready);
        $this->assertStringContainsString('Says nothing about when the school was founded', $ready);

        $all = ['about', 'admissions', 'contact', 'faq', 'careers', 'privacy', 'terms', 'refunds', 'safeguarding', 'nonsense'];
        $this->assertRedirectTo('/school/website', $this->post(self::HOST, '/school/website/ready/publish', ['pages' => $all]));
        $this->newBrowser();
        $about = $this->get(self::HOST, '/about')->body;
        $this->assertStringContainsString('School Academy: Learn by doing.', $about);
        $this->assertStringContainsString('is a training centre that teaches on this website', $about);
        $this->assertStringContainsString('href="/courses/networking"', $about);
        $this->assertStringContainsString('Practical and professional skills', $about);
        $this->assertStringContainsString('office@school.test', $about);
        $this->assertStringNotContainsString('founded', strtolower(strip_tags($about)), 'no invented history');
        $home = $this->get(self::HOST, '/courses')->body;
        foreach (['/about' => 'About us', '/contact' => 'Contact us', '/privacy' => 'Privacy policy', '/faq' => 'Questions and answers'] as $path => $name) {
            $this->assertStringContainsString('href="' . $path . '">' . $name, $home, 'in the footer');
        }
        $this->as('owner@school.test');
        $this->assertStringContainsString('Every page is on your website', $this->get(self::HOST, '/school/website/ready')->body);
        $this->assertSame(9, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'site.page_published'"));
    }

    public function testTheSchoolsOwnPagesAreKeptAndPagesWithProblemsWait(): void
    {
        $this->db()->execute("UPDATE tenants SET contact_email = '', contact_phone = '' WHERE id = ?", [$this->tenant->id]);
        $this->as('owner@school.test');
        $own = 'We are a small coding school in Ibadan, teaching evenings and weekends since last year. Our three teachers all work in software.';
        $this->post(self::HOST, '/school/website/about', ['title' => 'About us', 'summary' => 'A small coding school in Ibadan, evenings and weekends.', 'body' => $own]);
        $this->post(self::HOST, '/school/website/ready/drafts');
        $this->assertSame($own, $this->db()->fetchValue("SELECT body FROM site_pages WHERE slug = 'about'"), 'a finished page of its own is never overwritten');

        $this->post(self::HOST, '/school/website/ready/publish', ['pages' => ['about', 'contact']]);
        $this->assertSame('published', $this->db()->fetchValue("SELECT status FROM site_pages WHERE slug = 'about'"));
        $this->assertSame('draft', $this->db()->fetchValue("SELECT status FROM site_pages WHERE slug = 'contact'"), 'contact needs an email or phone first');
        $ready = $this->get(self::HOST, '/school/website/ready')->body;
        $this->assertStringContainsString('Add the school&#039;s email or phone number in Settings first', $ready);
        $this->assertStringNotContainsString('About us</strong>', $ready, 'published pages leave the list');

        $this->as('teacher@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/school/website/ready')->status);
        $this->assertSame(403, $this->post(self::HOST, '/school/website/ready/drafts')->status);
        $this->assertSame(403, $this->post(self::HOST, '/school/website/ready/publish', ['pages' => ['contact']])->status);
    }
}
