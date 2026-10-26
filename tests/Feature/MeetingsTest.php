<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Console\ConsoleApplication;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Modules\Guardians\GuardianRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\LiveClasses\Classroom\JitsiToken;
use App\Modules\Platform\SampleClock;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AppTestCase;

/** ADR 0030: meetings, starting a class now, lecture mode, running the room, and connecting from the console. */
final class MeetingsTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const SECRET = '9c2f4b8e1a7d3c5f0e6b2a9d4c8f1e3b7a5d0c2e9f4b6a8d1c3e5f7a9b0d2c4e';

    private SampleClock $clock;
    private Tenant $tenant;
    private User $bayo;
    private int $group;

    protected function setUp(): void
    {
        $this->clock = new SampleClock();
        $this->timeIs('2026-11-02 09:00:00');
        $this->overrides = [Clock::class => $this->clock];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $admin = $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'other-teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'amaka@school.test', Role::Learner);
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

    private function timeIs(string $utc): void
    {
        $this->clock->at(new DateTimeImmutable($utc, new DateTimeZone('UTC')));
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    private function connect(): void
    {
        $this->as('admin@school.test');
        $this->post(self::HOST, '/school/classroom', ['domain' => 'live.school.test', 'app_id' => 'dml-lms', 'secret' => self::SECRET, 'low_data' => '1']);
    }

    /** @param array<string, mixed> $over */
    private function meet(array $over = []): Response
    {
        return $this->post(self::HOST, '/meetings', $over + [
            'title' => 'SS 2 parents’ evening', 'agenda' => 'Term results.', 'when' => 'now', 'date' => '', 'time' => '', 'minutes' => '30',
            'staff_moderate' => '1', 'staff' => '', 'groups' => [], 'parents' => [(string) $this->group], 'emails' => 'other-teacher@school.test',
        ]);
    }

    private function lastMeeting(): int
    {
        return (int) $this->db()->fetchValue('SELECT MAX(id) FROM meetings');
    }

    /** @return array<string, mixed> */
    private function passOn(string $page): array
    {
        $this->assertSame(1, preg_match('/data-jwt="([^"]+)"/', $page, $m), 'the page carries a pass');

        return JitsiToken::verify(html_entity_decode($m[1] ?? ''), self::SECRET) ?? [];
    }

    /** @param array<string, string> $body */
    private function json(string $path, array $body): Response
    {
        return $this->send(new Request('POST', $path, self::HOST, [], $body + ['_token' => $this->app->get(Csrf::class)->token()], ['accept' => 'application/json']));
    }

    public function testStaffMeetNowAndOnlyThePeopleInvitedGetIn(): void
    {
        $this->as('teacher@school.test');
        $this->assertSame(422, $this->meet()->status, 'not before the classroom is connected');
        $this->connect();

        $this->as('amaka@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/meetings/new')->status, 'learners do not host');
        $this->assertSame(404, $this->meet()->status);

        $this->as('teacher@school.test');
        $this->assertStringContainsString('Not members of this school: stranger@else.test', $this->meet(['emails' => 'stranger@else.test'])->body);
        $this->assertStringContainsString('Invite at least one person', $this->meet(['parents' => [], 'emails' => ''])->body);
        $this->assertRedirectTo('/meetings/' . ($this->lastMeeting() + 1) . '/room', $this->meet());
        $id = $this->lastMeeting();
        $this->assertSame(2, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM meeting_invites WHERE meeting_id = ?', [$id]), 'the parent and the other teacher');
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'meeting.live'"));
        $this->assertMatchesRegularExpression('/^dmlm-[0-9a-f]{24}$/', (string) $this->db()->fetchValue('SELECT room_name FROM meetings WHERE id = ?', [$id]));

        $room = $this->get(self::HOST, '/meetings/' . $id . '/room');
        $this->assertSame(200, $room->status);
        $this->assertTrue($this->passOn($room->body)['moderator'] ?? null);
        $this->assertStringContainsString('frame-src https://live.school.test', (string) $room->header('Content-Security-Policy'));

        $this->as('parent@school.test');
        $this->assertFalse($this->passOn($this->get(self::HOST, '/meetings/' . $id . '/room')->body)['moderator'] ?? null, 'parents join as members');
        $this->assertStringContainsString('Meeting now', $this->get(self::HOST, '/')->body, 'on the parent’s home page');
        $this->as('other-teacher@school.test');
        $this->assertTrue($this->passOn($this->get(self::HOST, '/meetings/' . $id . '/room')->body)['moderator'] ?? null, 'other staff may run it');

        $this->as('amaka@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/meetings/' . $id)->status, 'not invited');
        $this->assertSame(404, $this->get(self::HOST, '/meetings/' . $id . '/room')->status);
        $this->assertSame(404, $this->json('/meetings/' . $id . '/signal', ['type' => 'join'])->status);
    }

    public function testPlannedMeetingsOpenOnTimeCountWhoCameAndCanBeRunByTheHost(): void
    {
        $this->connect();
        $this->as('teacher@school.test');
        $planned = $this->meet(['when' => 'later', 'date' => '2026-11-03', 'time' => '10:00', 'minutes' => '60', 'groups' => [(string) $this->group], 'parents' => [], 'emails' => '']);
        $id = $this->lastMeeting();
        $this->assertRedirectTo('/meetings/' . $id, $planned);
        $this->assertSame(['2026-11-03 09:00:00', '2026-11-03 10:00:00'], array_values($this->db()->fetchOne('SELECT starts_at, ends_at FROM meetings WHERE id = ?', [$id]) ?? []), '10:00 in Lagos');
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'meeting.invited'"), 'both learners; the host is not invited to their own meeting');

        $this->as('bayo@school.test');
        $early = $this->get(self::HOST, '/meetings/' . $id . '/room');
        $this->assertSame(409, $early->status);
        $this->assertStringContainsString('The meeting opens at <strong>09:45</strong>', $early->body);
        $page = $this->post(self::HOST, '/account/calendar');
        $this->assertSame(1, preg_match('#/calendar/([0-9a-f]{40})\.ics#', $page->body, $m));
        $this->assertStringContainsString('SUMMARY:SS 2 parents’ evening', $this->get(self::HOST, '/calendar/' . ($m[1] ?? '') . '.ics')->body);

        $this->timeIs('2026-11-03 09:01:00');
        $this->assertSame('{"ok":true}', $this->json('/meetings/' . $id . '/signal', ['type' => 'join'])->body);
        foreach (['09:02:05', '09:03:05', '09:04:05'] as $t) {
            $this->timeIs('2026-11-03 ' . $t);
            $this->json('/meetings/' . $id . '/signal', ['type' => 'here']);
        }
        $this->assertSame(404, $this->json('/meetings/' . $id . '/extend', ['minutes' => '30'])->status, 'only the host');

        $this->as('teacher@school.test');
        $show = $this->get(self::HOST, '/meetings/' . $id)->body;
        $this->assertStringContainsString('About 4 min', $show);
        $this->assertStringContainsString('1 joined', $show);
        $extended = $this->json('/meetings/' . $id . '/extend', ['minutes' => '30']);
        $this->assertSame(strtotime('2026-11-03 10:30:00 UTC'), json_decode($extended->body, true)['ends'] ?? null);
        $this->assertSame(409, $this->json('/meetings/' . $id . '/extend', ['minutes' => '600'])->status);
        $this->post(self::HOST, '/meetings/' . $id . '/end');
        $this->as('bayo@school.test');
        $this->assertStringContainsString('This meeting has ended.', $this->get(self::HOST, '/meetings/' . $id . '/room')->body);

        $this->as('teacher@school.test');
        $this->meet(['when' => 'later', 'date' => '2026-11-04', 'time' => '10:00']);
        $next = $this->lastMeeting();
        $this->assertSame(422, $this->post(self::HOST, '/meetings/' . $next . '/cancel', ['reason' => ''])->status);
        $this->post(self::HOST, '/meetings/' . $next . '/cancel', ['reason' => 'Moved to Friday.']);
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'meeting.cancelled'"));
        $this->post(self::HOST, '/meetings/' . $id . '/invite', ['emails' => 'chidi@school.test']);
        $this->assertSame(2, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM meeting_invites WHERE meeting_id = ?', [$id]), 'no one is added to a meeting that is over');
    }

    public function testTeachersStartAClassNowInLectureModeAndRunIt(): void
    {
        $this->as('teacher@school.test');
        $this->post(self::HOST, '/classes/now', ['group_id' => (string) $this->group, 'minutes' => '60', 'style' => 'lecture']);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM class_sessions'), 'not before the classroom is connected');
        $this->connect();

        $this->as('other-teacher@school.test');
        $this->post(self::HOST, '/classes/now', ['group_id' => (string) $this->group, 'minutes' => '60']);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM class_sessions'), 'only the class’s teachers');

        $this->timeIs('2026-11-02 09:07:41');
        $this->as('teacher@school.test');
        $started = $this->post(self::HOST, '/classes/now', ['group_id' => (string) $this->group, 'title' => '', 'minutes' => '60', 'style' => 'lecture']);
        $id = (int) $this->db()->fetchValue('SELECT MAX(id) FROM class_sessions');
        $this->assertRedirectTo('/classes/' . $id . '/room', $started);
        $row = $this->db()->fetchOne('SELECT title, mode, style, starts_at, ends_at FROM class_sessions WHERE id = ?', [$id]) ?? [];
        $this->assertSame(['Live class: SS 2 Gold', 'classroom', 'lecture', '2026-11-02 09:07:00', '2026-11-02 10:07:00'], array_values($row));
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'class.live'"));
        $this->assertStringContainsString('data-lecture="1"', $this->get(self::HOST, '/classes/' . $id . '/room')->body);

        $this->as('amaka@school.test');
        $room = $this->get(self::HOST, '/classes/' . $id . '/room')->body;
        $this->assertStringContainsString('Lecture mode: you can watch and listen.', $room);
        $this->assertStringNotContainsString('data-room-extend', $room, 'learners do not run the room');
        $this->assertStringContainsString('/classes/' . $id . '/room', $this->get(self::HOST, '/classes')->body, 'an Enter button on the list');
        $this->assertSame(403, $this->json('/classes/' . $id . '/extend', ['minutes' => '30'])->status, 'learners cannot add time');

        $this->as('teacher@school.test');
        $this->assertSame(strtotime('2026-11-02 10:37:00 UTC'), json_decode($this->json('/classes/' . $id . '/extend', ['minutes' => '30'])->body, true)['ends'] ?? null);
        $this->assertRedirectTo('/classes/' . $id . '#register', $this->post(self::HOST, '/classes/' . $id . '/end'));
        $this->assertSame('2026-11-02 09:08:00', $this->db()->fetchValue('SELECT ends_at FROM class_sessions WHERE id = ?', [$id]), 'ended, and at least a minute long');
        $this->timeIs('2026-11-02 09:08:30');
        $this->as('amaka@school.test');
        $this->assertSame(409, $this->get(self::HOST, '/classes/' . $id . '/room')->status, 'ended');
    }

    public function testTheConsoleConnectsTheClassroomWithoutTheSecretOnTheCommandLine(): void
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'sec');
        file_put_contents($file, self::SECRET . "\n");
        $console = new ConsoleApplication($this->app);
        ob_start();
        $refused = $console->run(['console', 'classroom:connect', 'school', '--domain=live.school.test', '--secret=' . self::SECRET]);
        $ok = $console->run(['console', 'classroom:connect', 'school', '--domain=https://live.school.test/', '--app-id=dml-lms', '--secret-file=' . $file, '--low-data=0']);
        $out = (string) ob_get_clean();
        unlink($file);
        $this->assertSame(1, $refused);
        $this->assertSame(0, $ok);
        $this->assertStringContainsString('Classroom connected for School Academy: live.school.test (app id dml-lms)', $out);
        $this->assertStringNotContainsString(self::SECRET, $out);
        $this->assertSame(['live.school.test', 'dml-lms', 0], array_values($this->db()->fetchOne('SELECT domain, app_id, low_data FROM classroom_settings') ?? []));
        $this->assertStringNotContainsString(self::SECRET, (string) $this->db()->fetchValue('SELECT secret_sealed FROM classroom_settings'));
    }
}
