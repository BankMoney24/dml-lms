<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Clock;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\UploadedFile;
use App\Core\Security\Csrf;
use App\Modules\Identity\Role;
use App\Modules\LiveClasses\Classroom\JitsiToken;
use App\Modules\Platform\SampleClock;
use App\Modules\Tenancy\Tenant;
use DateTimeImmutable;
use DateTimeZone;
use Tests\Support\AppTestCase;

/** ADR 0029: the online classroom (self-hosted Jitsi), its passes, attendance, materials and recordings. */
final class ClassroomTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const SECRET = '6f1c0b7e3d9a4c2b8e5f7a1d0c3b9e8f2a4d6c8e0b1f3a5c7e9d2b4f6a8c0e1d';

    private SampleClock $clock;
    private Tenant $tenant;
    private int $group;

    protected function setUp(): void
    {
        $this->clock = new SampleClock();
        $this->timeIs('2026-11-02 09:00:00');
        $this->overrides = [Clock::class => $this->clock];
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'other-teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'amaka@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'bayo@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'chidi@school.test', Role::Learner);

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
        $this->assertRedirectTo('/school/classroom', $this->post(self::HOST, '/school/classroom', [
            'domain' => 'live.school.test', 'app_id' => 'dml-lms', 'secret' => self::SECRET, 'low_data' => '1',
        ]));
    }

    /** @param array<string, string> $over */
    private function plan(array $over = []): Response
    {
        return $this->post(self::HOST, '/groups/' . $this->group . '/classes', $over + [
            'class_title' => 'Week 1: networks', 'class_date' => '2026-11-02', 'class_time' => '16:00', 'class_minutes' => '60',
            'class_mode' => 'classroom', 'class_join_url' => '', 'class_location' => '', 'class_course_id' => '', 'class_weeks' => '1', 'class_description' => '',
        ]);
    }

    private function lastSession(): int
    {
        return (int) $this->db()->fetchValue('SELECT MAX(id) FROM class_sessions');
    }

    private function signal(int $id, string $type): Response
    {
        return $this->send(new Request('POST', '/classes/' . $id . '/signal', self::HOST, [], [
            '_token' => $this->app->get(Csrf::class)->token(), 'type' => $type,
        ], ['accept' => 'application/json']));
    }

    /** @return array<string, mixed> */
    private function passOn(string $page): array
    {
        $this->assertSame(1, preg_match('/data-jwt="([^"]+)"/', $page, $m), 'the page carries a pass');
        $claims = JitsiToken::verify(html_entity_decode($m[1] ?? ''), self::SECRET);
        $this->assertIsArray($claims, 'signed with the school secret');

        return $claims;
    }

    public function testAdministratorsConnectTheServerAndTheSecretStaysHidden(): void
    {
        $this->as('teacher@school.test');
        $this->assertSame(422, $this->plan()->status, 'not before the classroom is set up');
        $this->assertSame(403, $this->get(self::HOST, '/school/classroom')->status);

        $this->as('admin@school.test');
        $bad = $this->post(self::HOST, '/school/classroom', ['domain' => 'https://live.school.test/x y', 'app_id' => 'dml lms', 'secret' => 'short', 'low_data' => '1']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Enter the classroom server’s name only', $bad->body);
        $this->assertStringContainsString('at least 32 characters', $bad->body);
        $this->assertStringNotContainsString('value="short"', $bad->body, 'a typed secret is never echoed back');

        $this->connect();
        $sealed = (string) $this->db()->fetchValue('SELECT secret_sealed FROM classroom_settings');
        $this->assertStringNotContainsString(self::SECRET, $sealed);
        $page = $this->get(self::HOST, '/school/classroom')->body;
        $this->assertStringContainsString('live.school.test', $page);
        $this->assertStringNotContainsString(self::SECRET, $page);

        // Saving without a secret keeps the old one.
        $this->post(self::HOST, '/school/classroom', ['domain' => 'https://Live.School.test/', 'app_id' => 'dml-lms', 'secret' => '', 'low_data' => '0']);
        $this->assertSame('live.school.test', $this->db()->fetchValue('SELECT domain FROM classroom_settings'));
        $this->assertSame($sealed, $this->db()->fetchValue('SELECT secret_sealed FROM classroom_settings'));
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT low_data FROM classroom_settings'));
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE CAST(metadata AS CHAR) LIKE '%" . substr(self::SECRET, 0, 12) . "%'"), 'not in the audit log');
    }

    public function testOnlyTheClassGetsAPassAndOnlyWhileItIsOn(): void
    {
        $this->connect();
        $this->as('teacher@school.test');
        $this->assertRedirectTo('/groups/' . $this->group . '#classes', $this->plan(['class_weeks' => '2']));
        $rooms = $this->db()->fetchAll('SELECT room_name FROM class_sessions ORDER BY id');
        $this->assertMatchesRegularExpression('/^dml-[0-9a-f]{24}$/', (string) $rooms[0]['room_name']);
        $this->assertNotSame($rooms[0]['room_name'], $rooms[1]['room_name'], 'each class has its own room');
        $id = (int) $this->db()->fetchValue('SELECT MIN(id) FROM class_sessions');

        $this->as('amaka@school.test');
        $early = $this->get(self::HOST, '/classes/' . $id . '/room');
        $this->assertSame(409, $early->status);
        $this->assertStringContainsString('The classroom opens at <strong>15:45</strong>', $early->body);
        $this->assertStringNotContainsString('data-jwt', $early->body);

        $this->timeIs('2026-11-02 14:35:00');
        $this->as('teacher@school.test');
        $room = $this->get(self::HOST, '/classes/' . $id . '/room');
        $this->assertSame(200, $room->status, 'teachers may set up 30 minutes early');
        $claims = $this->passOn($room->body);
        $this->assertSame('jitsi', $claims['aud']);
        $this->assertSame('dml-lms', $claims['iss']);
        $this->assertSame('live.school.test', $claims['sub']);
        $this->assertSame($rooms[0]['room_name'], $claims['room']);
        $this->assertTrue($claims['moderator']);
        $this->assertSame(strtotime('2026-11-02 16:15:00 UTC'), $claims['exp'], 'runs out 15 minutes after the class');
        $csp = (string) $room->header('Content-Security-Policy');
        $this->assertStringContainsString("script-src 'self' https://live.school.test;", $csp);
        $this->assertStringContainsString('frame-src https://live.school.test;', $csp);
        $this->assertStringContainsString('camera=(self "https://live.school.test")', (string) $room->header('Permissions-Policy'));
        $this->assertStringContainsString('<script src="https://live.school.test/external_api.js" defer></script>', $room->body);
        $this->assertSame('private, no-store', $room->header('Cache-Control'));

        $this->as('amaka@school.test');
        $this->assertSame(409, $this->get(self::HOST, '/classes/' . $id . '/room')->status, 'learners wait until 15 minutes before');
        $this->timeIs('2026-11-02 14:50:00');
        $learner = $this->passOn($this->get(self::HOST, '/classes/' . $id . '/room')->body);
        $this->assertFalse($learner['moderator']);
        $this->assertSame('member', $learner['context']['user']['affiliation'] ?? null);

        $this->as('chidi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/classes/' . $id . '/room')->status, 'signed in is not enough');
        $this->assertSame(404, $this->signal($id, 'join')->status);
        $this->as('other-teacher@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/classes/' . $id . '/room')->status);

        $this->timeIs('2026-11-02 16:00:00');
        $this->as('amaka@school.test');
        $ended = $this->get(self::HOST, '/classes/' . $id . '/room');
        $this->assertSame(409, $ended->status);
        $this->assertStringContainsString('This class has ended.', $ended->body);
    }

    public function testJoiningInTheClassroomMarksAttendanceAndTimeInClass(): void
    {
        $this->connect();
        $this->as('teacher@school.test');
        $this->plan();
        $id = $this->lastSession();

        $this->timeIs('2026-11-02 14:58:00');
        $this->as('amaka@school.test');
        $this->assertSame('{"ok":true,"status":"present"}', $this->signal($id, 'join')->body);
        for ($minute = 59; $minute <= 70; $minute++) {
            $this->timeIs(sprintf('2026-11-02 %s:%02d:05', $minute >= 60 ? '15' : '14', $minute % 60));
            $this->signal($id, 'here');
            $this->signal($id, 'here'); // a second tab adds nothing
        }
        $this->signal($id, 'leave');

        $this->timeIs('2026-11-02 15:20:00');
        $this->as('bayo@school.test');
        $this->assertSame('{"ok":true,"status":"late"}', $this->signal($id, 'join')->body);
        $this->assertSame(409, $this->signal($id, 'wave')->status);

        $this->as('teacher@school.test');
        $register = $this->get(self::HOST, '/classes/' . $id)->body;
        $this->assertStringContainsString('Joined online, about 13 min in the classroom', $register, 'amaka: join plus twelve minutes');
        $this->assertStringContainsString('Joined online, about 1 min in the classroom', $register);
        $this->assertSame(['present', 'late'], array_column($this->db()->fetchAll('SELECT status FROM class_attendance ORDER BY user_id'), 'status'));

        $this->timeIs('2026-11-02 16:01:00');
        $this->as('amaka@school.test');
        $this->assertSame(409, $this->signal($id, 'here')->status, 'not after the class');
    }

    public function testTeachersShareMaterialsAndTheRecording(): void
    {
        $this->connect();
        $this->as('teacher@school.test');
        $this->plan();
        $id = $this->lastSession();

        $notes = $this->upload(self::HOST, '/classes/' . $id . '/materials', [], ['material' => [$this->fakeUpload('Week 1 slides.pdf', self::pdfBytes())]]);
        $this->assertRedirectTo('/classes/' . $id . '#materials', $notes);
        $pdfAsRecording = $this->upload(self::HOST, '/classes/' . $id . '/materials', ['recording' => '1'], ['material' => [$this->fakeUpload('notes.pdf', self::pdfBytes())]]);
        $this->assertSame(422, $pdfAsRecording->status, 'a recording is video or audio');

        $clip = (string) tempnam(sys_get_temp_dir(), 'med');
        copy(__DIR__ . '/../Fixtures/media/clip.mp4', $clip);
        $this->upload(self::HOST, '/classes/' . $id . '/materials', ['recording' => '1'], ['material' => [UploadedFile::fromLocalPath($clip, 'Week 1 recording.mp4')]]);
        $recording = (int) $this->db()->fetchValue('SELECT recording_file_id FROM class_sessions WHERE id = ?', [$id]);
        $this->assertGreaterThan(0, $recording);
        $slides = (int) $this->db()->fetchValue("SELECT id FROM files WHERE original_name = 'Week 1 slides.pdf'");

        $this->as('amaka@school.test');
        $page = $this->get(self::HOST, '/classes/' . $id)->body;
        $this->assertStringContainsString('Week 1 slides.pdf', $page);
        $this->assertStringContainsString('<source src="/files/' . $recording . '" type="video/mp4">', $page);
        $this->assertSame(200, $this->get(self::HOST, '/files/' . $slides)->status);
        $this->assertContains($this->upload(self::HOST, '/classes/' . $id . '/materials', [], ['material' => [$this->fakeUpload('mine.pdf', self::pdfBytes())]])->status, [403, 404]);
        $this->timeIs('2026-11-02 14:50:00');
        $this->assertStringContainsString('Week 1 slides.pdf', $this->get(self::HOST, '/classes/' . $id . '/room')->body, 'beside the classroom too');

        $this->as('chidi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $slides)->status, 'not in the class');
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $recording)->status);

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/classes/' . $id . '/materials/' . $recording . '/delete');
        $this->assertNull($this->db()->fetchValue('SELECT recording_file_id FROM class_sessions WHERE id = ?', [$id]));
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files WHERE class_session_id = ?', [$id]));
    }
}
