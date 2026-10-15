<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0023: learning paths. */
final class PathsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    /** @var array<string, int> slug => course id */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        foreach (['html-basics', 'javascript-basics', 'web-project'] as $slug) {
            $this->ids[$slug] = $this->makeCourse($this->tenant, $teacher, $slug)['course']->id;
        }
        $this->ids['draft-course'] = $this->app->get(CourseRepository::class)->create($this->tenant->id, $teacher->id, 'draft-course', 'Draft course', '')->id;
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    private function pathId(): int
    {
        return (int) $this->db()->fetchValue('SELECT MAX(id) FROM learning_paths');
    }

    /** @return list<int> */
    private function order(int $pathId): array
    {
        return array_map('intval', array_column($this->db()->fetchAll('SELECT course_id FROM learning_path_courses WHERE path_id = ? ORDER BY position', [$pathId]), 'course_id'));
    }

    private function buildPublishedPath(): int
    {
        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/paths', ['title' => 'Junior web developer', 'summary' => 'From your first page to a working project.']);
        $id = $this->pathId();
        foreach (['html-basics', 'javascript-basics', 'web-project'] as $slug) {
            $this->post(self::HOST, '/teach/paths/' . $id . '/courses', ['course_id' => (string) $this->ids[$slug]]);
        }
        $this->post(self::HOST, '/teach/paths/' . $id . '/publish', ['publish' => '1']);

        return $id;
    }

    public function testTeachersBuildOrderAndPublishAPath(): void
    {
        $this->as('teacher@school.test');
        $this->assertSame(422, $this->post(self::HOST, '/teach/paths', ['title' => 'X', 'summary' => ''])->status);
        $this->assertRedirectTo('/teach/paths/1', $this->post(self::HOST, '/teach/paths', ['title' => 'Junior web developer', 'summary' => '']));
        $id = $this->pathId();

        $this->post(self::HOST, '/teach/paths/' . $id . '/courses', ['course_id' => (string) $this->ids['html-basics']]);
        $this->assertSame(422, $this->post(self::HOST, '/teach/paths/' . $id . '/courses', ['course_id' => (string) $this->ids['html-basics']])->status, 'no course twice');
        $this->post(self::HOST, '/teach/paths/' . $id . '/courses', ['course_id' => (string) $this->ids['draft-course']]);
        $edit = $this->get(self::HOST, '/teach/paths/' . $id)->body;
        $this->assertStringContainsString('&quot;Draft course&quot; is not published yet.', $edit);
        $this->post(self::HOST, '/teach/paths/' . $id . '/publish', ['publish' => '1']);
        $this->assertSame('draft', (string) $this->db()->fetchValue('SELECT status FROM learning_paths WHERE id = ?', [$id]), 'drafts block publishing');

        $this->post(self::HOST, '/teach/paths/' . $id . '/courses/' . $this->ids['draft-course'] . '/remove');
        $this->post(self::HOST, '/teach/paths/' . $id . '/courses', ['course_id' => (string) $this->ids['javascript-basics']]);
        $this->post(self::HOST, '/teach/paths/' . $id . '/courses', ['course_id' => (string) $this->ids['web-project']]);
        $this->post(self::HOST, '/teach/paths/' . $id . '/courses/' . $this->ids['web-project'] . '/move', ['direction' => 'up']);
        $this->assertSame([$this->ids['html-basics'], $this->ids['web-project'], $this->ids['javascript-basics']], $this->order($id));
        $this->post(self::HOST, '/teach/paths/' . $id . '/courses/' . $this->ids['html-basics'] . '/move', ['direction' => 'up']);
        $this->assertSame($this->ids['html-basics'], $this->order($id)[0], 'the first cannot move further up');

        $this->post(self::HOST, '/teach/paths/' . $id . '/publish', ['publish' => '1']);
        $this->assertSame('published', (string) $this->db()->fetchValue('SELECT status FROM learning_paths WHERE id = ?', [$id]));
        $this->post(self::HOST, '/teach/paths/' . $id . '/courses/' . $this->ids['web-project'] . '/remove');
        $this->post(self::HOST, '/teach/paths/' . $id . '/courses/' . $this->ids['javascript-basics'] . '/remove');
        $this->assertCount(2, $this->order($id), 'a published path keeps at least two courses');

        $this->post(self::HOST, '/teach/paths/' . $id . '/delete', ['confirm' => 'wrong']);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM learning_paths'));
        $this->post(self::HOST, '/teach/paths/' . $id . '/delete', ['confirm' => 'junior-web-developer']);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM learning_paths'));
        $this->assertSame(4, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM courses'), 'courses stay');

        $this->as('ada@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/teach/paths')->status);
    }

    public function testVisitorsSeePublishedPathsOnly(): void
    {
        $id = $this->buildPublishedPath();
        $this->newBrowser();
        $page = $this->get(self::HOST, '/paths/junior-web-developer');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('The courses, in order', $page->body);
        $this->assertLessThan(strpos($page->body, 'href="/courses/web-project"') ?: 0, strpos($page->body, 'href="/courses/html-basics"'));
        $this->assertStringContainsString('Sign in to start', $page->body);
        $list = $this->get(self::HOST, '/courses')->body;
        $this->assertStringContainsString('Learning paths', $list);
        $this->assertStringContainsString('href="/paths/junior-web-developer"', $list);
        $this->assertStringContainsString('/paths/junior-web-developer</loc>', $this->get(self::HOST, '/sitemap.xml')->body);

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/paths/' . $id . '/publish', ['publish' => '0']);
        $this->newBrowser();
        $this->assertSame(404, $this->get(self::HOST, '/paths/junior-web-developer')->status);

        $this->db()->execute('UPDATE tenants SET public_catalogue = 0 WHERE id = ?', [$this->tenant->id]);
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/paths/junior-web-developer'));
    }

    public function testLearnersFollowAPathToTheEnd(): void
    {
        $this->buildPublishedPath();
        $this->as('ada@school.test');
        $this->assertRedirectTo('/courses/html-basics', $this->post(self::HOST, '/paths/junior-web-developer/start'));
        $ada = $this->app->get(UserRepository::class)->findByEmail('ada@school.test');
        $this->assertNotNull($ada);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments WHERE user_id = ? AND course_id = ?', [$ada->id, $this->ids['html-basics']]), 'joined the first course');

        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('Your learning paths', $home);
        $this->assertStringContainsString('0 of 3 courses', $home);

        $this->db()->execute("UPDATE enrolments SET status = 'completed', completed_at = UTC_TIMESTAMP() WHERE user_id = ? AND course_id = ?", [$ada->id, $this->ids['html-basics']]);
        $page = $this->get(self::HOST, '/paths/junior-web-developer')->body;
        $this->assertStringContainsString('<strong>1 of 3</strong> courses done', $page);
        $this->assertStringContainsString('Continue: Course school', $page);

        // The next course has a price: starting again leaves paying to the learner.
        $this->app->get(CourseRepository::class)->updatePrice($this->tenant->id, $this->ids['javascript-basics'], 500000, 'NGN');
        $this->assertRedirectTo('/courses/javascript-basics', $this->post(self::HOST, '/paths/junior-web-developer/start'));
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments WHERE user_id = ? AND course_id = ?', [$ada->id, $this->ids['javascript-basics']]));

        foreach (['javascript-basics', 'web-project'] as $slug) {
            $this->db()->execute(
                "INSERT INTO enrolments (tenant_id, course_id, user_id, status, enrolled_at, completed_at) VALUES (?, ?, ?, 'completed', UTC_TIMESTAMP(), UTC_TIMESTAMP())",
                [$this->tenant->id, $this->ids[$slug], $ada->id],
            );
        }
        $this->assertStringContainsString('Path complete', $this->get(self::HOST, '/paths/junior-web-developer')->body);
        $this->get(self::HOST, '/');
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM achievements WHERE user_id = ? AND code = 'path'", [$ada->id]));

        $this->post(self::HOST, '/paths/junior-web-developer/leave');
        $this->assertStringNotContainsString('Your learning paths', $this->get(self::HOST, '/')->body);
        $this->assertSame(3, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments WHERE user_id = ?', [$ada->id]), 'leaving keeps the courses');
    }
}
