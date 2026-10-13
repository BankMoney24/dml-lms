<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class FilesTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private int $courseId;
    private int $readingId;
    private int $assignmentId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'ngozi@school.test', Role::Learner);

        $courses = $this->app->get(CourseRepository::class);
        $lessons = $this->app->get(LessonRepository::class);
        $course = $courses->create($this->tenant->id, $teacher->id, 'servers', 'Servers', '');
        $module = $lessons->addModule($this->tenant->id, $course->id, 'Module');
        $this->readingId = $lessons->addLesson($this->tenant->id, $course->id, $module, Lesson::TYPE_TEXT, 'Reading', 'Text.');
        $this->assignmentId = $lessons->addLesson($this->tenant->id, $course->id, $module, Lesson::TYPE_ASSIGNMENT, 'Task', 'Do it.');
        $courses->markPublished($this->tenant->id, $course->id);
        $this->courseId = $course->id;
    }

    private function lessonUrl(int $lessonId): string
    {
        return sprintf('/teach/courses/%d/lessons/%d', $this->courseId, $lessonId);
    }

    private function asTeacher(): void
    {
        $this->newBrowser();
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
    }

    private function asLearner(string $email = 'tobi@school.test', bool $enrol = true): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
        if ($enrol) {
            $this->post(self::HOST, '/courses/servers/enrol');
        }
    }

    private function attach(string $name, string $bytes, ?int $lessonId = null): \App\Core\Http\Response
    {
        return $this->upload(self::HOST, $this->lessonUrl($lessonId ?? $this->readingId) . '/files', [], ['file' => [$this->fakeUpload($name, $bytes)]]);
    }

    private function fileId(string $name): int
    {
        return (int) $this->db()->fetchValue('SELECT id FROM files WHERE original_name = ?', [$name]);
    }

    public function testTeacherAttachesAPdfThatOnlyEnrolledLearnersCanOpen(): void
    {
        $this->asTeacher();
        $this->assertRedirectTo($this->lessonUrl($this->readingId) . '#files', $this->attach('Week 1 notes.pdf', self::pdfBytes()));
        $id = $this->fileId('Week 1 notes.pdf');
        $key = (string) $this->db()->fetchValue('SELECT storage_key FROM files WHERE id = ?', [$id]);
        $this->assertMatchesRegularExpression('#^\d+/\d{4}/\d{2}/[a-f0-9]{32}$#', $key, 'stored under a random name, not the uploaded one');

        $this->asLearner();
        $page = $this->get(self::HOST, '/courses/servers/lessons/' . $this->readingId)->body;
        $this->assertStringContainsString('Week 1 notes.pdf', $page);
        $this->assertMatchesRegularExpression('/PDF, \d+ bytes/', $page, 'size shown before downloading');

        $download = $this->get(self::HOST, '/files/' . $id);
        $this->assertSame(200, $download->status);
        $this->assertSame('application/pdf', $download->header('Content-Type'));
        $this->assertStringStartsWith('inline; filename="Week 1 notes.pdf"', (string) $download->header('Content-Disposition'));
        $this->assertSame(self::pdfBytes(), file_get_contents((string) $download->filePath()));
        $this->assertStringStartsWith('attachment;', (string) $this->send(new Request('GET', '/files/' . $id, self::HOST, ['download' => '1']))->header('Content-Disposition'));

        $this->asLearner('ngozi@school.test', false);
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $id)->status, 'not enrolled');

        $this->newBrowser();
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/files/' . $id), 'signed out');

        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'teacher@other.test', Role::Educator);
        $this->newBrowser();
        $this->login('other.test', 'teacher@other.test', 'correct-horse-battery');
        $this->assertSame(404, $this->get('other.test', '/files/' . $id)->status, 'another school');
    }

    public function testCodeAndMarkupAreShownAsTextNeverRun(): void
    {
        $this->asTeacher();
        $this->attach('page.html', "<!doctype html><script>alert(document.cookie)</script>\n");
        $download = $this->get(self::HOST, '/files/' . $this->fileId('page.html'));

        $this->assertSame('text/plain; charset=utf-8', $download->header('Content-Type'));
        $this->assertStringContainsString('sandbox', (string) $download->header('Content-Security-Policy'));
        $this->assertSame('nosniff', $download->header('X-Content-Type-Options'));
    }

    public function testDangerousOrMislabelledFilesAreRefused(): void
    {
        $this->asTeacher();
        $cases = [
            'setup.exe' => ["MZ\x90\x00\x03", 'This kind of file is not accepted'],
            'drawing.svg' => ['<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'This kind of file is not accepted'],
            'notes.pdf' => ['just some text pretending', 'contents do not match'],
            'photo.png' => [self::pdfBytes(), 'contents do not match'],
            'script.sh' => ["#!/bin/sh\n\x00\x01binary", 'contents do not match'],
            'empty.txt' => ['', 'The file is empty'],
        ];
        foreach ($cases as $name => [$bytes, $message]) {
            $response = $this->attach($name, $bytes);
            $this->assertSame(422, $response->status, $name);
            $this->assertStringContainsString($message, html_entity_decode($response->body, ENT_QUOTES), $name);
        }
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files'));
    }

    public function testSizeAndQuotaLimits(): void
    {
        $this->configure('UPLOAD_MAX_MB', '1');
        $this->configure('UPLOAD_TENANT_QUOTA_MB', '1');
        $this->asTeacher();

        $big = $this->attach('big.txt', str_repeat("a line of text\n", 80000));
        $this->assertSame(422, $big->status);
        $this->assertStringContainsString('Files can be up to 1 MB', $big->body);

        $this->assertSame(303, $this->attach('one.txt', str_repeat("x\n", 300000))->status);
        $full = $this->attach('two.txt', str_repeat("y\n", 300000));
        $this->assertSame(422, $full->status);
        $this->assertStringContainsString('used all of its file storage', $full->body);
    }

    public function testRemovingAFileDeletesItFromDisk(): void
    {
        $this->asTeacher();
        $this->attach('old.pdf', self::pdfBytes());
        $id = $this->fileId('old.pdf');
        $key = (string) $this->db()->fetchValue('SELECT storage_key FROM files WHERE id = ?', [$id]);
        $path = sys_get_temp_dir() . '/dml-lms-test-storage/uploads/' . $key;
        $this->assertFileExists($path);

        $this->post(self::HOST, $this->lessonUrl($this->readingId) . '/files/' . $id . '/delete');

        $this->assertFileDoesNotExist($path);
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $id)->status);
    }

    public function testDeletingALessonRemovesItsFiles(): void
    {
        $this->asTeacher();
        $this->attach('handout.pdf', self::pdfBytes());
        $key = (string) $this->db()->fetchValue("SELECT storage_key FROM files WHERE original_name = 'handout.pdf'");

        $this->post(self::HOST, $this->lessonUrl($this->readingId) . '/delete');

        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lessons WHERE id = ?', [$this->readingId]));
        $this->assertFileDoesNotExist(sys_get_temp_dir() . '/dml-lms-test-storage/uploads/' . $key);
    }

    public function testAssignmentFilesAreVisibleToTheLearnerAndTeachersOnly(): void
    {
        $this->asLearner();
        $url = '/courses/servers/lessons/' . $this->assignmentId . '/submissions';
        $four = [];
        foreach (['a', 'b', 'c', 'd'] as $n) {
            $four[] = $this->fakeUpload($n . '.txt', "file $n\n");
        }
        $tooMany = $this->upload(self::HOST, $url, ['answer' => '', 'link' => ''], ['attachments' => $four]);
        $this->assertSame(422, $tooMany->status);
        $this->assertStringContainsString('Attach at most 3 files', $tooMany->body);

        $this->upload(self::HOST, $url, ['answer' => '', 'link' => ''], ['attachments' => [$this->fakeUpload('my-config.conf', "PermitRootLogin no\n")]]);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM assignment_submissions'), 'a file alone is a valid submission');
        $id = $this->fileId('my-config.conf');
        $this->assertStringContainsString('my-config.conf', $this->get(self::HOST, '/courses/servers/lessons/' . $this->assignmentId)->body);
        $this->assertSame(200, $this->get(self::HOST, '/files/' . $id)->status, 'the learner who sent it');

        // While waiting for review, adding more files keeps the earlier ones, up to three in all.
        $this->upload(self::HOST, $url, ['answer' => 'Notes', 'link' => ''], ['attachments' => [$this->fakeUpload('two.txt', "2\n"), $this->fakeUpload('three.txt', "3\n")]]);
        $this->assertSame(3, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files WHERE submission_id IS NOT NULL'));
        $this->assertSame(422, $this->upload(self::HOST, $url, ['answer' => 'Notes', 'link' => ''], ['attachments' => [$this->fakeUpload('four.txt', "4\n")]])->status);

        $this->asLearner('ngozi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $id)->status, 'another learner');

        $this->asTeacher();
        $this->assertSame(200, $this->get(self::HOST, '/files/' . $id)->status, 'teachers reviewing it');
        $submission = (int) $this->db()->fetchValue('SELECT id FROM assignment_submissions');
        $this->assertStringContainsString('my-config.conf', $this->get(self::HOST, '/teach/reviews/' . $submission)->body);
    }

    public function testTooLargeRequestGetsAPlainAnswer(): void
    {
        $this->asTeacher();
        $request = new Request('POST', $this->lessonUrl($this->readingId) . '/files', self::HOST, [], [], [], [], '0.0.0.0', false, [], true);

        $response = $this->send($request);

        $this->assertSame(413, $response->status);
        $this->assertStringContainsString('too much to send at once', $response->body);
    }

    public function testUploadsAreRefusedWhenTheVirusScannerIsDown(): void
    {
        $this->configure('UPLOAD_SCANNER', 'clamd');
        $this->configure('CLAMD_PORT', '1');
        $this->asTeacher();

        $response = $this->attach('notes.pdf', self::pdfBytes());

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('cannot be checked for viruses right now', $response->body);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files'));
    }
}
