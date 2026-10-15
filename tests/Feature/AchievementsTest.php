<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0023: achievements come from real activity, once, with the right time. */
final class AchievementsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $ada;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->ada = $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->fx = $this->makeCourse($this->tenant, $teacher, 'python-basics');
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    /** @return list<string> */
    private function codes(int $userId): array
    {
        return array_map('strval', array_column($this->db()->fetchAll('SELECT code FROM achievements WHERE user_id = ? ORDER BY code', [$userId]), 'code'));
    }

    public function testLearningEarnsAchievementsOnceWithTheirRealTime(): void
    {
        $this->as('ada@school.test');
        $this->assertStringContainsString('Not yet', $this->get(self::HOST, '/achievements')->body);
        $this->assertSame([], $this->codes($this->ada->id));

        $this->post(self::HOST, '/courses/python-basics/enrol');
        $this->post(self::HOST, '/courses/python-basics/lessons/' . $this->fx['reading']->id . '/complete');
        $this->post(self::HOST, '/courses/python-basics/lessons/' . $this->fx['quiz']->id . '/attempts', [
            'answers' => $this->answers($this->fx['assessment_id'], true),
            'idempotency_key' => str_repeat('a', 32),
        ]);
        $home = $this->get(self::HOST, '/')->body;
        $this->assertSame(['first-course', 'first-lesson', 'full-marks'], $this->codes($this->ada->id));
        $this->assertStringContainsString('See all achievements', $home);
        $this->assertSame(3, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND kind = 'achievement'", [$this->ada->id]));

        $completedAt = (string) $this->db()->fetchValue('SELECT completed_at FROM enrolments WHERE user_id = ?', [$this->ada->id]);
        $this->assertSame($completedAt, (string) $this->db()->fetchValue("SELECT earned_at FROM achievements WHERE user_id = ? AND code = 'first-course'", [$this->ada->id]));

        $this->get(self::HOST, '/');
        $this->get(self::HOST, '/achievements');
        $this->assertSame(3, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'achievement'"), 'each is told once');
        $page = $this->get(self::HOST, '/achievements')->body;
        $this->assertStringContainsString('You have earned 3 of 10', $page);
        $this->assertStringContainsString('Full marks', $page);
    }

    public function testOldActivityIsRecordedQuietly(): void
    {
        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/python-basics/enrol');
        $this->post(self::HOST, '/courses/python-basics/lessons/' . $this->fx['reading']->id . '/complete');
        $this->db()->execute('UPDATE lesson_progress SET completed_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 30 DAY)');
        $this->get(self::HOST, '/achievements');
        $this->assertSame(['first-lesson'], $this->codes($this->ada->id));
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM notifications WHERE kind = 'achievement'"));
    }

    public function testQuestionsHelpingAndCodeCount(): void
    {
        foreach (['ada@school.test', 'tobi@school.test'] as $email) {
            $this->as($email);
            $this->post(self::HOST, '/courses/python-basics/enrol');
        }
        $this->post(self::HOST, '/courses/python-basics/lessons/' . $this->fx['reading']->id . '/questions', ['question' => 'How do I print two values?']);
        $question = (int) $this->db()->fetchValue('SELECT MAX(id) FROM lesson_questions');

        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/python-basics/questions/' . $question . '/answers', ['answer' => 'Separate them with a comma.']);
        for ($i = 1; $i <= 5; $i++) {
            $this->post(self::HOST, '/playground', ['title' => 'Snippet ' . $i, 'code' => 'console.log(' . $i . ')']);
        }
        $answer = (int) $this->db()->fetchValue('SELECT MAX(id) FROM lesson_answers');
        $this->get(self::HOST, '/achievements');
        $this->assertSame(['coder'], $this->codes($this->ada->id), 'an answer counts only once it is marked');

        $this->as('tobi@school.test');
        $this->post(self::HOST, '/courses/python-basics/questions/' . $question . '/accept', ['answer' => (string) $answer]);
        $this->get(self::HOST, '/achievements');
        $tobi = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'tobi@school.test'");
        $this->assertSame(['curious'], $this->codes($tobi));

        $this->as('ada@school.test');
        $this->get(self::HOST, '/achievements');
        $this->assertSame(['coder', 'helper'], $this->codes($this->ada->id));
    }

    public function testAchievementsBelongToOneSchool(): void
    {
        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'ada@school.test', Role::Learner);
        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/python-basics/enrol');
        $this->post(self::HOST, '/courses/python-basics/lessons/' . $this->fx['reading']->id . '/complete');
        $this->get(self::HOST, '/');

        $this->newBrowser();
        $this->login('other.test', 'ada@school.test', 'correct-horse-battery');
        $this->assertStringContainsString('Earn these by learning', $this->get('other.test', '/achievements')->body);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM achievements WHERE tenant_id = ?', [$other->id]));

        $this->as('teacher@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/achievements')->status, 'for people who learn');
    }
}
