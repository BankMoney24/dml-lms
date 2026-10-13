<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Classes, cohorts or groups: who sees them, enrolment through them, progress and the learner's view (ADR 0013). */
final class GroupsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $teacher;
    private User $amaka;
    private User $bayo;
    private Course $physics;
    private Course $chemistry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->db()->execute("UPDATE tenants SET kind = 'school', levels = ? WHERE id = ?", [json_encode(['SS 1', 'SS 2']), $this->tenant->id]);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'other-teacher@school.test', Role::Educator);
        $this->amaka = $this->makeUser($this->tenant, 'amaka@school.test', Role::Learner);
        $this->bayo = $this->makeUser($this->tenant, 'bayo@school.test', Role::Learner);
        $this->physics = $this->makeCourse($this->tenant, $this->teacher, 'physics')['course'];
        $this->chemistry = $this->makeCourse($this->tenant, $this->teacher, 'chemistry')['course'];
    }

    private function signIn(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    /** Creates "SS 2 Gold" as the admin and returns its id. */
    private function createGroup(string $name = 'SS 2 Gold'): int
    {
        $this->signIn('admin@school.test');
        $response = $this->post(self::HOST, '/groups', ['name' => $name, 'level' => 'SS 2', 'period' => '2026/2027']);
        $this->assertSame(303, $response->status);
        $this->assertSame(1, preg_match('#^/groups/(\d+)$#', (string) $response->header('Location'), $m));

        return (int) ($m[1] ?? 0);
    }

    private function enrolled(User $user, Course $course): bool
    {
        return $this->db()->fetchValue('SELECT 1 FROM enrolments WHERE user_id = ? AND course_id = ?', [$user->id, $course->id]) !== null;
    }

    public function testTheSchoolsWordIsUsedAndOnlyStaffSeeTheList(): void
    {
        $this->signIn('admin@school.test');
        $page = $this->get(self::HOST, '/groups')->body;
        $this->assertStringContainsString('<h1>Classes</h1>', $page, 'a school says class');
        $this->assertStringContainsString('href="/groups"', $page, 'in the navigation');

        $this->db()->execute("UPDATE tenants SET group_term = 'cohort' WHERE id = ?", [$this->tenant->id]);
        $this->assertStringContainsString('<h1>Cohorts</h1>', $this->get(self::HOST, '/groups')->body);

        $this->signIn('amaka@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/groups')->status);
        $this->signIn('teacher@school.test');
        $this->assertStringContainsString('You are not teaching a cohort yet', $this->get(self::HOST, '/groups')->body);
        $this->assertSame(403, $this->post(self::HOST, '/groups', ['name' => 'Mine'])->status, 'only administrators create groups');
    }

    public function testCreatingValidatesTheName(): void
    {
        $this->createGroup();
        $this->assertSame(422, $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold'])->status, 'names are unique');
        $this->assertSame(422, $this->post(self::HOST, '/groups', ['name' => '   '])->status);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM learner_groups'));
    }

    public function testLearnersAreEnrolledInTheGroupsCoursesWhenEitherIsAdded(): void
    {
        $id = $this->createGroup();
        $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->physics->id, 'due_on' => '2026-11-30']);

        $added = $this->post(self::HOST, '/groups/' . $id . '/learners', [
            'emails' => "amaka@school.test\nnobody@school.test, teacher@school.test",
            'learners' => [(string) $this->bayo->id],
        ]);
        $this->assertRedirectTo('/groups/' . $id, $added);
        $this->assertTrue($this->enrolled($this->amaka, $this->physics));
        $this->assertTrue($this->enrolled($this->bayo, $this->physics));
        $page = $this->get(self::HOST, '/groups/' . $id)->body;
        $this->assertStringContainsString('2 new course enrolments made', $page);
        $this->assertStringContainsString('nobody@school.test: no one with this email', $page);
        $this->assertStringContainsString('is staff here, not a learner', $page);
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM learner_group_members WHERE role = 'learner'"));

        // A course set later reaches everyone already in the group.
        $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->chemistry->id]);
        $this->assertTrue($this->enrolled($this->amaka, $this->chemistry));
        $this->assertTrue($this->enrolled($this->bayo, $this->chemistry));

        // Removing a learner or a course keeps enrolments and progress.
        $this->post(self::HOST, '/groups/' . $id . '/learners/' . $this->bayo->id . '/remove');
        $this->post(self::HOST, '/groups/' . $id . '/courses/' . $this->chemistry->id . '/remove');
        $this->assertTrue($this->enrolled($this->bayo, $this->chemistry));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM learner_group_members WHERE role = 'learner'"));
    }

    public function testTeachersSeeOnlyTheirOwnGroups(): void
    {
        $id = $this->createGroup();
        $other = $this->createGroup('SS 1 Blue');

        $this->signIn('teacher@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/groups/' . $id)->status, 'not their group yet');

        $this->signIn('admin@school.test');
        $this->assertRedirectTo('/groups/' . $id, $this->post(self::HOST, '/groups/' . $id . '/teachers', ['teachers' => [(string) $this->teacher->id]]));
        $this->assertSame(422, $this->post(self::HOST, '/groups/' . $id . '/teachers', ['teachers' => [(string) $this->amaka->id]])->status, 'learners cannot teach');

        $this->signIn('teacher@school.test');
        $list = $this->get(self::HOST, '/groups')->body;
        $this->assertStringContainsString('SS 2 Gold', $list);
        $this->assertStringNotContainsString('SS 1 Blue', $list);
        $this->assertSame(200, $this->get(self::HOST, '/groups/' . $id)->status);
        $this->assertSame(404, $this->get(self::HOST, '/groups/' . $other)->status);
        $this->assertSame(404, $this->post(self::HOST, '/groups/' . $other . '/learners', ['emails' => 'amaka@school.test'])->status);
        $this->assertSame(403, $this->post(self::HOST, '/groups/' . $id . '/details', ['name' => 'Renamed'])->status, 'details are for administrators');

        // A teacher can add learners and set free courses for their own group.
        $this->post(self::HOST, '/groups/' . $id . '/learners', ['emails' => 'amaka@school.test']);
        $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->physics->id]);
        $this->assertTrue($this->enrolled($this->amaka, $this->physics));
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('Your classes', $home, 'on the teacher home page');
        $this->assertStringContainsString('1 learner, 1 course', $home);

        // Another school cannot see it at all.
        $elsewhere = $this->makeTenant('elsewhere', 'elsewhere.test');
        $this->makeUser($elsewhere, 'head@elsewhere.test', Role::Admin);
        $this->newBrowser();
        $this->login('elsewhere.test', 'head@elsewhere.test', 'correct-horse-battery');
        $this->assertSame(404, $this->get('elsewhere.test', '/groups/' . $id)->status);
    }

    public function testPaidCoursesAreSetByAdministratorsAndCoveredByTheSchool(): void
    {
        $this->app->get(CourseRepository::class)->updatePrice($this->tenant->id, $this->chemistry->id, 1500000, 'NGN');
        $id = $this->createGroup();
        $this->post(self::HOST, '/groups/' . $id . '/teachers', ['teachers' => [(string) $this->teacher->id]]);
        $this->post(self::HOST, '/groups/' . $id . '/learners', ['emails' => 'amaka@school.test']);

        $this->signIn('teacher@school.test');
        $refused = $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->chemistry->id]);
        $this->assertSame(422, $refused->status);
        $this->assertStringContainsString('Ask an administrator', $refused->body);

        $this->signIn('admin@school.test');
        $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->chemistry->id]);
        $this->assertTrue($this->enrolled($this->amaka, $this->chemistry), 'no payment needed');
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM payments'));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'learning.enrolled_by_group'"));
    }

    public function testProgressSideBySideAndTheLearnersView(): void
    {
        $id = $this->createGroup();
        $this->post(self::HOST, '/groups/' . $id . '/learners', ['emails' => 'amaka@school.test, bayo@school.test']);
        $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->physics->id, 'due_on' => '2026-01-15']);
        $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->chemistry->id]);
        $this->assertSame(422, $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->chemistry->id, 'due_on' => '31/02/2026'])->status);
        $this->db()->execute("UPDATE users SET display_name = '=cmd()' WHERE id = ?", [$this->bayo->id]);

        $this->signIn('amaka@school.test');
        $reading = (int) $this->db()->fetchValue("SELECT MIN(id) FROM lessons WHERE course_id = ? AND type = 'text'", [$this->physics->id]);
        $this->post(self::HOST, '/courses/physics/lessons/' . $reading . '/complete');
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('Your class: SS 2 Gold', $home);
        $this->assertStringContainsString('Was due 15 Jan', $home, 'past the due date');
        $this->assertStringContainsString('1 of 2 lessons', $home);
        $this->assertStringNotContainsString('bayo@school.test', $home, 'learners never see classmates');

        $this->signIn('admin@school.test');
        $page = $this->get(self::HOST, '/groups/' . $id)->body;
        $this->assertStringContainsString('1 of 2', $page);
        $this->assertStringContainsString('Past due', $page);
        $csv = $this->get(self::HOST, '/groups/' . $id . '/progress.csv');
        $this->assertSame('text/csv; charset=utf-8', $csv->header('Content-Type'));
        $this->assertStringContainsString('SS-2-Gold-progress.csv', (string) $csv->header('Content-Disposition'));
        $this->assertStringContainsString("'=cmd()", $csv->body);
        $this->assertStringContainsString('1 of 2', $csv->body);
    }

    public function testArchivingHidesTheGroupAndFreezesIt(): void
    {
        $id = $this->createGroup();
        $this->post(self::HOST, '/groups/' . $id . '/learners', ['emails' => 'amaka@school.test']);
        $this->assertRedirectTo('/groups', $this->post(self::HOST, '/groups/' . $id . '/archive'));
        $this->assertStringNotContainsString('href="/groups/' . $id . '"', $this->get(self::HOST, '/groups')->body);
        $this->assertStringContainsString('href="/groups/' . $id . '"', $this->send(new Request('GET', '/groups', self::HOST, ['archived' => '1']))->body);
        $this->assertSame(422, $this->post(self::HOST, '/groups/' . $id . '/learners', ['emails' => 'bayo@school.test'])->status);

        $this->signIn('amaka@school.test');
        $this->assertStringNotContainsString('SS 2 Gold', $this->get(self::HOST, '/')->body);

        $this->signIn('admin@school.test');
        $this->post(self::HOST, '/groups/' . $id . '/archive', ['restore' => '1']);
        $this->assertNull($this->db()->fetchValue('SELECT archived_at FROM learner_groups'));
    }
}
