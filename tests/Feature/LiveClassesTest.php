<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Http\Request;
use App\Modules\Guardians\GuardianRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Platform\SampleClock;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AppTestCase;

/** ADR 0028: live classes, joining, the register, attendance, parents and the calendar. */
final class LiveClassesTest extends AppTestCase
{
    private const HOST = 'school.test';

    private SampleClock $clock;
    private Tenant $tenant;
    private User $amaka;
    private User $bayo;
    private int $group;

    protected function setUp(): void
    {
        $this->clock = new SampleClock();
        $this->clock->at(new DateTimeImmutable('2026-11-02 09:00:00', new DateTimeZone('UTC')));
        $this->overrides = [Clock::class => $this->clock];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $admin = $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'other-teacher@school.test', Role::Educator);
        $this->amaka = $this->makeUser($this->tenant, 'amaka@school.test', Role::Learner);
        $this->bayo = $this->makeUser($this->tenant, 'bayo@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'chidi@school.test', Role::Learner);
        $parent = $this->makeUser($this->tenant, 'parent@school.test', Role::Guardian);
        $this->app->get(GuardianRepository::class)->link($this->tenant->id, $parent->id, $this->bayo->id, 'mother', true, true, true, $admin->id);

        $this->as('admin@school.test');
        $made = $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold', 'level' => 'SS 2', 'period' => '2026']);
        $this->assertSame(1, preg_match('#^/groups/(\d+)$#', (string) $made->header('Location'), $m));
        $this->group = (int) ($m[1] ?? 0);
        $this->post(self::HOST, '/groups/' . $this->group . '/teachers', ['teachers' => [(string) $teacher->id]]);
        $this->post(self::HOST, '/groups/' . $this->group . '/learners', ['emails' => 'amaka@school.test bayo@school.test']);
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    /** @param array<string, string> $over */
    private function plan(array $over = []): \App\Core\Http\Response
    {
        return $this->post(self::HOST, '/groups/' . $this->group . '/classes', $over + [
            'class_title' => 'Week 1: networks', 'class_date' => '2026-11-02', 'class_time' => '16:00', 'class_minutes' => '60',
            'class_mode' => 'online', 'class_join_url' => 'https://meet.example.org/abc-defg-hij', 'class_location' => '', 'class_course_id' => '',
            'class_weeks' => '1', 'class_description' => 'Bring your questions.',
        ]);
    }

    private function lastSession(): int
    {
        return (int) $this->db()->fetchValue('SELECT MAX(id) FROM class_sessions');
    }

    private function timeIs(string $utc): void
    {
        $this->clock->at(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }

    private function joinAsJson(int $id): \App\Core\Http\Response
    {
        return $this->send(new Request('POST', '/classes/' . $id . '/join', self::HOST, [], ['_token' => $this->app->get(\App\Core\Security\Csrf::class)->token()], ['accept' => 'application/json']));
    }

    public function testTeachersPlanClassesInTheSchoolsTimeAndLearnersAreTold(): void
    {
        $this->as('teacher@school.test');
        $this->assertSame(422, $this->plan(['class_join_url' => 'javascript:alert(1)'])->status, 'only web addresses');
        $this->assertSame(422, $this->plan(['class_join_url' => 'https://localhost'])->status);
        $this->assertSame(422, $this->plan(['class_mode' => 'room', 'class_location' => ''])->status, 'a room needs a name');
        $this->assertSame(422, $this->plan(['class_weeks' => '16'])->status);
        $bad = $this->plan(['class_date' => '2026-02-30'])->body;
        $this->assertStringContainsString('That day does not exist', $bad);

        $this->assertRedirectTo('/groups/' . $this->group . '#classes', $this->plan(['class_weeks' => '3']));
        $rows = $this->db()->fetchAll('SELECT starts_at, ends_at, series_id FROM class_sessions ORDER BY starts_at');
        $this->assertCount(3, $rows);
        $this->assertSame(['2026-11-02 15:00:00', '2026-11-02 16:00:00'], [(string) $rows[0]['starts_at'], (string) $rows[0]['ends_at']], '16:00 in Lagos is 15:00 UTC');
        $this->assertSame('2026-11-16 15:00:00', (string) $rows[2]['starts_at'], 'weekly');
        $this->assertNotNull($rows[0]['series_id']);
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'class.scheduled'"), 'one notice per learner for the series');

        $this->as('other-teacher@school.test');
        $this->assertSame(404, $this->plan()->status, 'not their class');
        $this->as('amaka@school.test');
        $this->assertSame(404, $this->plan()->status);
        $this->assertSame(404, $this->get(self::HOST, '/groups/' . $this->group . '/attendance.csv')->status);
    }

    public function testLearnersJoinDuringTheClassAndAreMarkedPresentOrLate(): void
    {
        $this->as('teacher@school.test');
        $this->plan();
        $id = $this->lastSession();

        $this->as('amaka@school.test');
        $page = $this->get(self::HOST, '/classes')->body;
        $this->assertStringContainsString('Week 1: networks', $page);
        $this->assertStringContainsString('Mon 2 Nov, 16:00–17:00', $page, 'shown in Lagos time');
        $this->assertStringNotContainsString('meet.example.org', $this->get(self::HOST, '/classes/' . $id)->body, 'the link is not shown before it opens');
        $this->assertSame(409, $this->joinAsJson($id)->status, 'too early');

        $this->timeIs('2026-11-02 14:50:00');
        $this->assertStringContainsString('href="https://meet.example.org/abc-defg-hij"', $this->get(self::HOST, '/classes/' . $id)->body);
        $this->assertStringContainsString('Starting soon', $this->get(self::HOST, '/')->body, 'on the home page');
        $this->assertSame('{"marked":true,"status":"present"}', $this->joinAsJson($id)->body);
        $this->timeIs('2026-11-02 15:20:00');
        $this->joinAsJson($id);
        $this->assertSame('present', $this->db()->fetchValue('SELECT status FROM class_attendance WHERE user_id = ?', [$this->amaka->id]), 'joining again changes nothing');

        $this->as('bayo@school.test');
        $this->assertSame('{"marked":true,"status":"late"}', $this->joinAsJson($id)->body, 'more than 10 minutes after the start');

        $this->as('chidi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/classes/' . $id)->status, 'not in the class');
        $this->timeIs('2026-11-02 17:00:00');
        $this->as('amaka@school.test');
        $this->assertSame(409, $this->joinAsJson($id)->status, 'ended');
    }

    public function testTheRegisterTellsParentsAboutAbsenceOnce(): void
    {
        $this->as('teacher@school.test');
        $this->plan();
        $id = $this->lastSession();
        $this->post(self::HOST, '/classes/' . $id . '/register', ['status' => [(string) $this->amaka->id => 'present']]);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM class_attendance'), 'not before it starts');

        $this->timeIs('2026-11-02 16:05:00');
        $register = $this->get(self::HOST, '/classes/' . $id)->body;
        $this->assertStringContainsString('name="status[' . $this->bayo->id . ']"', $register);
        $chidi = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'chidi@school.test'");
        $this->post(self::HOST, '/classes/' . $id . '/register', [
            'status' => [(string) $this->amaka->id => 'present', (string) $this->bayo->id => 'absent', (string) $chidi => 'present'],
            'note' => [(string) $this->bayo->id => 'No word from home'],
        ]);
        $this->assertSame(2, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM class_attendance'), 'only learners in the class');
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'class.absent'"));
        $this->post(self::HOST, '/classes/' . $id . '/register', ['status' => [(string) $this->bayo->id => 'absent'], 'note' => [(string) $this->bayo->id => 'No word from home']]);
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'class.absent'"), 'not again for the same mark');

        $csv = $this->get(self::HOST, '/groups/' . $this->group . '/attendance.csv')->body;
        $this->assertMatchesRegularExpression('/bayo@school\.test,1,0,0,1,0,0,0/', $csv);

        $this->as('parent@school.test');
        $child = $this->get(self::HOST, '/children/' . $this->bayo->id)->body;
        $this->assertStringContainsString('Live classes and attendance', $child);
        $this->assertMatchesRegularExpression('#<strong>0%</strong>#', $child);

        $this->as('bayo@school.test');
        $this->assertStringContainsString('No word from home', $this->get(self::HOST, '/classes/' . $id)->body, 'learners see their own mark');
    }

    public function testCancellingTellsLearnersAndStopsJoining(): void
    {
        $this->as('teacher@school.test');
        $this->plan();
        $id = $this->lastSession();
        $this->assertSame(422, $this->post(self::HOST, '/classes/' . $id . '/cancel', ['reason' => ''])->status);
        $this->post(self::HOST, '/classes/' . $id . '/cancel', ['reason' => 'The teacher is unwell.']);
        $this->assertSame('cancelled', $this->db()->fetchValue('SELECT status FROM class_sessions WHERE id = ?', [$id]));
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'class.cancelled'"));

        $this->timeIs('2026-11-02 15:05:00');
        $this->as('amaka@school.test');
        $this->assertSame(409, $this->joinAsJson($id)->status);
        $this->assertStringContainsString('The teacher is unwell.', $this->get(self::HOST, '/classes/' . $id)->body);
    }

    public function testClassesAreInTheCalendarFeed(): void
    {
        $this->as('teacher@school.test');
        $this->plan();
        $this->as('amaka@school.test');
        $page = $this->post(self::HOST, '/account/calendar');
        $this->assertSame(1, preg_match('#/calendar/([0-9a-f]{40})\.ics#', $page->body, $m));
        $feed = $this->get(self::HOST, '/calendar/' . ($m[1] ?? '') . '.ics')->body;
        $this->assertStringContainsString('DTSTART:20261102T150000Z', $feed);
        $this->assertStringContainsString('SUMMARY:Week 1: networks (SS 2 Gold)', $feed);
        $this->assertStringContainsString('LOCATION:Online', $feed);
    }
}
