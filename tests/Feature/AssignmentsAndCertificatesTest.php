<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Bootstrap\Application;
use App\Modules\Courses\CoursePackageImporter;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class AssignmentsAndCertificatesTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $teacher;
    private int $assignmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config['MAIL_DRIVER'] = 'file';
        $this->app = $this->freshApp($this->session);
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);

        // One reading and one assignment.
        $courses = $this->app->get(CourseRepository::class);
        $lessons = $this->app->get(LessonRepository::class);
        $course = $courses->create($this->tenant->id, $this->teacher->id, 'servers', 'Servers', 'Summary');
        $module = $lessons->addModule($this->tenant->id, $course->id, 'Module');
        $lessons->addLesson($this->tenant->id, $course->id, $module, Lesson::TYPE_TEXT, 'Read me', 'Text.');
        $this->assignmentId = $lessons->addLesson($this->tenant->id, $course->id, $module, Lesson::TYPE_ASSIGNMENT, 'Harden SSH', 'Disable password login and paste `sshd -T`.');
        $courses->markPublished($this->tenant->id, $course->id);
    }

    private function asLearner(): void
    {
        $this->newBrowser();
        $this->login(self::HOST, 'tobi@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/servers/enrol');
        $reading = (int) $this->db()->fetchValue("SELECT id FROM lessons WHERE title = 'Read me'");
        $this->post(self::HOST, '/courses/servers/lessons/' . $reading . '/complete');
    }

    private function asTeacher(): void
    {
        $this->newBrowser();
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
    }

    private function submit(string $answer, string $link = ''): \App\Core\Http\Response
    {
        return $this->post(self::HOST, '/courses/servers/lessons/' . $this->assignmentId . '/submissions', ['answer' => $answer, 'link' => $link]);
    }

    private function latestSubmissionId(): int
    {
        return (int) $this->db()->fetchValue('SELECT MAX(id) FROM assignment_submissions');
    }

    public function testFullReviewCycleEndsWithACompletedCourseAndCertificate(): void
    {
        $this->asLearner();
        $learnerBrowser = $this->session;

        $this->assertSame(422, $this->submit('')->status, 'empty answer refused');
        $this->assertSame(422, $this->submit('Done', 'javascript:alert(1)')->status, 'link must be a web address');
        $this->submit("First try\n\n```text\nPasswordAuthentication yes\n```");
        $this->submit('First try, corrected', 'https://github.com/tobi/notes');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM assignment_submissions'), 'a pending submission is replaced, not duplicated');

        $page = $this->get(self::HOST, '/courses/servers/lessons/' . $this->assignmentId)->body;
        $this->assertStringContainsString('Waiting for review', $page);

        // Teacher asks for changes; feedback is required.
        $this->asTeacher();
        $queue = $this->get(self::HOST, '/teach/reviews')->body;
        $this->assertStringContainsString('Harden SSH', $queue);
        $id = $this->latestSubmissionId();
        $review = $this->get(self::HOST, '/teach/reviews/' . $id)->body;
        $this->assertStringContainsString('First try, corrected', $review);
        $this->assertStringContainsString('rel="noopener noreferrer nofollow"', $review);
        $this->assertSame(422, $this->post(self::HOST, '/teach/reviews/' . $id, ['decision' => 'changes', 'feedback' => ''])->status);
        $this->assertRedirectTo('/teach/reviews', $this->post(self::HOST, '/teach/reviews/' . $id, ['decision' => 'changes', 'feedback' => 'Set it to **no**.']));

        $mail = $this->deliverMail();
        $this->assertCount(1, $mail);
        $this->assertSame('tobi@school.test', $mail[0]->toEmail);
        $this->assertStringContainsString('Changes requested: Harden SSH', $mail[0]->subject);
        $this->assertStringContainsString('Set it to **no**.', $mail[0]->text);
        $this->assertStringContainsString('/courses/servers/lessons/' . $this->assignmentId, $mail[0]->text);

        // The decision is final: reviewing the same submission again is refused.
        $this->assertSame(422, $this->post(self::HOST, '/teach/reviews/' . $id, ['decision' => 'approve', 'feedback' => ''])->status);

        // Learner sees feedback and resubmits: a new submission, history kept.
        $this->useBrowser($learnerBrowser);
        $page = $this->get(self::HOST, '/courses/servers/lessons/' . $this->assignmentId)->body;
        $this->assertStringContainsString('Changes requested', $page);
        $this->assertStringContainsString('Set it to <strong>no</strong>.', $page);
        $this->submit('Second try: PasswordAuthentication no');
        $this->assertSame(2, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM assignment_submissions'));

        // Approval completes the lesson, the course, and issues a certificate.
        $this->asTeacher();
        $this->post(self::HOST, '/teach/reviews/' . $this->latestSubmissionId(), ['decision' => 'approve', 'feedback' => 'Well done.']);
        $this->assertSame('completed', $this->db()->fetchValue("SELECT status FROM enrolments"));
        $code = (string) $this->db()->fetchValue('SELECT code FROM certificates');
        $this->assertMatchesRegularExpression('/^[23456789A-HJ-NP-Z]{12}$/', $code);
        $this->assertStringContainsString('Approved: Harden SSH', $this->deliverMail()[0]->subject);

        $progress = $this->get(self::HOST, '/teach/courses/' . (int) $this->db()->fetchValue('SELECT id FROM courses') . '/progress')->body;
        $this->assertStringContainsString('Approved', $progress);

        $this->useBrowser($learnerBrowser);
        $this->assertSame(422, $this->submit('Third try')->status, 'nothing more to submit once approved');
        $display = implode('-', str_split($code, 4));
        $this->assertStringContainsString('/certificates/' . $display, $this->get(self::HOST, '/courses/servers')->body);
        $this->assertStringContainsString('/certificates/' . $display, $this->get(self::HOST, '/')->body, 'dashboard lists certificates');
        $certificate = $this->get(self::HOST, '/certificates/' . $display);
        $this->assertSame(200, $certificate->status);
        $this->assertStringContainsString('Test Learner', $certificate->body);
        $this->assertStringContainsString('http://' . self::HOST . '/verify/' . $display, $certificate->body);
    }

    public function testCertificatesArePrivateButVerifiable(): void
    {
        $this->asLearner();
        $this->submit('My work');
        $this->asTeacher();
        $this->post(self::HOST, '/teach/reviews/' . $this->latestSubmissionId(), ['decision' => 'approve', 'feedback' => '']);
        $code = (string) $this->db()->fetchValue('SELECT code FROM certificates');

        $this->assertSame(200, $this->get(self::HOST, '/certificates/' . $code)->status, 'staff with reports access may view it');

        $this->makeUser($this->tenant, 'ngozi@school.test', Role::Learner);
        $this->newBrowser();
        $this->login(self::HOST, 'ngozi@school.test', 'correct-horse-battery');
        $this->assertSame(404, $this->get(self::HOST, '/certificates/' . $code)->status, 'another learner cannot');

        $this->newBrowser();
        $verify = $this->get(self::HOST, '/verify/' . strtolower(implode('-', str_split($code, 4))));
        $this->assertSame(200, $verify->status, 'anyone can verify; letter case does not matter');
        $typed = new \App\Core\Http\Request('GET', '/verify', self::HOST, ['code' => strtolower(implode(' ', str_split($code, 4)))]);
        $this->assertSame(200, $this->send($typed)->status, 'spaces are fine in the form');
        $this->assertStringContainsString('Genuine certificate', $verify->body);
        $this->assertStringContainsString('Servers', $verify->body);
        $this->assertSame(404, $this->get(self::HOST, '/verify/ABCDEFGHJKLM')->status);
        $this->assertSame(404, $this->send(new \App\Core\Http\Request('GET', '/verify', self::HOST, ['code' => 'not-a-code']))->status);
        $this->assertSame(200, $this->get(self::HOST, '/verify')->status, 'the empty form');

        $other = $this->makeTenant('other', 'other.test');
        $this->assertSame(404, $this->get('other.test', '/verify/' . $code)->status, 'only on the issuing school');

        $this->app->get(\App\Modules\Certificates\CertificateService::class)->revoke($code, 'Issued in error');
        $revoked = $this->get(self::HOST, '/verify/' . $code)->body;
        $this->assertStringContainsString('Revoked', $revoked);
        $this->assertStringContainsString('Issued in error', $revoked);
        unset($other);
    }

    public function testReviewRulesAndIsolation(): void
    {
        // An owner who is also enrolled cannot approve their own work.
        $owner = $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->newBrowser();
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/servers/enrol');
        $this->post(self::HOST, '/courses/servers/lessons/' . $this->assignmentId . '/submissions', ['answer' => 'Mine', 'link' => '']);
        $id = $this->latestSubmissionId();
        $own = $this->post(self::HOST, '/teach/reviews/' . $id, ['decision' => 'approve', 'feedback' => '']);
        $this->assertSame(422, $own->status);
        $this->assertStringContainsString('cannot review your own work', $own->body);
        unset($owner);

        // Learners cannot open the queue.
        $this->asLearner();
        $this->assertSame(403, $this->get(self::HOST, '/teach/reviews')->status);

        // A teacher from another school cannot see or decide it.
        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'teacher@other.test', Role::Educator);
        $this->newBrowser();
        $this->login('other.test', 'teacher@other.test', 'correct-horse-battery');
        $this->assertSame(404, $this->get('other.test', '/teach/reviews/' . $id)->status);
        $this->assertSame(404, $this->post('other.test', '/teach/reviews/' . $id, ['decision' => 'approve', 'feedback' => ''])->status);
        $this->assertSame('submitted', $this->db()->fetchValue('SELECT status FROM assignment_submissions WHERE id = ?', [$id]));
    }

    public function testCertificatesAreIssuedForCoursesCompletedBeforeThisRelease(): void
    {
        $this->asLearner();
        $this->db()->execute("UPDATE enrolments SET status = 'completed', completed_at = UTC_TIMESTAMP()");
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM certificates'));

        $this->get(self::HOST, '/courses/servers');

        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM certificates'));
        $this->get(self::HOST, '/');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM certificates'), 'never a second one');
    }

    public function testTheShippedLinuxCourseImportsCleanly(): void
    {
        $membership = $this->app->get(MembershipRepository::class)->find($this->tenant->id, $this->teacher->id);
        $this->assertNotNull($membership);
        $result = $this->app->get(CoursePackageImporter::class)->import(
            $this->tenant->id,
            new CurrentUser($this->teacher, $membership),
            Application::basePath('courses/linux-server-administration'),
            true,
        );

        $this->assertSame(8, $result['modules']);
        $this->assertSame(32, $result['lessons']);
        $this->assertGreaterThanOrEqual(60, $result['questions']);
        $course = $result['course'];
        $stored = $this->app->get(CourseRepository::class)->findById($this->tenant->id, $course->id);
        $this->assertNotNull($stored);
        $this->assertTrue($stored->isPublished());
        $this->assertSame('advanced', $stored->level);
        $this->assertSame(0, (int) $this->db()->fetchValue(
            "SELECT COUNT(*) FROM questions q JOIN assessments a ON a.id = q.assessment_id JOIN lessons l ON l.id = a.lesson_id
             WHERE l.course_id = ? AND (q.explanation IS NULL OR q.explanation = '')",
            [$course->id],
        ), 'every question has an explanation');
        $this->assertSame(3, (int) $this->db()->fetchValue(
            "SELECT max_attempts FROM assessments a JOIN lessons l ON l.id = a.lesson_id WHERE l.title = 'Final exam' AND l.course_id = ?",
            [$course->id],
        ));

        // Every lesson renders for a learner.
        $this->login(self::HOST, 'tobi@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/' . $course->slug . '/enrol');
        foreach ($this->db()->fetchAll('SELECT id FROM lessons WHERE course_id = ?', [$course->id]) as $row) {
            $this->assertSame(200, $this->get(self::HOST, '/courses/' . $course->slug . '/lessons/' . $row['id'])->status);
        }
    }

    public function testABrokenPackageLeavesNothingBehind(): void
    {
        $dir = sys_get_temp_dir() . '/dml-broken-package-' . bin2hex(random_bytes(4));
        mkdir($dir . '/lessons', 0700, true);
        file_put_contents($dir . '/lessons/one.md', 'Hello');
        file_put_contents($dir . '/course.json', json_encode([
            'title' => 'Broken course', 'summary' => '', 'modules' => [
                ['title' => 'One', 'lessons' => [
                    ['type' => 'text', 'title' => 'Fine', 'file' => 'lessons/one.md'],
                    ['type' => 'text', 'title' => 'Escapes', 'file' => '../../etc/passwd'],
                ]],
            ],
        ], JSON_THROW_ON_ERROR));
        $membership = $this->app->get(MembershipRepository::class)->find($this->tenant->id, $this->teacher->id);
        $this->assertNotNull($membership);

        try {
            $this->app->get(CoursePackageImporter::class)->import($this->tenant->id, new CurrentUser($this->teacher, $membership), $dir, false);
            $this->fail('import should fail');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('modules[0].lessons[1]', $e->getMessage());
        }
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM courses WHERE title = 'Broken course'"));
        array_map('unlink', [$dir . '/lessons/one.md', $dir . '/course.json']);
        rmdir($dir . '/lessons');
        rmdir($dir);
    }
}
