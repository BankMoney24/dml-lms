<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Video lessons, Markdown text, course details and quiz explanations. */
final class RichCourseTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $teacher;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
    }

    /** @return array{course: Course, module: int} a published course with one reading lesson */
    private function draftCourse(): array
    {
        $courses = $this->app->get(CourseRepository::class);
        $lessons = $this->app->get(LessonRepository::class);
        $course = $courses->create($this->tenant->id, $this->teacher->id, 'servers', 'Servers', 'All about servers');
        $module = $lessons->addModule($this->tenant->id, $course->id, 'Module one');
        $lessons->addLesson($this->tenant->id, $course->id, $module, Lesson::TYPE_TEXT, 'Intro', "## Start here\n\n```bash\nls -la <dir>\n```\n\n<script>alert(1)</script>");
        $courses->markPublished($this->tenant->id, $course->id);

        return ['course' => $courses->findById($this->tenant->id, $course->id) ?? $course, 'module' => $module];
    }

    private function lessonId(int $courseId, string $title): int
    {
        return (int) $this->db()->fetchValue('SELECT id FROM lessons WHERE course_id = ? AND title = ?', [$courseId, $title]);
    }

    public function testTeacherAddsAVideoLessonAndLearnersGetAClickToLoadPlayer(): void
    {
        ['course' => $course, 'module' => $module] = $this->draftCourse();
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');

        $bad = $this->post(self::HOST, '/teach/courses/' . $course->id . '/lessons', [
            'module_id' => (string) $module, 'type' => 'video', 'lesson_title' => 'Watch this', 'body' => '', 'media_url' => 'https://evil.example/v.mp4',
        ]);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Paste a YouTube or Vimeo link', $bad->body);

        $this->post(self::HOST, '/teach/courses/' . $course->id . '/lessons', [
            'module_id' => (string) $module, 'type' => 'video', 'lesson_title' => 'Watch this', 'body' => 'Notes **below**', 'media_url' => 'youtu.be/dQw4w9WgXcQ',
        ]);
        $id = $this->lessonId($course->id, 'Watch this');
        $this->assertSame('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $this->db()->fetchValue('SELECT media_url FROM lessons WHERE id = ?', [$id]), 'stored in one canonical form');

        $this->newBrowser();
        $this->login(self::HOST, 'tobi@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/servers/enrol');
        $page = $this->get(self::HOST, '/courses/servers/lessons/' . $id);

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('data-embed="https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ?rel=0"', $page->body);
        $this->assertStringNotContainsString('<iframe', $page->body, 'nothing loads until the learner asks');
        $this->assertStringContainsString('Notes <strong>below</strong>', $page->body);
        $this->assertStringContainsString("frame-src 'self' https://www.youtube-nocookie.com https://player.vimeo.com", (string) $page->header('Content-Security-Policy'));

        $this->assertRedirectTo('/courses/servers', $this->post(self::HOST, '/courses/servers/lessons/' . $id . '/complete'), 'last lesson: back to the course');
    }

    public function testLessonTextIsRenderedAsSafeMarkdown(): void
    {
        $this->draftCourse();
        $this->login(self::HOST, 'tobi@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/servers/enrol');
        $id = (int) $this->db()->fetchValue("SELECT id FROM lessons WHERE title = 'Intro'");

        $body = $this->get(self::HOST, '/courses/servers/lessons/' . $id)->body;

        $this->assertStringContainsString('<h2>Start here</h2>', $body);
        $this->assertStringContainsString('<code class="language-bash">ls -la &lt;dir&gt;</code>', $body);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $body);
        $this->assertStringContainsString('1 min read', $body);
    }

    public function testCourseDetailsAppearOnTheCoursePage(): void
    {
        ['course' => $course] = $this->draftCourse();
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');

        $bad = $this->post(self::HOST, '/teach/courses/' . $course->id . '/details', [
            'title' => 'Servers', 'summary' => 'x', 'level' => 'expert', 'duration_hours' => 'ten', 'outcomes' => '', 'prerequisites' => '',
        ]);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Choose beginner, intermediate or advanced.', $bad->body);

        $this->post(self::HOST, '/teach/courses/' . $course->id . '/details', [
            'title' => 'Servers', 'summary' => 'All about servers', 'level' => 'advanced', 'duration_hours' => '40',
            'outcomes' => "- Harden SSH\n- Restore a backup\n\n", 'prerequisites' => 'A laptop and **patience**.',
        ]);

        $page = $this->get(self::HOST, '/courses/servers')->body;
        $this->assertStringContainsString('Advanced', $page);
        $this->assertStringContainsString('About 40 hours', $page);
        $this->assertStringContainsString('<li>Harden SSH</li><li>Restore a backup</li>', $page);
        $this->assertStringContainsString('A laptop and <strong>patience</strong>.', $page);
    }

    public function testQuizExplanationsAppearOnlyAfterPassing(): void
    {
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
        ['course' => $course, 'quiz' => $quiz, 'assessment_id' => $assessmentId] = $this->makeCourse($this->tenant, $this->teacher);
        $this->post(self::HOST, sprintf('/teach/courses/%d/lessons/%d/questions', $course->id, $quiz->id), [
            'prompt' => 'Third question', 'options' => ['right', 'wrong'], 'correct' => '0', 'explanation' => 'Because **right** is right.',
        ]);
        $first = (int) $this->db()->fetchValue("SELECT id FROM questions WHERE assessment_id = ? AND prompt = 'Q1'", [$assessmentId]);
        $this->post(self::HOST, sprintf('/teach/courses/%d/lessons/%d/questions/%d/explanation', $course->id, $quiz->id, $first), [
            'explanation' => 'Q1 explained.',
        ]);

        $this->newBrowser();
        $this->login(self::HOST, 'tobi@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/physics-101/enrol');
        $lessonPage = $this->get(self::HOST, '/courses/physics-101/lessons/' . $quiz->id)->body;
        $this->assertStringNotContainsString('explained', $lessonPage, 'never before an attempt');

        $failed = $this->post(self::HOST, '/courses/physics-101/lessons/' . $quiz->id . '/attempts', [
            'answers' => $this->answers($assessmentId, false), 'idempotency_key' => str_repeat('a', 32),
        ]);
        $result = $this->get(self::HOST, (string) $failed->header('Location'))->body;
        $this->assertStringContainsString('Not right yet', $result);
        $this->assertStringNotContainsString('Q1 explained.', $result);
        $this->assertStringNotContainsString('Correct answer:', $result);

        $passed = $this->post(self::HOST, '/courses/physics-101/lessons/' . $quiz->id . '/attempts', [
            'answers' => $this->answers($assessmentId, true), 'idempotency_key' => str_repeat('b', 32),
        ]);
        $result = $this->get(self::HOST, (string) $passed->header('Location'))->body;
        $this->assertStringContainsString('Q1 explained.', $result);
        $this->assertStringContainsString('Because <strong>right</strong> is right.', $result);

        // The earlier failed attempt now shows explanations too, since the learner has passed.
        $this->assertStringContainsString('Correct answer:', $this->get(self::HOST, (string) $failed->header('Location'))->body);
    }

    public function testOutlineShowsLessonTypes(): void
    {
        ['course' => $course, 'module' => $module] = $this->draftCourse();
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/teach/courses/' . $course->id . '/lessons', [
            'module_id' => (string) $module, 'type' => 'assignment', 'lesson_title' => 'Build it', 'body' => '',
        ]);
        $this->assertSame(1, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM lessons WHERE title = 'Intro'"), 'the empty assignment was refused');
        $this->post(self::HOST, '/teach/courses/' . $course->id . '/lessons', [
            'module_id' => (string) $module, 'type' => 'assignment', 'lesson_title' => 'Build it', 'body' => 'Build a thing and describe it.',
        ]);

        $outline = $this->get(self::HOST, '/teach/courses/' . $course->id)->body;
        $this->assertStringContainsString('badge-type-assignment', $outline);
        $this->assertStringContainsString('<strong>Video</strong>', $outline, 'all four types offered');
        $this->assertStringContainsString('Assignment', $this->get(self::HOST, '/courses/servers')->body);
    }
}
