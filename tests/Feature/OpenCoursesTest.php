<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use Tests\Support\AppTestCase;

/** ADR 0021: open courses anyone can read, the course list filters, and staff previews. */
final class OpenCoursesTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    /** @var array{course: Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $open;
    /** @var array{course: Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $members;
    /** @var array{course: Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $paid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->open = $this->makeCourse($this->tenant, $teacher, 'python-basics');
        $this->members = $this->makeCourse($this->tenant, $teacher, 'school-maths');
        $this->paid = $this->makeCourse($this->tenant, $teacher, 'linux-pro');
        $courses = $this->app->get(CourseRepository::class);
        $courses->updateAccess($this->tenant->id, $this->open['course']->id, Course::ACCESS_OPEN);
        $courses->updatePrice($this->tenant->id, $this->paid['course']->id, 2500000, 'NGN');
        $this->db()->execute("UPDATE courses SET title = 'Python basics', level = 'beginner' WHERE id = ?", [$this->open['course']->id]);
        $this->db()->execute("UPDATE courses SET title = 'Linux for professionals', level = 'advanced' WHERE id = ?", [$this->paid['course']->id]);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    private function membersOnlyList(): void
    {
        $this->db()->execute('UPDATE tenants SET public_catalogue = 0 WHERE id = ?', [$this->tenant->id]);
    }

    /** @param array<string, string> $query */
    private function list(array $query = []): string
    {
        return $this->send(new Request('GET', '/courses', self::HOST, $query))->body;
    }

    public function testVisitorsReadOpenCoursesWithoutAnAccount(): void
    {
        $page = $this->get(self::HOST, '/courses/python-basics');
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Free and open to everyone', $page->body);
        $this->assertStringContainsString('Start reading: Reading of school', $page->body);
        $this->assertStringContainsString('href="/courses/python-basics/preview/' . $this->open['reading']->id . '"', $page->body);
        $this->assertStringContainsString('Join to do this', $page->body, 'the quiz needs joining');

        $lesson = $this->get(self::HOST, '/courses/python-basics/preview/' . $this->open['reading']->id);
        $this->assertSame(200, $lesson->status);
        $this->assertStringContainsString('Secret text of school', $lesson->body, 'the whole reading, not marked as a preview');
        $this->assertStringContainsString('Open to everyone', $lesson->body);
        $this->assertStringContainsString('index, follow', $lesson->body);
        $this->assertSame(404, $this->get(self::HOST, '/courses/python-basics/preview/' . $this->open['quiz']->id)->status, 'quizzes are never open');

        // A members course in the same school stays closed.
        $this->assertSame(404, $this->get(self::HOST, '/courses/school-maths/preview/' . $this->members['reading']->id)->status);
        $this->assertStringContainsString('Sign in to enrol', $this->get(self::HOST, '/courses/school-maths')->body);
        $this->assertStringNotContainsString('Join to do this', $this->get(self::HOST, '/courses/school-maths')->body);
    }

    public function testOpenCoursesShowEvenWhenTheCourseListIsForMembers(): void
    {
        $this->membersOnlyList();

        $list = $this->get(self::HOST, '/courses');
        $this->assertSame(200, $list->status);
        $this->assertStringContainsString('Python basics', $list->body);
        $this->assertStringNotContainsString('Course school', $list->body, 'members courses are not listed for visitors');
        $this->assertStringNotContainsString('Linux for professionals', $list->body);
        $this->assertStringContainsString('no account needed', $list->body);

        $this->assertSame(200, $this->get(self::HOST, '/courses/python-basics')->status);
        $this->assertSame(200, $this->get(self::HOST, '/courses/python-basics/preview/' . $this->open['reading']->id)->status);
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/courses/school-maths'));
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/courses/no-such-course'), 'no hint about which courses exist');

        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('See the free courses', $home);
        $this->assertStringContainsString('Python basics', $home);
        $this->assertStringContainsString('href="/courses"', $home, 'the header links to the open courses');

        $sitemap = $this->get(self::HOST, '/sitemap.xml')->body;
        $this->assertStringContainsString('/courses/python-basics</loc>', $sitemap);
        $this->assertStringContainsString('/courses/python-basics/preview/' . $this->open['reading']->id . '</loc>', $sitemap);
        $this->assertStringNotContainsString('school-maths', $sitemap);
        $this->assertStringNotContainsString('/preview/' . $this->open['quiz']->id . '<', $sitemap);
        $this->assertSame(200, $this->get(self::HOST, '/share/courses/python-basics.png')->status);
        $this->assertSame(404, $this->get(self::HOST, '/share/courses/school-maths.png')->status);

        // With nothing open, the members-only list asks visitors to sign in, as before.
        $this->app->get(CourseRepository::class)->updateAccess($this->tenant->id, $this->open['course']->id, Course::ACCESS_MEMBERS);
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/courses'));
        $this->assertStringNotContainsString('See the free courses', $this->get(self::HOST, '/')->body);

        // Members still see every course.
        $this->as('learner@school.test');
        $this->assertStringContainsString('Linux for professionals', $this->get(self::HOST, '/courses')->body);
    }

    public function testCourseListFiltersByPriceAccessLevelAndWords(): void
    {
        $all = $this->list();
        $this->assertStringContainsString('Open to read <span class="chip-count">1</span>', $all);
        $this->assertStringContainsString('Free <span class="chip-count">2</span>', $all);
        $this->assertStringContainsString('Paid <span class="chip-count">1</span>', $all);

        $open = $this->list(['show' => 'open']);
        $this->assertStringContainsString('<h1>Open courses</h1>', $open);
        $this->assertStringContainsString('Python basics', $open);
        $this->assertStringNotContainsString('Course school', $open);
        $this->assertStringContainsString('<link rel="canonical" href="http://school.test/courses?show=open">', $open);

        $free = $this->list(['show' => 'free']);
        $this->assertStringContainsString('Python basics', $free);
        $this->assertStringNotContainsString('Linux for professionals', $free);

        $paid = $this->list(['show' => 'paid']);
        $this->assertStringContainsString('Linux for professionals', $paid);
        $this->assertStringNotContainsString('Python basics', $paid);

        $advanced = $this->list(['level' => 'advanced']);
        $this->assertStringContainsString('Linux for professionals', $advanced);
        $this->assertStringNotContainsString('Python basics', $advanced);
        $this->assertStringContainsString('noindex', $advanced);

        $search = $this->list(['q' => 'PYTHON']);
        $this->assertStringContainsString('Python basics', $search);
        $this->assertStringNotContainsString('Linux for professionals', $search);
        $this->assertStringContainsString('Clear filters', $search);

        $none = $this->list(['q' => '100%_', 'show' => 'paid']);
        $this->assertStringContainsString('No courses match', $none, 'wildcards are searched as text');

        $ignored = $this->list(['show' => 'everything', 'level' => 'expert']);
        $this->assertStringContainsString('Python basics', $ignored);
        $this->assertStringContainsString('Linux for professionals', $ignored);
        $this->assertStringContainsString('Free', $this->list(), 'free courses say so on their card');
    }

    public function testOnlyFreeCoursesCanBeOpenAndAuthorsDecide(): void
    {
        $this->as('teacher@school.test');
        $paidId = $this->paid['course']->id;
        $refused = $this->post(self::HOST, '/teach/courses/' . $paidId . '/access', ['access' => 'open']);
        $this->assertSame(422, $refused->status);
        $this->assertStringContainsString('Only free courses can be open', $refused->body);

        $membersId = $this->members['course']->id;
        $this->assertRedirectTo('/teach/courses/' . $membersId, $this->post(self::HOST, '/teach/courses/' . $membersId . '/access', ['access' => 'open']));
        $this->assertSame('open', (string) $this->db()->fetchValue('SELECT access FROM courses WHERE id = ?', [$membersId]));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'course.access_changed'"));
        $this->assertSame(422, $this->post(self::HOST, '/teach/courses/' . $membersId . '/access', ['access' => 'public'])->status);

        $this->as('owner@school.test');
        $price = $this->post(self::HOST, '/teach/courses/' . $membersId . '/price', ['price' => '5000', 'currency' => 'NGN']);
        $this->assertSame(422, $price->status, 'an open course cannot be given a price');
        $this->assertStringContainsString('open to everyone, so it is free', $price->body);

        $this->as('learner@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/teach/courses/' . $membersId . '/access', ['access' => 'members'])->status);
    }

    public function testSignedInLearnersCanReadBeforeJoiningAndAreSentToTheRightPlace(): void
    {
        $this->as('learner@school.test');
        $reading = $this->open['reading']->id;
        $this->assertRedirectTo('/courses/python-basics/preview/' . $reading, $this->get(self::HOST, '/courses/python-basics/lessons/' . $reading));
        $preview = $this->get(self::HOST, '/courses/python-basics/preview/' . $reading)->body;
        $this->assertStringContainsString('Enrol and continue', $preview);
        $this->assertStringContainsString('start reading</a> without enrolling', $this->get(self::HOST, '/courses/python-basics')->body);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments'), 'reading does not enrol anyone');

        $this->assertRedirectTo('/courses/python-basics#start', $this->get(self::HOST, '/courses/python-basics/lessons/' . $this->open['quiz']->id));

        $this->post(self::HOST, '/courses/python-basics/enrol');
        $this->assertRedirectTo('/courses/python-basics/lessons/' . $reading, $this->get(self::HOST, '/courses/python-basics/preview/' . $reading));
    }

    public function testVisitorsAreInvitedToSignUpWhenTheSchoolAllowsIt(): void
    {
        $this->assertStringContainsString('Sign in to join', $this->get(self::HOST, '/courses/python-basics/preview/' . $this->open['reading']->id)->body);

        $this->app->get(TenantRepository::class)->updateJoining($this->tenant->id, 'open', null, true);
        $lesson = $this->get(self::HOST, '/courses/python-basics/preview/' . $this->open['reading']->id)->body;
        $this->assertStringContainsString('Create a free account', $lesson);
        $this->assertStringContainsString('href="/join"', $lesson);
        $this->assertStringContainsString('Create an account to enrol', $this->get(self::HOST, '/courses/school-maths')->body);
    }

    public function testTeachersPreviewAnyLessonFromTheCoursePage(): void
    {
        $this->as('teacher@school.test');
        $page = $this->get(self::HOST, '/courses/school-maths')->body;
        $this->assertStringContainsString('You teach here', $page);
        $quizPreview = '/teach/courses/' . $this->members['course']->id . '/lessons/' . $this->members['quiz']->id . '/preview';
        $this->assertStringContainsString('href="' . $quizPreview . '"', $page);
        $this->assertSame(200, $this->get(self::HOST, $quizPreview)->status);
    }
}
