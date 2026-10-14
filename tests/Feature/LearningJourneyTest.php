<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class LearningJourneyTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->fixture = $this->makeCourse($this->tenant, $teacher, 'physics-101', 3);
    }

    /** @param 'reading'|'quiz' $which */
    private function lessonUrl(string $which): string
    {
        return '/courses/physics-101/lessons/' . $this->fixture[$which]->id;
    }

    private function enrol(): void
    {
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/physics-101/enrol');
    }

    public function testGuestIsSentToSignInBeforeLessons(): void
    {
        $this->assertRedirectTo('/login', $this->get(self::HOST, $this->lessonUrl('reading')));
    }

    public function testLessonsRequireEnrolment(): void
    {
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');

        $this->assertRedirectTo('/courses/physics-101#start', $this->get(self::HOST, $this->lessonUrl('reading')), 'sent to the course page to join');
        $this->assertStringContainsString('Join the course to open', $this->get(self::HOST, '/courses/physics-101')->body);
    }

    public function testEnrolmentIsIdempotentAndOpensFirstLesson(): void
    {
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');

        $first = $this->post(self::HOST, '/courses/physics-101/enrol');
        $this->post(self::HOST, '/courses/physics-101/enrol');

        $this->assertRedirectTo($this->lessonUrl('reading'), $first);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments'));
    }

    public function testFullJourneyToCourseCompletion(): void
    {
        $this->enrol();
        $this->assertStringContainsString('Secret text of school', $this->get(self::HOST, $this->lessonUrl('reading'))->body);
        $this->post(self::HOST, $this->lessonUrl('reading') . '/complete');

        // Failing attempt: recorded, course not complete.
        $fail = $this->post(self::HOST, $this->lessonUrl('quiz') . '/attempts', [
            'answers' => $this->answers($this->fixture['assessment_id'], false),
            'idempotency_key' => str_repeat('1', 32),
        ]);
        $this->assertStringContainsString('Not passed yet', $this->get(self::HOST, (string) $fail->header('Location'))->body);
        $this->assertSame('active', $this->db()->fetchValue('SELECT status FROM enrolments'));

        // Passing attempt completes the course.
        $pass = $this->post(self::HOST, $this->lessonUrl('quiz') . '/attempts', [
            'answers' => $this->answers($this->fixture['assessment_id'], true),
            'idempotency_key' => str_repeat('2', 32),
        ]);
        $result = $this->get(self::HOST, (string) $pass->header('Location'));
        $this->assertStringContainsString('You passed', $result->body);
        $this->assertStringContainsString('completed the whole course', $result->body);
        $this->assertSame('completed', $this->db()->fetchValue('SELECT status FROM enrolments'));
    }

    public function testResubmittingTheSameAttemptRecordsItOnce(): void
    {
        $this->enrol();
        $payload = [
            'answers' => $this->answers($this->fixture['assessment_id'], true),
            'idempotency_key' => str_repeat('c', 32),
        ];

        $first = $this->post(self::HOST, $this->lessonUrl('quiz') . '/attempts', $payload);
        $second = $this->post(self::HOST, $this->lessonUrl('quiz') . '/attempts', $payload);

        $this->assertSame($first->header('Location'), $second->header('Location'));
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM quiz_attempts'));
    }

    public function testAttemptLimitIsEnforcedOnTheServer(): void
    {
        $this->enrol();
        for ($i = 0; $i < 3; $i++) {
            $this->post(self::HOST, $this->lessonUrl('quiz') . '/attempts', [
                'answers' => [],
                'idempotency_key' => str_repeat((string) $i, 32),
            ]);
        }

        $fourth = $this->post(self::HOST, $this->lessonUrl('quiz') . '/attempts', [
            'answers' => $this->answers($this->fixture['assessment_id'], true),
            'idempotency_key' => str_repeat('9', 32),
        ]);

        $this->assertSame(409, $fourth->status);
        $this->assertSame(3, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM quiz_attempts'));
    }

    public function testQuizCannotBeMarkedCompleteWithoutPassing(): void
    {
        $this->enrol();

        $response = $this->post(self::HOST, $this->lessonUrl('quiz') . '/complete');

        $this->assertSame(400, $response->status);
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM lesson_progress WHERE status = 'completed'"));
    }

    public function testQuizPageDoesNotRevealCorrectAnswers(): void
    {
        $this->enrol();
        $body = $this->get(self::HOST, $this->lessonUrl('quiz'))->body;

        $this->assertStringContainsString('name="idempotency_key"', $body);
        $this->assertStringNotContainsString('is_correct', $body);
        $this->assertStringNotContainsString('correct"', $body);
    }

    public function testLearnerCannotReachTeachingPages(): void
    {
        $this->enrol();

        $this->assertSame(403, $this->get(self::HOST, '/teach')->status);
        $this->assertSame(403, $this->post(self::HOST, '/teach/courses', ['title' => 'Mine'])->status);
    }
}
