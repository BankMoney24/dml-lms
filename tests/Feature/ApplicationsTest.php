<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0025: applying for a place, the waiting list, and offering places. */
final class ApplicationsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private Course $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->course = $this->makeCourse($this->tenant, $teacher, 'data-skills')['course'];
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    private function takesApplications(?int $capacity = null, string $mode = 'apply'): void
    {
        $this->app->get(CourseRepository::class)->updateEnrolment($this->tenant->id, $this->course->id, $mode, $capacity);
    }

    private function status(string $email): ?string
    {
        $v = $this->db()->fetchValue('SELECT status FROM course_applications WHERE email = ? ORDER BY id DESC LIMIT 1', [$email]);

        return $v === null ? null : (string) $v;
    }

    private function enrolled(string $email): bool
    {
        return $this->db()->fetchValue('SELECT 1 FROM enrolments e JOIN users u ON u.id = e.user_id WHERE u.email = ?', [$email]) !== null;
    }

    private function lastId(): int
    {
        return (int) $this->db()->fetchValue('SELECT MAX(id) FROM course_applications');
    }

    public function testTeachersChooseHowPeopleJoin(): void
    {
        $this->as('teacher@school.test');
        $this->assertSame(422, $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/enrolment', ['enrolment' => 'apply', 'capacity' => 'lots'])->status);
        $this->assertRedirectTo('/teach/courses/' . $this->course->id, $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/enrolment', ['enrolment' => 'apply', 'capacity' => '25']));
        $row = $this->db()->fetchOne('SELECT enrolment, capacity FROM courses WHERE id = ?', [$this->course->id]) ?? [];
        $this->assertSame(['enrolment' => 'apply', 'capacity' => 25], ['enrolment' => (string) ($row['enrolment'] ?? ''), 'capacity' => (int) ($row['capacity'] ?? 0)]);

        $this->as('ada@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/teach/courses/' . $this->course->id . '/enrolment', ['enrolment' => 'open'])->status);
        $this->assertSame(403, $this->get(self::HOST, '/teach/applications')->status);
    }

    public function testVisitorsApplyAndAreGivenAnAccountWithTheirPlace(): void
    {
        $this->takesApplications();
        $page = $this->get(self::HOST, '/courses/data-skills')->body;
        $this->assertStringContainsString('Apply for a place', $page);
        $this->assertStringContainsString('action="/courses/data-skills/apply"', $page);

        $bad = $this->post(self::HOST, '/courses/data-skills/apply', ['name' => 'Chidi Okafor', 'email' => 'not-an-email', 'phone' => '', 'message' => '']);
        $this->assertRedirectTo('/courses/data-skills#apply', $bad);
        $again = $this->get(self::HOST, '/courses/data-skills')->body;
        $this->assertStringContainsString('Enter an email address the school can reach you on.', $again);
        $this->assertStringContainsString('value="Chidi Okafor"', $again, 'what was typed comes back');

        $form = ['name' => 'Chidi Okafor', 'email' => 'Chidi@Example.test', 'phone' => '0803 123 4567', 'message' => 'I work in a bank and want to learn data.', 'agree' => '1'];
        $this->post(self::HOST, '/courses/data-skills/apply', $form);
        $this->assertSame('waiting', $this->status('chidi@example.test'));
        $this->assertStringContainsString('Application received', $this->get(self::HOST, '/courses/data-skills')->body, 'no second form on this browser');
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'application.new'"), 'the owner and the course author');
        $this->post(self::HOST, '/courses/data-skills/apply', $form);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM course_applications'), 'no double applications');
        $this->post(self::HOST, '/courses/data-skills/apply', ['website' => 'spam'] + $form + ['email' => 'bot@example.test']);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM course_applications'), 'the hidden field catches bots');
        $id = $this->lastId();

        // A teacher cannot create accounts; an owner can.
        $this->as('teacher@school.test');
        $this->assertStringContainsString('Chidi Okafor', $this->get(self::HOST, '/teach/applications')->body);
        $this->post(self::HOST, '/teach/applications/' . $id . '/offer');
        $this->assertSame('waiting', $this->status('chidi@example.test'));

        $this->as('owner@school.test');
        $this->post(self::HOST, '/teach/applications/' . $id . '/offer');
        $this->assertSame('enrolled', $this->status('chidi@example.test'), 'a free course starts at once');
        $this->assertTrue($this->enrolled('chidi@example.test'));
        $this->assertStringContainsString('one-time password', $this->get(self::HOST, '/teach/applications?show=decided')->body);
        $role = $this->db()->fetchValue("SELECT m.role FROM memberships m JOIN users u ON u.id = m.user_id WHERE u.email = 'chidi@example.test'");
        $this->assertSame('learner', (string) $role);
    }

    public function testMembersWaitInLineAndCannotSkipIt(): void
    {
        $this->takesApplications();
        $this->as('ada@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/courses/data-skills/enrol')->status, 'no joining without a place');
        $this->post(self::HOST, '/courses/data-skills/apply', ['phone' => '', 'message' => 'Please']);
        $page = $this->get(self::HOST, '/courses/data-skills')->body;
        $this->assertStringContainsString('You are on the waiting list', $page);
        $this->assertMatchesRegularExpression('#Your place in line</span><strong>1</strong>#', $page);

        $this->as('tobi@school.test');
        $this->post(self::HOST, '/courses/data-skills/apply', ['phone' => '', 'message' => '']);
        $this->assertMatchesRegularExpression('#Your place in line</span><strong>2</strong>#', $this->get(self::HOST, '/courses/data-skills')->body);
        $tobi = $this->lastId();

        $this->as('ada@school.test');
        $ada = (int) $this->db()->fetchValue("SELECT id FROM course_applications WHERE email = 'ada@school.test'");
        $this->post(self::HOST, '/courses/data-skills/apply/withdraw', ['application' => (string) $ada]);
        $this->assertSame('withdrawn', $this->status('ada@school.test'));

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/applications/' . $tobi . '/offer');
        $this->assertSame('enrolled', $this->status('tobi@school.test'));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'application.decision'"));

        $this->as('tobi@school.test');
        $this->assertStringContainsString('lessons done', $this->get(self::HOST, '/courses/data-skills')->body);
    }

    public function testPaidPlacesAreOfferedThenPaidFor(): void
    {
        $this->takesApplications();
        $this->app->get(CourseRepository::class)->updatePrice($this->tenant->id, $this->course->id, 2000000, 'NGN');
        $this->as('ada@school.test');
        $this->assertRedirectTo('/courses/data-skills#apply', $this->post(self::HOST, '/courses/data-skills/pay'), 'no paying before a place');
        $this->post(self::HOST, '/courses/data-skills/apply', ['phone' => '', 'message' => '']);

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/applications/' . $this->lastId() . '/offer');
        $this->assertSame('offered', $this->status('ada@school.test'));
        $this->assertStringContainsString('Waiting for payment', $this->send(new \App\Core\Http\Request('GET', '/teach/applications', self::HOST, ['show' => 'offered']))->body);

        $this->as('ada@school.test');
        $this->assertStringContainsString('for the whole course', $this->get(self::HOST, '/courses/data-skills')->body, 'the pay box');
        $ada = $this->app->get(UserRepository::class)->findByEmail('ada@school.test');
        $this->assertNotNull($ada);
        $this->db()->execute(
            "INSERT INTO payments (tenant_id, user_id, course_id, reference, provider, mode, amount_minor, currency, status, created_at, updated_at, paid_at)
             VALUES (?, ?, ?, 'MAN-T-1', 'manual', 'live', 2000000, 'NGN', 'paid', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$this->tenant->id, $ada->id, $this->course->id],
        );
        $this->post(self::HOST, '/courses/data-skills/enrol');
        $this->assertTrue($this->enrolled('ada@school.test'));
        $this->assertSame('enrolled', $this->status('ada@school.test'));
    }

    public function testAFullCourseKeepsAWaitingList(): void
    {
        $this->takesApplications(1, 'open');
        $this->as('ada@school.test');
        $this->assertStringContainsString('1 place left', $this->get(self::HOST, '/courses/data-skills')->body);
        $this->post(self::HOST, '/courses/data-skills/enrol');
        $this->assertTrue($this->enrolled('ada@school.test'));

        $this->as('tobi@school.test');
        $page = $this->get(self::HOST, '/courses/data-skills')->body;
        $this->assertStringContainsString('This course is full', $page);
        $this->assertStringContainsString('Join the waiting list', $page);
        $this->assertSame(403, $this->post(self::HOST, '/courses/data-skills/enrol')->status);
        $this->post(self::HOST, '/courses/data-skills/apply', ['phone' => '', 'message' => '']);
        $this->assertSame('waiting', $this->status('tobi@school.test'));

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/teach/applications/' . $this->lastId() . '/decline');
        $this->assertSame('declined', $this->status('tobi@school.test'));
        $this->as('tobi@school.test');
        $this->assertStringContainsString('was not offered a place', $this->get(self::HOST, '/courses/data-skills')->body);
    }
}
