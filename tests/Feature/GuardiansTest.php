<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Parents and guardians: verified links, record-level permissions, read-only views (PAR-001..004). */
final class GuardiansTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $tobi;
    private User $ada;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->tobi = $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->ada = $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->course = $this->makeCourse($this->tenant, $teacher, 'physics');

        // Tobi has done some work: the reading and a quiz attempt.
        $this->signIn('tobi@school.test');
        $this->post(self::HOST, '/courses/physics/enrol');
        $this->post(self::HOST, '/courses/physics/lessons/' . $this->course['reading']->id . '/complete');
        $this->post(self::HOST, '/courses/physics/lessons/' . $this->course['quiz']->id . '/attempts', [
            'answers' => $this->answers($this->course['assessment_id'], true), 'idempotency_key' => str_repeat('a', 32),
        ]);
    }

    private function signIn(string $email, string $password = 'correct-horse-battery'): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, $password);
    }

    /**
     * Links mum@school.test to Tobi as the admin and returns her one-time password.
     *
     * @param array<string, string> $may
     */
    private function linkMum(array $may = ['progress' => '1', 'results' => '1', 'notices' => '1']): string
    {
        $this->signIn('admin@school.test');
        $response = $this->post(self::HOST, '/people/' . $this->tobi->id . '/guardians', [
            'name' => 'Funke Bello', 'email' => 'mum@school.test', 'relationship' => 'mother',
        ] + $may);
        $this->assertRedirectTo('/people/' . $this->tobi->id . '/guardians', $response);
        $page = $this->get(self::HOST, '/people/' . $this->tobi->id . '/guardians')->body;
        $this->assertSame(1, preg_match('#<span class="secret">([^<]+)</span>#', $page, $m), 'one-time password shown once');

        return html_entity_decode($m[1] ?? '');
    }

    public function testAnAdministratorLinksAGuardianWhoSeesOnlyTheirChild(): void
    {
        $password = $this->linkMum();
        $this->assertSame('guardian', $this->db()->fetchValue("SELECT m.role FROM memberships m JOIN users u ON u.id = m.user_id WHERE u.email = 'mum@school.test'"));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'guardian.linked'"));
        $this->assertNotNull($this->db()->fetchValue('SELECT verified_by FROM guardian_links'));
        $this->assertStringContainsString('1 parent or guardian', $this->get(self::HOST, '/people')->body);

        $this->signIn('mum@school.test', $password);
        $this->post(self::HOST, '/account/password', ['current_password' => $password, 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase']);
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('Your child', $home);
        $this->assertStringContainsString('href="/children/' . $this->tobi->id . '"', $home);
        $this->assertRedirectTo('/children/' . $this->tobi->id, $this->get(self::HOST, '/children'));

        $child = $this->get(self::HOST, '/children/' . $this->tobi->id)->body;
        $this->assertStringContainsString('You are Test\'s mother', str_replace('&#039;', "'", $child));
        $this->assertStringContainsString('<span class="set-due">Completed</span>', $child, 'both lessons done');
        $this->assertStringContainsString('100%', $child, 'best quiz score');
        $this->assertStringContainsString('Passed', $child);

        $this->assertSame(404, $this->get(self::HOST, '/children/' . $this->ada->id)->status, 'other learners are not visible');
        $this->assertSame(403, $this->get(self::HOST, '/courses/physics/lessons/' . $this->course['reading']->id)->status, 'guardians do not open lessons');
        $this->assertSame(403, $this->get(self::HOST, '/people')->status);
    }

    public function testEachLinkSaysWhatTheGuardianMaySee(): void
    {
        $password = $this->linkMum(['progress' => '1']);
        $this->signIn('mum@school.test', $password);
        $this->post(self::HOST, '/account/password', ['current_password' => $password, 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase']);
        $child = $this->get(self::HOST, '/children/' . $this->tobi->id)->body;
        $this->assertStringContainsString('Course school', $child);
        $this->assertStringNotContainsString('Best score', $child, 'results not shared');
        $this->assertStringContainsString('Quiz and exam scores, and assignment results: no', $child);

        $this->signIn('admin@school.test');
        $link = (int) $this->db()->fetchValue('SELECT id FROM guardian_links');
        $this->post(self::HOST, '/people/' . $this->tobi->id . '/guardians/' . $link, ['results' => '1']);
        $this->signIn('mum@school.test', 'a brand new passphrase');
        $child = $this->get(self::HOST, '/children/' . $this->tobi->id)->body;
        $this->assertStringContainsString('Best score', $child);
        $this->assertStringNotContainsString('id="courses-h"', $child, 'progress switched off');

        $this->signIn('admin@school.test');
        $this->post(self::HOST, '/people/' . $this->tobi->id . '/guardians/' . $link . '/revoke');
        $this->signIn('mum@school.test', 'a brand new passphrase');
        $this->assertSame(404, $this->get(self::HOST, '/children/' . $this->tobi->id)->status);
        $this->assertSame(404, $this->get(self::HOST, '/children')->status);
    }

    public function testOnlyMemberManagersLinkAndOnlyToLearners(): void
    {
        $this->signIn('teacher@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/people/' . $this->tobi->id . '/guardians')->status);

        $this->signIn('admin@school.test');
        $teacherId = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'teacher@school.test'");
        $this->assertSame(404, $this->get(self::HOST, '/people/' . $teacherId . '/guardians')->status, 'teachers have no guardians here');
        $self = $this->post(self::HOST, '/people/' . $this->tobi->id . '/guardians', ['name' => 'Tobi', 'email' => 'tobi@school.test', 'relationship' => 'other']);
        $this->assertSame(422, $self->status);
        $this->assertSame(422, $this->post(self::HOST, '/people/' . $this->tobi->id . '/guardians', ['name' => 'X', 'email' => 'x@school.test', 'relationship' => 'cousin'])->status);

        // A member who is also a parent is linked to their existing account, keeping their role.
        $this->post(self::HOST, '/people/' . $this->tobi->id . '/guardians', ['name' => 'Ignored', 'email' => 'teacher@school.test', 'relationship' => 'father', 'progress' => '1']);
        $this->assertSame('educator', $this->db()->fetchValue('SELECT role FROM memberships WHERE user_id = ?', [$teacherId]));
        $this->signIn('teacher@school.test');
        $this->assertSame(200, $this->get(self::HOST, '/children/' . $this->tobi->id)->status);

        // When the learner leaves the school, nothing is shown any more.
        $this->db()->execute("UPDATE memberships SET status = 'removed' WHERE user_id = ?", [$this->tobi->id]);
        $this->assertSame(404, $this->get(self::HOST, '/children/' . $this->tobi->id)->status);
    }

    public function testGuardiansHearClassNewsOnlyWhenAllowed(): void
    {
        $this->linkMum(['progress' => '1', 'notices' => '1']);
        $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold']);
        $group = (int) $this->db()->fetchValue('SELECT id FROM learner_groups');
        $this->post(self::HOST, '/groups/' . $group . '/learners', ['emails' => 'tobi@school.test']);
        $this->post(self::HOST, '/groups/' . $group . '/announcements', ['announcement' => 'Lab day on Friday.']);

        $mum = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'mum@school.test'");
        $this->assertSame(['News from Test\'s group'], array_map('strval', array_column($this->db()->fetchAll('SELECT title FROM notifications WHERE user_id = ?', [$mum]), 'title')));

        $link = (int) $this->db()->fetchValue('SELECT id FROM guardian_links');
        $this->post(self::HOST, '/people/' . $this->tobi->id . '/guardians/' . $link, ['progress' => '1']);
        $this->post(self::HOST, '/groups/' . $group . '/announcements', ['announcement' => 'Second note.']);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ?', [$mum]), 'notices switched off');
    }

    private function mumSignsIn(string $password): void
    {
        $this->signIn('mum@school.test', $password);
        $this->post(self::HOST, '/account/password', ['current_password' => $password, 'new_password' => 'a brand new passphrase', 'confirm_password' => 'a brand new passphrase']);
    }

    public function testParentsAndStaffPrintAReportWithOnlyWhatTheyMaySee(): void
    {
        $this->mumSignsIn($this->linkMum(['progress' => '1', 'results' => '1']));
        $report = $this->get(self::HOST, '/children/' . $this->tobi->id . '/report')->body;
        $this->assertStringContainsString('Learner report', $report);
        $this->assertStringContainsString('Completed', $report, 'Tobi finished the course');
        $this->assertStringContainsString('Quiz of school', $report);
        $this->assertStringContainsString('100%', $report);
        $this->assertStringContainsString('Lessons finished in this period</dt><dd>2</dd>', $report);
        $this->assertStringContainsString('data-print', $report);

        // An earlier period has none of this term's lessons.
        $old = $this->get(self::HOST, '/children/' . $this->tobi->id . '/report?from=2000-01-01&to=2000-03-01')->body;
        $this->assertStringContainsString('Lessons finished in this period</dt><dd>0</dd>', $old);
        $this->assertStringContainsString('at most 400 days', $this->get(self::HOST, '/children/' . $this->tobi->id . '/report?from=2000-01-01')->body);

        // Not her child: nothing.
        $this->assertSame(404, $this->get(self::HOST, '/children/' . $this->ada->id . '/report')->status);
        $this->assertSame(403, $this->get(self::HOST, '/people/' . $this->tobi->id . '/report')->status);

        $this->signIn('admin@school.test');
        $staff = $this->get(self::HOST, '/people/' . $this->tobi->id . '/report')->body;
        $this->assertStringContainsString('Quiz results', $staff);
        $teacherId = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'teacher@school.test'");
        $this->assertSame(404, $this->get(self::HOST, '/people/' . $teacherId . '/report')->status, 'reports are for learners');
    }

    public function testAParentWithoutResultsSeesProgressOnly(): void
    {
        $this->mumSignsIn($this->linkMum(['progress' => '1']));
        $report = $this->get(self::HOST, '/children/' . $this->tobi->id . '/report')->body;
        $this->assertStringContainsString('Courses', $report);
        $this->assertStringNotContainsString('Quiz results', $report);
        $this->assertStringNotContainsString('Quiz average', $report);
    }
}
