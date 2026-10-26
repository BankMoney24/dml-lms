<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class CompletionRulesTest extends AppTestCase
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
        $this->fixture = $this->makeCourse($this->tenant, $teacher, 'physics-101');
    }

    private function asTeacher(): void
    {
        $this->newBrowser();
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
    }

    private function asLearner(): void
    {
        $this->newBrowser();
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
    }

    private function teach(string $suffix = ''): string
    {
        return '/teach/courses/' . $this->fixture['course']->id . $suffix;
    }

    private function lesson(string $which): string
    {
        return '/courses/physics-101/lessons/' . ($which === 'quiz' ? $this->fixture['quiz']->id : $this->fixture['reading']->id);
    }

    /** Sends the quiz with one of its two questions right (50%, the quiz's pass mark) or both right. */
    private function takeQuiz(bool $both, string $key): void
    {
        $right = $this->answers($this->fixture['assessment_id'], true);
        $wrong = $this->answers($this->fixture['assessment_id'], false);
        $answers = $both ? $right : [array_key_first($right) => reset($right)] + array_slice($wrong, 1, null, true);
        $this->post(self::HOST, $this->lesson('quiz') . '/attempts', ['answers' => $answers, 'idempotency_key' => str_repeat($key, 32)]);
    }

    private function status(): string
    {
        return (string) $this->db()->fetchValue('SELECT status FROM enrolments');
    }

    public function testAnAverageAcrossQuizzesIsNeededWhenTheCourseSetsOne(): void
    {
        $this->asTeacher();
        $this->assertRedirectTo($this->teach('#completion-h'), $this->post(self::HOST, $this->teach('/completion'), ['completion_min_average' => '80%']));
        $edit = $this->get(self::HOST, $this->teach())->body;
        $this->assertStringContainsString('value="80"', $edit);
        $this->assertStringContainsString('<strong>2 of 2</strong> lessons are required', $edit);

        $this->asLearner();
        $this->post(self::HOST, '/courses/physics-101/enrol');
        $this->post(self::HOST, $this->lesson('reading') . '/complete');
        $this->takeQuiz(false, 'a');
        $this->assertSame('active', $this->status(), 'every lesson done, but the average is 50%');
        $page = $this->get(self::HOST, '/courses/physics-101')->body;
        $this->assertStringContainsString('Every lesson is done', $page);
        $this->assertStringContainsString('Your average now: <strong>50%</strong>', $page);

        $this->takeQuiz(true, 'b');
        $this->assertSame('completed', $this->status());
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM certificates'));
    }

    public function testLoweringTheRuleFinishesLearnersWhoNowMeetIt(): void
    {
        $this->asTeacher();
        $this->post(self::HOST, $this->teach('/completion'), ['completion_min_average' => '90']);
        $this->asLearner();
        $this->post(self::HOST, '/courses/physics-101/enrol');
        $this->post(self::HOST, $this->lesson('reading') . '/complete');
        $this->takeQuiz(false, 'c');
        $this->assertSame('active', $this->status());

        $this->asTeacher();
        $this->post(self::HOST, $this->teach('/completion'), ['completion_min_average' => '']);
        $this->assertStringContainsString('1 learner has now finished the course', $this->get(self::HOST, $this->teach())->body);
        $this->assertSame('completed', $this->status());
    }

    public function testOptionalLessonsDoNotBlockFinishingButOneLessonStaysRequired(): void
    {
        $this->asTeacher();
        $readingUrl = $this->teach('/lessons/' . $this->fixture['reading']->id);
        $this->post(self::HOST, $readingUrl, ['lesson_title' => 'Reading', 'body' => 'Extra reading', 'required_shown' => '1']);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT is_mandatory FROM lessons WHERE id = ?', [$this->fixture['reading']->id]));
        $this->assertStringContainsString('<strong>1 of 2</strong> lessons are required', $this->get(self::HOST, $this->teach())->body);

        // The quiz is now the only required lesson: it cannot be made optional too.
        $quizUrl = $this->teach('/lessons/' . $this->fixture['quiz']->id);
        $refused = $this->post(self::HOST, $quizUrl, ['lesson_title' => 'Quiz', 'body' => '', 'pass_threshold' => '50', 'required_shown' => '1']);
        $this->assertSame(422, $refused->status);
        $this->assertStringContainsString('at least one required lesson', $refused->body);

        $this->asLearner();
        $this->post(self::HOST, '/courses/physics-101/enrol');
        $this->takeQuiz(false, 'd');
        $this->assertSame('completed', $this->status(), 'the optional reading was skipped');
    }

    public function testTheAverageMustBeAPercentage(): void
    {
        $this->asTeacher();
        $bad = $this->post(self::HOST, $this->teach('/completion'), ['completion_min_average' => '150']);
        $this->assertSame(422, $bad->status);
        $this->assertStringContainsString('Enter a percentage from 1 to 100', $bad->body);

        $this->asLearner();
        $this->assertSame(403, $this->post(self::HOST, $this->teach('/completion'), ['completion_min_average' => '10'])->status);
    }
}
