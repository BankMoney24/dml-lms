<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Http\UploadedFile;
use App\Modules\Courses\CourseAuthoring;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0029: video and audio lessons with uploaded media, streamed in byte ranges. */
final class LessonMediaTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const MEDIA = __DIR__ . '/../Fixtures/media/';

    private Tenant $tenant;
    private int $courseId;
    private int $videoId;
    private int $audioId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'ngozi@school.test', Role::Learner);

        $courses = $this->app->get(CourseRepository::class);
        $lessons = $this->app->get(LessonRepository::class);
        $course = $courses->create($this->tenant->id, $teacher->id, 'radio', 'Radio basics', '');
        $module = $lessons->addModule($this->tenant->id, $course->id, 'Module');
        $this->videoId = $lessons->addLesson($this->tenant->id, $course->id, $module, Lesson::TYPE_VIDEO, 'Watch the antenna', 'Notes.');
        $this->audioId = $lessons->addLesson($this->tenant->id, $course->id, $module, Lesson::TYPE_AUDIO, 'Listen: the call signs', '');
        $this->courseId = $course->id;
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    private function media(string $fixture, string $as): UploadedFile
    {
        $copy = (string) tempnam(sys_get_temp_dir(), 'med');
        copy(self::MEDIA . $fixture, $copy);

        return UploadedFile::fromLocalPath($copy, $as);
    }

    private function uploadMedia(int $lessonId, UploadedFile $file): Response
    {
        return $this->upload(self::HOST, sprintf('/teach/courses/%d/lessons/%d/media', $this->courseId, $lessonId), [], ['media' => [$file]]);
    }

    private function lesson(int $id): Lesson
    {
        return $this->app->get(LessonRepository::class)->findById($this->tenant->id, $id) ?? throw new \RuntimeException('lesson');
    }

    /** @return list<string> */
    private function problems(): array
    {
        $course = $this->app->get(CourseRepository::class)->findById($this->tenant->id, $this->courseId) ?? throw new \RuntimeException('course');

        return $this->app->get(CourseAuthoring::class)->publishProblems($course);
    }

    private function rangeGet(string $path, ?string $range): Response
    {
        return $this->send(new Request('GET', $path, self::HOST, [], [], $range === null ? [] : ['range' => $range]));
    }

    private static function streamed(Response $response): string
    {
        ob_start();
        ob_start();
        $response->send();
        ob_end_flush();

        return (string) ob_get_clean();
    }

    public function testTeachersUploadTheVideoAndRecordingAndCannotMixThemUp(): void
    {
        $this->assertContains('Upload a video or add a YouTube or Vimeo link to the video lesson “Watch the antenna”.', $this->problems());
        $this->assertContains('Upload the recording for the audio lesson “Listen: the call signs”.', $this->problems());

        $this->as('teacher@school.test');
        $wrong = $this->uploadMedia($this->videoId, $this->media('tone.mp3', 'tone.mp3'));
        $this->assertSame(422, $wrong->status);
        $this->assertStringContainsString('Choose a video file: MP4 or WebM', $wrong->body);
        $this->assertSame(422, $this->uploadMedia($this->audioId, $this->media('clip.mp4', 'clip.mp4'))->status);
        $this->assertSame(422, $this->uploadMedia($this->videoId, $this->media('tone.mp3', 'fake.mp4'))->status, 'contents must match the name');

        $uploaded = $this->uploadMedia($this->videoId, $this->media('clip.mp4', 'Antenna demo.mp4'));
        $this->assertRedirectTo(sprintf('/teach/courses/%d/lessons/%d#lesson-media', $this->courseId, $this->videoId), $uploaded);
        $this->uploadMedia($this->audioId, $this->media('tone.mp3', 'Call signs.mp3'));
        $this->assertSame([], $this->problems());

        $first = (int) $this->lesson($this->videoId)->mediaFileId;
        $this->uploadMedia($this->videoId, $this->media('clip.mp4', 'Antenna demo v2.mp4'));
        $this->assertNotSame($first, $this->lesson($this->videoId)->mediaFileId, 'replaced');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files WHERE lesson_id = ?', [$this->videoId]), 'the old video is deleted');

        $editor = $this->get(self::HOST, sprintf('/teach/courses/%d/lessons/%d', $this->courseId, $this->videoId))->body;
        $this->assertStringContainsString('<video controls preload="none" playsinline>', $editor);
        $this->assertStringContainsString('data-progress', $editor, 'big uploads show progress');

        $this->post(self::HOST, sprintf('/teach/courses/%d/lessons/%d/media/remove', $this->courseId, $this->audioId));
        $this->assertNull($this->lesson($this->audioId)->mediaFileId);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM files WHERE lesson_id = ?', [$this->audioId]));

        $this->as('tobi@school.test');
        $this->assertContains($this->uploadMedia($this->audioId, $this->media('tone.mp3', 'tone.mp3'))->status, [403, 404], 'learners cannot');
    }

    public function testLearnersPlayMediaInRangesAndCompleteAudioLessons(): void
    {
        $this->as('teacher@school.test');
        $this->uploadMedia($this->videoId, $this->media('clip.mp4', 'Antenna demo.mp4'));
        $this->uploadMedia($this->audioId, $this->media('tone.mp3', 'Call signs.mp3'));
        $this->app->get(CourseRepository::class)->markPublished($this->tenant->id, $this->courseId);
        $video = (int) $this->lesson($this->videoId)->mediaFileId;
        $size = filesize(self::MEDIA . 'clip.mp4');

        $this->as('ngozi@school.test');
        $this->assertSame(404, $this->get(self::HOST, '/files/' . $video)->status, 'not enrolled');

        $this->as('tobi@school.test');
        $this->post(self::HOST, '/courses/radio/enrol');
        $page = $this->get(self::HOST, '/courses/radio/lessons/' . $this->videoId)->body;
        $this->assertStringContainsString('<source src="/files/' . $video . '" type="video/mp4">', $page);
        $this->assertStringContainsString('Nothing downloads until you press play.', $page);
        $this->assertStringNotContainsString('class="file-list"', $page, 'the video is the player, not also a download in the list');

        $whole = $this->rangeGet('/files/' . $video, null);
        $this->assertSame(200, $whole->status);
        $this->assertSame('bytes', $whole->header('Accept-Ranges'));
        $this->assertSame(file_get_contents(self::MEDIA . 'clip.mp4'), self::streamed($whole));

        $part = $this->rangeGet('/files/' . $video, 'bytes=0-99');
        $this->assertSame(206, $part->status);
        $this->assertSame('bytes 0-99/' . $size, $part->header('Content-Range'));
        $this->assertSame('100', $part->header('Content-Length'));
        $this->assertSame(substr((string) file_get_contents(self::MEDIA . 'clip.mp4'), 0, 100), self::streamed($part));

        $tail = $this->rangeGet('/files/' . $video, 'bytes=-10');
        $this->assertSame([$size - 10, $size - 1], $tail->byteRange());
        $open = $this->rangeGet('/files/' . $video, 'bytes=' . ($size - 5) . '-');
        $this->assertSame('bytes ' . ($size - 5) . '-' . ($size - 1) . '/' . $size, $open->header('Content-Range'));
        $beyond = $this->rangeGet('/files/' . $video, 'bytes=' . $size . '-');
        $this->assertSame(416, $beyond->status);
        $this->assertSame('bytes */' . $size, $beyond->header('Content-Range'));
        $this->assertSame(200, $this->rangeGet('/files/' . $video, 'bytes=0-1,5-9')->status, 'several ranges: the whole file');

        $audioPage = $this->get(self::HOST, '/courses/radio/lessons/' . $this->audioId)->body;
        $this->assertStringContainsString('<audio controls preload="none">', $audioPage);
        $this->assertStringContainsString('I have listened: mark complete', $audioPage);
        $this->post(self::HOST, '/courses/radio/lessons/' . $this->audioId . '/complete');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lesson_progress WHERE lesson_id = ? AND completed_at IS NOT NULL', [$this->audioId]));
    }

    public function testFreePreviewMediaPlaysForVisitorsOnly(): void
    {
        $this->as('teacher@school.test');
        $this->uploadMedia($this->videoId, $this->media('clip.mp4', 'Antenna demo.mp4'));
        $this->uploadMedia($this->audioId, $this->media('tone.mp3', 'Call signs.mp3'));
        $this->app->get(CourseRepository::class)->markPublished($this->tenant->id, $this->courseId);
        $this->db()->execute('UPDATE lessons SET is_preview = 1 WHERE id = ?', [$this->audioId]);
        $this->db()->execute('UPDATE tenants SET public_catalogue = 1 WHERE id = ?', [$this->tenant->id]);

        $this->newBrowser();
        $page = $this->get(self::HOST, '/courses/radio/preview/' . $this->audioId)->body;
        $this->assertStringContainsString('<source src="/courses/radio/preview/' . $this->audioId . '/media" type="audio/mpeg">', $page);
        $media = $this->get(self::HOST, '/courses/radio/preview/' . $this->audioId . '/media');
        $this->assertSame(200, $media->status);
        $this->assertSame('audio/mpeg', $media->header('Content-Type'));
        $this->assertSame(404, $this->get(self::HOST, '/courses/radio/preview/' . $this->videoId . '/media')->status, 'not a preview lesson');
    }
}
