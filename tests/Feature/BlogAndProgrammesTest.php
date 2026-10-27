<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Update 34 (ADR 0034): the blog, the programmes page, and the FAQ and careers pages. */
final class BlogAndProgrammesTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const OTHER = 'other.test';

    private Tenant $tenant;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->db()->execute("UPDATE tenants SET contact_email = 'office@school.test' WHERE id = ?", [$this->tenant->id]);
        $other = $this->makeTenant('other', self::OTHER);
        $this->makeUser($other, 'owner@other.test', Role::Owner);
    }

    private function as(string $email, string $host = self::HOST): void
    {
        $this->newBrowser();
        $this->login($host, $email, 'correct-horse-battery');
    }

    /** @return array{title: string, summary: string, body: string, category: string, slug: string} */
    private function postData(string $title = 'Open day on Saturday'): array
    {
        return [
            'title' => $title,
            'summary' => 'Come and see the labs, meet the teachers and try a lesson on Saturday 7 November from 10am.',
            'body' => "## What happens\n\nTeachers show the labs and answer questions. Bring a phone to try a lesson.",
            'category' => 'events',
            'slug' => '',
        ];
    }

    public function testABlogPostIsADraftUntilPublishedThenAppearsInTheBlogFeedSitemapAndMenu(): void
    {
        $this->assertSame(404, $this->get(self::HOST, '/blog')->status, 'no blog until a post is published');
        $this->assertStringNotContainsString('href="/blog"', $this->get(self::HOST, '/')->body);

        $this->as('owner@school.test');
        $bad = $this->post(self::HOST, '/school/blog', ['title' => 'Hi', 'summary' => '', 'body' => 'short', 'category' => 'gossip', 'slug' => 'Bad Slug']);
        $this->assertSame(422, $bad->status);
        foreach (['Give the post a title', 'Write the post', 'Choose what kind of post', 'Use small letters'] as $message) {
            $this->assertStringContainsString($message, $bad->body);
        }

        $created = $this->post(self::HOST, '/school/blog', $this->postData());
        $id = (int) $this->db()->fetchValue("SELECT id FROM blog_posts WHERE slug = 'open-day-on-saturday'");
        $this->assertGreaterThan(0, $id, 'the address is made from the title');
        $this->assertRedirectTo('/school/blog/' . $id, $created);
        $this->assertSame(200, $this->get(self::HOST, '/school/blog/' . $id . '/preview')->status);

        $this->newBrowser();
        $this->assertSame(404, $this->get(self::HOST, '/blog/open-day-on-saturday')->status, 'drafts are not public');

        $this->as('owner@school.test');
        $this->assertRedirectTo('/school/blog/' . $id, $this->post(self::HOST, '/school/blog/' . $id . '/publish'));
        $this->assertSame('published', $this->db()->fetchValue('SELECT status FROM blog_posts WHERE id = ?', [$id]));

        $this->newBrowser();
        $page = $this->get(self::HOST, '/blog/open-day-on-saturday');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('"@type":"BlogPosting"', $page->body);
        $this->assertStringContainsString('<h2>What happens</h2>', $page->body);
        $this->assertStringContainsString('/share/posts/open-day-on-saturday.png', $page->body);

        $list = $this->get(self::HOST, '/blog');
        $this->assertSame(200, $list->status);
        $this->assertStringContainsString('Open day on Saturday', $list->body);
        $this->assertStringContainsString('href="/blog"', $list->body, 'the menu links the blog');
        $this->assertStringContainsString('application/rss+xml', $list->body);
        $this->assertSame(200, $this->get(self::HOST, '/blog?category=events')->status);
        $this->assertStringContainsString('Nothing here yet', $this->get(self::HOST, '/blog?category=news')->body);

        $feed = $this->get(self::HOST, '/blog/feed.xml');
        $this->assertSame(200, $feed->status);
        $this->assertStringStartsWith('application/rss+xml', (string) $feed->header('Content-Type'));
        $this->assertStringContainsString('<title>Open day on Saturday</title>', $feed->body);
        $this->assertStringContainsString('/blog/open-day-on-saturday</loc>', $this->get(self::HOST, '/sitemap.xml')->body);

        // Another school sees nothing.
        $this->assertSame(404, $this->get(self::OTHER, '/blog/open-day-on-saturday')->status);
        $this->assertSame(404, $this->get(self::OTHER, '/blog')->status);
    }

    public function testPublishedPostsKeepTheirAddressAndCannotBeDeletedWhileLive(): void
    {
        $this->as('owner@school.test');
        $this->post(self::HOST, '/school/blog', $this->postData());
        $id = (int) $this->db()->fetchValue('SELECT id FROM blog_posts');
        $this->post(self::HOST, '/school/blog/' . $id . '/publish');

        $this->post(self::HOST, '/school/blog/' . $id, ['slug' => 'changed'] + $this->postData('Open day, new time'));
        $row = $this->db()->fetchOne('SELECT slug, title FROM blog_posts WHERE id = ?', [$id]) ?? [];
        $this->assertSame(['open-day-on-saturday', 'Open day, new time'], [$row['slug'] ?? null, $row['title'] ?? null]);

        $this->post(self::HOST, '/school/blog/' . $id . '/delete');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM blog_posts'), 'a live post is not deleted');
        $this->post(self::HOST, '/school/blog/' . $id . '/unpublish');
        $this->assertRedirectTo('/school/blog', $this->post(self::HOST, '/school/blog/' . $id . '/delete'));
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM blog_posts'));
    }

    public function testOnlySchoolManagersWriteTheBlogAndPostsStayInTheirSchool(): void
    {
        $this->as('learner@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/school/blog')->status);
        $this->assertSame(403, $this->post(self::HOST, '/school/blog', $this->postData())->status);

        $this->as('owner@school.test');
        $this->post(self::HOST, '/school/blog', $this->postData());
        $id = (int) $this->db()->fetchValue('SELECT id FROM blog_posts');
        $this->as('owner@other.test', self::OTHER);
        $this->assertSame(404, $this->get(self::OTHER, '/school/blog/' . $id)->status);
        $this->assertSame(404, $this->post(self::OTHER, '/school/blog/' . $id . '/publish')->status);
    }

    public function testStarterPostsAreHonestDraftsAddedOnce(): void
    {
        $this->as('owner@school.test');
        $this->assertRedirectTo('/school/blog', $this->post(self::HOST, '/school/blog/starters'));
        $rows = $this->db()->fetchAll('SELECT slug, status, title, summary, body FROM blog_posts ORDER BY id');
        $this->assertCount(3, $rows, 'no everyday courses, so no post about them');
        foreach ($rows as $row) {
            $this->assertSame('draft', $row['status']);
            $this->assertStringNotContainsString('[[', (string) $row['body']);
            $this->assertGreaterThanOrEqual(50, mb_strlen((string) $row['summary']), 'ready to publish once read');
        }
        $this->assertStringContainsString('office@school.test', (string) $rows[0]['body']);
        $this->post(self::HOST, '/school/blog/starters');
        $this->assertSame(3, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM blog_posts'), 'not added twice');
    }

    public function testProgrammesGroupFormalSkillsAndEverydayLearningFromRealCourses(): void
    {
        $this->assertSame(404, $this->get(self::HOST, '/programmes')->status, 'no courses, no page');
        $school = $this->makeCourse($this->tenant, $this->teacher, 'physics')['course'];
        $everyday = $this->makeCourse($this->tenant, $this->teacher, 'phone-money')['course'];
        $this->db()->execute("UPDATE courses SET track = 'school', title = 'Physics SS2' WHERE id = ?", [$school->id]);
        $this->db()->execute("UPDATE courses SET track = 'everyday', title = 'Your phone and your money', access = 'open' WHERE id = ?", [$everyday->id]);
        $this->db()->execute("UPDATE tenants SET levels = ?, calendar = 'terms' WHERE id = ?", [json_encode(['SS1', 'SS2']), $this->tenant->id]);

        $page = $this->get(self::HOST, '/programmes');
        $this->assertSame(200, $page->status);
        $body = $page->body;
        $this->assertStringContainsString('Formal learning', $body);
        $this->assertStringContainsString('Informal learning', $body);
        $this->assertStringNotContainsString('id="skills"', $body, 'a track with no courses is left out');
        $this->assertStringContainsString('Learners in SS1, SS2', $body);
        $this->assertStringContainsString('Follows the school’s terms', $body);
        $this->assertStringContainsString('Open courses can be read without an account', $body);
        $this->assertLessThan(strpos($body, 'Your phone and your money'), strpos($body, 'Physics SS2'), 'formal first, informal last');
        $this->assertStringContainsString('href="/programmes"', $body);
        $this->assertStringContainsString('/programmes</loc>', $this->get(self::HOST, '/sitemap.xml')->body);

        // A members-only course list: visitors see only open courses here too.
        $this->db()->execute('UPDATE tenants SET public_catalogue = 0 WHERE id = ?', [$this->tenant->id]);
        $closed = $this->get(self::HOST, '/programmes')->body;
        $this->assertStringNotContainsString('Physics SS2', $closed);
        $this->assertStringContainsString('Your phone and your money', $closed);
    }

    public function testFaqAndCareersFullDraftsHaveNoGapsAndTheFaqIsMarkedUpForSearch(): void
    {
        $this->makeCourse($this->tenant, $this->teacher, 'physics');
        $this->as('owner@school.test');
        $edit = $this->get(self::HOST, '/school/website/careers')->body;
        $this->assertStringContainsString('no advertised vacancies', $edit, 'the choice is shown before it is used');
        foreach (['faq', 'careers'] as $slug) {
            $this->assertRedirectTo('/school/website/' . $slug, $this->post(self::HOST, '/school/website/' . $slug . '/full-draft'));
            $row = $this->db()->fetchOne('SELECT status, body FROM site_pages WHERE slug = ?', [$slug]) ?? [];
            $this->assertSame('draft', $row['status'] ?? null);
            $this->assertStringNotContainsString('[[', (string) ($row['body'] ?? ''));
        }
        $faq = (string) $this->db()->fetchValue("SELECT body FROM site_pages WHERE slug = 'faq'");
        $this->assertStringContainsString('The school makes accounts for its learners', $faq, 'sign-up is closed on this school');
        $this->assertStringContainsString('Do I get a certificate?', $faq);

        $this->post(self::HOST, '/school/website/faq/publish');
        $this->newBrowser();
        $page = $this->get(self::HOST, '/faq');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('"@type":"FAQPage"', $page->body);
        $this->assertStringContainsString('"acceptedAnswer"', $page->body);
        $this->assertMatchesRegularExpression('#<nav class="footer-col" aria-label="School">.*href="/faq"#s', $page->body);
    }
}
