<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Response;
use App\Modules\Courses\CourseAuthoring;
use App\Modules\Courses\CoursePackageImporter;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use App\Modules\VirtualLabs\LabCatalogue;
use App\Modules\VirtualLabs\Seed;
use Tests\Support\AppTestCase;
use Tests\Unit\VirtualLabsTest as Answers;

final class VirtualLabsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $teacher;
    private int $courseId;
    private int $labLessonId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'other@school.test', Role::Learner);

        $authoring = $this->app->get(CourseAuthoring::class);
        $author = $this->author();
        $course = $authoring->createCourse($this->tenant->id, $author, 'Safe online', 'Phishing practice');
        $module = $authoring->addModule($course, $author, 'Module');
        $this->labLessonId = $authoring->addLesson($course, $author, $module, ['type' => 'lab', 'title' => '', 'body' => 'Have a go.', 'lab_key' => 'phishing']);
        $authoring->publish($course, $author);
        $this->courseId = $course->id;
    }

    private function author(): CurrentUser
    {
        return new CurrentUser($this->teacher, $this->app->get(MembershipRepository::class)->find($this->tenant->id, $this->teacher->id));
    }

    private function signIn(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    private function lessonUrl(): string
    {
        return '/courses/safe-online/lessons/' . $this->labLessonId;
    }

    private function startLab(): int
    {
        $this->post(self::HOST, '/courses/safe-online/enrol');
        $response = $this->post(self::HOST, $this->lessonUrl() . '/lab');
        $this->assertSame(303, $response->status);
        $this->assertSame(1, preg_match('#^/labs/(\d+)$#', (string) $response->header('Location'), $m));

        return (int) ($m[1] ?? 0);
    }

    /** @return array<string, string> */
    private function correctAnswersFor(int $sessionId): array
    {
        $seed = (string) $this->db()->fetchValue('SELECT seed FROM lab_sessions WHERE id = ?', [$sessionId]);
        $lab = LabCatalogue::builtIn()->find('phishing') ?? throw new \LogicException();

        return Answers::correctAnswers('phishing', $lab->params(new Seed($seed)));
    }

    /** @param array<string, string> $answers */
    private function submit(int $sessionId, array $answers): Response
    {
        return $this->post(self::HOST, '/labs/' . $sessionId . '/submit', ['answers' => $answers]);
    }

    public function testTheLessonPageDescribesTheLabAndNeedsEnrolment(): void
    {
        $this->signIn('learner@school.test');
        $this->assertSame(403, $this->post(self::HOST, $this->lessonUrl() . '/lab')->status, 'not enrolled');

        $this->post(self::HOST, '/courses/safe-online/enrol');
        $page = $this->get(self::HOST, $this->lessonUrl())->body;
        $this->assertStringContainsString('Spot the phish', $page, 'the title defaults to the lab title');
        $this->assertStringContainsString('20 minutes for each attempt', $page);
        $this->assertStringContainsString('Start the lab', $page);
    }

    public function testAPassingAttemptIsMarkedOnTheServerAndCompletesTheLesson(): void
    {
        $this->signIn('learner@school.test');
        $id = $this->startLab();

        $page = $this->get(self::HOST, '/labs/' . $id);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('data-lab="phishing"', $page->body);
        $this->assertStringContainsString('/assets/labs/phishing.js', $page->body);
        $this->assertStringNotContainsString('Phishing. ', $page->body, 'reasons stay on the server until marked');

        $this->assertRedirectTo('/labs/' . $id, $this->submit($id, $this->correctAnswersFor($id)));
        $row = $this->db()->fetchOne('SELECT status, score, passed, evidence FROM lab_sessions WHERE id = ?', [$id]);
        $this->assertSame(['submitted', 100, 1], [$row['status'] ?? null, (int) ($row['score'] ?? 0), (int) ($row['passed'] ?? 0)]);
        $this->assertSame('completed', $this->db()->fetchValue('SELECT status FROM enrolments'), 'the only lesson, so the course is complete');
        $this->assertStringContainsString('Passed: 100%', $this->get(self::HOST, '/labs/' . $id)->body);

        // A second submission of the same attempt changes nothing.
        $this->submit($id, []);
        $this->assertSame(100, (int) $this->db()->fetchValue('SELECT score FROM lab_sessions WHERE id = ?', [$id]));
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'lab.submitted'"));
    }

    public function testWrongAnswersAreRecordedButDoNotComplete(): void
    {
        $this->signIn('learner@school.test');
        $id = $this->startLab();
        $answers = array_map(static fn (string $a): string => $a === 'safe' ? 'phishing' : 'safe', $this->correctAnswersFor($id));
        $this->submit($id, $answers);

        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT passed FROM lab_sessions WHERE id = ?', [$id]));
        $this->assertSame('active', $this->db()->fetchValue('SELECT status FROM enrolments'));
        $result = $this->get(self::HOST, '/labs/' . $id)->body;
        $this->assertStringContainsString('Not passed yet: 0%', $result);
        $this->assertStringContainsString('Try again with new numbers', $result);
    }

    public function testSessionsBelongToOneLearner(): void
    {
        $this->signIn('learner@school.test');
        $id = $this->startLab();
        $answers = $this->correctAnswersFor($id);

        $this->signIn('other@school.test');
        $this->post(self::HOST, '/courses/safe-online/enrol');
        $this->assertSame(404, $this->get(self::HOST, '/labs/' . $id)->status);
        $this->assertSame(404, $this->submit($id, $answers)->status);
        $this->assertSame(404, $this->post(self::HOST, '/labs/' . $id . '/end')->status);
        $this->assertSame(404, $this->get('elsewhere.test', '/labs/' . $id)->status);
        $this->assertSame('open', $this->db()->fetchValue('SELECT status FROM lab_sessions WHERE id = ?', [$id]));
    }

    public function testTimeRunsOutAndOnlyOneAttemptIsOpen(): void
    {
        $this->signIn('learner@school.test');
        $first = $this->startLab();
        $second = (int) substr((string) $this->post(self::HOST, $this->lessonUrl() . '/lab')->header('Location'), 6);
        $this->assertSame('ended', $this->db()->fetchValue('SELECT status FROM lab_sessions WHERE id = ?', [$first]), 'starting again closes the open attempt');

        $this->db()->execute('UPDATE lab_sessions SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 MINUTE) WHERE id = ?', [$second]);
        $this->submit($second, $this->correctAnswersFor($second));
        $this->assertSame('expired', $this->db()->fetchValue('SELECT status FROM lab_sessions WHERE id = ?', [$second]));
        $this->assertNull($this->db()->fetchValue('SELECT score FROM lab_sessions WHERE id = ?', [$second]));
        $this->assertStringContainsString('Time ran out', $this->get(self::HOST, '/labs/' . $second)->body);
    }

    public function testLaunchesAreLimited(): void
    {
        $this->signIn('learner@school.test');
        $id = $this->startLab();
        $this->db()->execute(
            'INSERT INTO lab_sessions (tenant_id, user_id, course_id, lesson_id, enrolment_id, lab_key, lab_version, seed, status, started_at, expires_at)
             SELECT tenant_id, user_id, course_id, lesson_id, enrolment_id, lab_key, lab_version, seed, \'ended\', UTC_TIMESTAMP(), expires_at
             FROM lab_sessions s CROSS JOIN (SELECT 1 FROM information_schema.columns LIMIT 30) n WHERE s.id = ?',
            [$id],
        );
        $response = $this->post(self::HOST, $this->lessonUrl() . '/lab');
        $this->assertRedirectTo($this->lessonUrl(), $response);
        $this->assertStringContainsString('in the last hour', $this->get(self::HOST, $this->lessonUrl())->body);
    }

    public function testTheCodeRunnerIsSealedOff(): void
    {
        $runner = $this->get(self::HOST, '/labs/runner');
        $this->assertSame(200, $runner->status);
        $csp = (string) $runner->header('Content-Security-Policy');
        foreach (["default-src 'none'", "connect-src 'none'", 'worker-src blob:', "frame-ancestors 'self'", "form-action 'none'"] as $directive) {
            $this->assertStringContainsString($directive, $csp);
        }
        $this->assertSame(1, preg_match("/script-src 'nonce-([A-Za-z0-9+\\/=]+)'/", $csp, $m));
        $this->assertStringContainsString('<script nonce="' . ($m[1] ?? '') . '">', $runner->body);
        $this->assertSame('SAMEORIGIN', $runner->header('X-Frame-Options'));
        $this->assertNotSame($csp, (string) $this->get(self::HOST, '/labs/runner')->header('Content-Security-Policy'), 'a new nonce each time');

        // Ordinary pages may frame only this site's runner (and the video players), and are never framed.
        $page = $this->get(self::HOST, '/');
        $this->assertStringContainsString("frame-src 'self' https://www.youtube-nocookie.com", (string) $page->header('Content-Security-Policy'));
        $this->assertSame('DENY', $page->header('X-Frame-Options'));
    }

    public function testTeachersTryLabsWithoutLeavingAnything(): void
    {
        $this->signIn('teacher@school.test');
        $this->assertStringContainsString('Spot the phish', $this->get(self::HOST, '/teach/labs')->body);
        $page = $this->get(self::HOST, '/teach/labs/phishing')->body;
        $this->assertSame(1, preg_match('/name="seed" value="([a-f0-9]{32})"/', $page, $seed));
        $this->assertSame(1, preg_match('/name="until" value="(\d+)"/', $page, $until));
        $this->assertSame(1, preg_match('/name="signature" value="([a-f0-9]{64})"/', $page, $sig));
        [$seed, $until, $sig] = [$seed[1] ?? '', $until[1] ?? '', $sig[1] ?? ''];
        $lab = LabCatalogue::builtIn()->find('phishing') ?? throw new \LogicException();
        $answers = Answers::correctAnswers('phishing', $lab->params(new Seed($seed)));

        $checked = $this->post(self::HOST, '/teach/labs/phishing', ['seed' => $seed, 'until' => $until, 'signature' => $sig, 'answers' => $answers]);
        $this->assertStringContainsString('Passed: 100%', $checked->body);
        $this->assertStringContainsString('practice run', $checked->body);

        $forged = $this->post(self::HOST, '/teach/labs/phishing', ['seed' => str_repeat('a', 32), 'until' => $until, 'signature' => $sig, 'answers' => $answers]);
        $this->assertRedirectTo('/teach/labs/phishing', $forged);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lab_sessions'));

        $this->signIn('learner@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/teach/labs')->status);
    }

    public function testTeachersAddLabsOnlyFromTheCatalogueAndSeeResults(): void
    {
        $this->signIn('teacher@school.test');
        $module = (int) $this->db()->fetchValue('SELECT id FROM course_modules WHERE course_id = ?', [$this->courseId]);
        $bad = $this->post(self::HOST, '/teach/courses/' . $this->courseId . '/lessons', ['module_id' => (string) $module, 'type' => 'lab', 'lab_key' => '../../etc/passwd', 'lesson_title' => 'x']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Choose a lab from the catalogue', $bad->body);

        $this->post(self::HOST, '/teach/courses/' . $this->courseId . '/lessons', ['module_id' => (string) $module, 'type' => 'lab', 'lab_key' => 'ohms-law', 'lesson_title' => '']);
        $this->assertSame('ohms-law', $this->db()->fetchValue("SELECT lab_key FROM lessons WHERE title = 'Ohm’s law: the hidden resistor'"));

        $this->signIn('learner@school.test');
        $id = $this->startLab();
        $this->submit($id, $this->correctAnswersFor($id));
        $this->signIn('teacher@school.test');
        $report = $this->get(self::HOST, '/teach/courses/' . $this->courseId . '/progress')->body;
        $this->assertStringContainsString('Spot the phish<span class="visually-hidden">, best lab score', $report);
        $this->assertStringContainsString('✓ 100%', $report);
    }

    public function testShippedLabCoursesImportAndShowUnderTheirTrack(): void
    {
        $importer = $this->app->get(CoursePackageImporter::class);
        foreach (['ss2-science-practicals', 'practical-tech-skills'] as $package) {
            $result = $importer->import($this->tenant->id, $this->author(), dirname(__DIR__, 2) . '/courses/' . $package, true);
            $this->assertSame(3, $result['modules']);
        }
        $this->assertSame(6, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM lessons WHERE type = 'lab' AND lab_key IS NOT NULL") - 1);
        $this->assertEquals(['school' => 1, 'skills' => 1], $this->app->get(CourseRepository::class)->publishedTrackCounts($this->tenant->id));

        $school = $this->send(new \App\Core\Http\Request('GET', '/courses', self::HOST, ['track' => 'school']))->body;
        $this->assertStringContainsString('SS2 Science Practicals', $school);
        $this->assertStringNotContainsString('Practical Tech Skills', $school);
        $this->assertStringContainsString('3 labs', $school);
        $this->assertStringContainsString('School subjects', $this->get(self::HOST, '/courses')->body);
    }
}
