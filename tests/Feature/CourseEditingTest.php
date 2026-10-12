<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class CourseEditingTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $fixture;
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->fixture = $this->makeCourse($this->tenant, $teacher);
        $this->base = '/teach/courses/' . $this->fixture['course']->id;
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
    }

    private function asLearner(callable $steps): void
    {
        $teacher = $this->session;
        $this->newBrowser();
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $steps();
        $this->useBrowser($teacher);
    }

    public function testEditLessonTextAndQuizSettings(): void
    {
        $quiz = $this->fixture['quiz'];
        $response = $this->post(self::HOST, $this->base . '/lessons/' . $quiz->id, [
            'lesson_title' => 'Final check', 'body' => 'Answer carefully.', 'pass_threshold' => '80', 'max_attempts' => '2',
        ]);

        $this->assertRedirectTo($this->base . '/lessons/' . $quiz->id, $response);
        $this->assertSame('Final check', $this->db()->fetchValue('SELECT title FROM lessons WHERE id = ?', [$quiz->id]));
        $this->assertSame(80, (int) $this->db()->fetchValue('SELECT pass_threshold FROM assessments WHERE id = ?', [$this->fixture['assessment_id']]));
        $this->assertSame(2, (int) $this->db()->fetchValue('SELECT max_attempts FROM assessments WHERE id = ?', [$this->fixture['assessment_id']]));
    }

    public function testInvalidEditKeepsTypedTextAndExplains(): void
    {
        $response = $this->post(self::HOST, $this->base . '/lessons/' . $this->fixture['reading']->id, [
            'lesson_title' => 'x', 'body' => 'Kept <text>',
        ]);

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('There is a problem', $response->body);
        $this->assertStringContainsString('Kept &lt;text&gt;', $response->body);
    }

    public function testReorderLessonsWithinModule(): void
    {
        $order = fn (): array => array_map('intval', array_column(
            $this->db()->fetchAll('SELECT id FROM lessons ORDER BY position'),
            'id',
        ));
        $this->assertSame([$this->fixture['reading']->id, $this->fixture['quiz']->id], $order());

        $this->post(self::HOST, $this->base . '/lessons/' . $this->fixture['quiz']->id . '/move', ['direction' => 'up']);
        $this->assertSame([$this->fixture['quiz']->id, $this->fixture['reading']->id], $order());

        // Already first: nothing changes.
        $this->post(self::HOST, $this->base . '/lessons/' . $this->fixture['quiz']->id . '/move', ['direction' => 'up']);
        $this->assertSame([$this->fixture['quiz']->id, $this->fixture['reading']->id], $order());
    }

    public function testLessonsLearnersHaveUsedCannotBeDeleted(): void
    {
        $reading = $this->fixture['reading'];
        $this->asLearner(function () use ($reading): void {
            $this->post(self::HOST, '/courses/physics-101/enrol');
            $this->get(self::HOST, '/courses/physics-101/lessons/' . $reading->id);
        });

        $this->post(self::HOST, $this->base . '/lessons/' . $reading->id . '/delete');

        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lessons WHERE id = ?', [$reading->id]));
    }

    public function testUnusedLessonCanBeDeleted(): void
    {
        $this->post(self::HOST, $this->base . '/lessons/' . $this->fixture['reading']->id . '/delete');

        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lessons WHERE id = ?', [$this->fixture['reading']->id]));
    }

    public function testQuestionsAreLockedOnceAnswered(): void
    {
        $quiz = $this->fixture['quiz'];
        $questionId = (int) $this->db()->fetchValue('SELECT id FROM questions ORDER BY id LIMIT 1');
        $answers = $this->answers($this->fixture['assessment_id'], true);
        $this->asLearner(function () use ($quiz, $answers): void {
            $this->post(self::HOST, '/courses/physics-101/enrol');
            $this->post(self::HOST, '/courses/physics-101/lessons/' . $quiz->id . '/attempts', [
                'answers' => $answers, 'idempotency_key' => str_repeat('e', 32),
            ]);
        });

        $this->post(self::HOST, $this->base . '/lessons/' . $quiz->id . '/questions/' . $questionId . '/delete');

        $this->assertSame(2, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM questions'));
    }

    public function testUnpublishHidesCourseButKeepsProgress(): void
    {
        $this->asLearner(function (): void {
            $this->post(self::HOST, '/courses/physics-101/enrol');
        });
        $this->post(self::HOST, $this->base . '/unpublish');

        $this->assertSame('draft', $this->db()->fetchValue('SELECT status FROM courses'));
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments'));
        $this->asLearner(function (): void {
            $this->assertSame(404, $this->get(self::HOST, '/courses/physics-101')->status);
        });
    }

    public function testPreviewShowsAnswersAndRecordsNothing(): void
    {
        $body = $this->get(self::HOST, $this->base . '/lessons/' . $this->fixture['quiz']->id . '/preview')->body;

        $this->assertStringContainsString('Correct', $body);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lesson_progress'));
    }

    public function testModulesRenameAndOnlyEmptyOnesDelete(): void
    {
        $moduleId = $this->fixture['reading']->moduleId;
        $this->post(self::HOST, $this->base . '/modules/' . $moduleId, ['module_title' => 'Waves']);
        $this->post(self::HOST, $this->base . '/modules/' . $moduleId . '/delete');

        $this->assertSame('Waves', $this->db()->fetchValue('SELECT title FROM course_modules WHERE id = ?', [$moduleId]));

        $this->post(self::HOST, $this->base . '/modules', ['module_title' => 'Spare']);
        $spare = (int) $this->db()->fetchValue("SELECT id FROM course_modules WHERE title = 'Spare'");
        $this->post(self::HOST, $this->base . '/modules/' . $spare . '/delete');
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM course_modules WHERE id = ?', [$spare]));
    }

    public function testProgressReportListsLearnersAndBestScores(): void
    {
        $quiz = $this->fixture['quiz'];
        $answers = $this->answers($this->fixture['assessment_id'], true);
        $this->asLearner(function () use ($quiz, $answers): void {
            $this->post(self::HOST, '/courses/physics-101/enrol');
            $this->post(self::HOST, '/courses/physics-101/lessons/' . $quiz->id . '/attempts', [
                'answers' => $answers, 'idempotency_key' => str_repeat('f', 32),
            ]);
        });

        $body = $this->get(self::HOST, $this->base . '/progress')->body;

        $this->assertStringContainsString('learner@school.test', $body);
        $this->assertStringContainsString('✓ 100%', $body);
    }

    public function testCourseDetailsEdit(): void
    {
        $this->post(self::HOST, $this->base . '/details', ['title' => 'Physics for Everyone', 'summary' => 'New summary']);

        $this->assertSame('Physics for Everyone', $this->db()->fetchValue('SELECT title FROM courses'));
        $this->assertSame('physics-101', $this->db()->fetchValue('SELECT slug FROM courses'), 'links keep working');
    }
}
