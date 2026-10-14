<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Response;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0022: questions and answers under lessons, and the teachers' inbox. */
final class DiscussionsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    /** @var array{course: Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'outsider@school.test', Role::Learner);
        $this->fx = $this->makeCourse($this->tenant, $teacher, 'python-basics');
        foreach (['ada@school.test', 'tobi@school.test'] as $email) {
            $this->as($email);
            $this->post(self::HOST, '/courses/python-basics/enrol');
        }
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    private function lessonPath(): string
    {
        return '/courses/python-basics/lessons/' . $this->fx['reading']->id;
    }

    private function ask(string $text): Response
    {
        return $this->post(self::HOST, $this->lessonPath() . '/questions', ['question' => $text]);
    }

    private function lastId(string $table): int
    {
        return (int) $this->db()->fetchValue('SELECT MAX(id) FROM ' . $table);
    }

    private function notifications(string $kind): int
    {
        return (int) $this->db()->fetchValue('SELECT COUNT(*) FROM notifications WHERE kind = ?', [$kind]);
    }

    public function testAskAnswerAcceptAndTheInbox(): void
    {
        $this->as('ada@school.test');
        $this->assertStringContainsString('No questions yet', $this->get(self::HOST, $this->lessonPath())->body);
        $posted = $this->ask("Why does my loop never stop?\n\n```js\nwhile (true) { console.log('<b>hi</b>') }\n```");
        $qid = $this->lastId('lesson_questions');
        $this->assertRedirectTo($this->lessonPath() . '#q' . $qid, $posted);
        $page = $this->get(self::HOST, $this->lessonPath())->body;
        $this->assertStringContainsString('Why does my loop never stop?', $page);
        $this->assertStringContainsString('&lt;b&gt;hi&lt;/b&gt;', $page, 'code is shown as text');
        $this->assertStringNotContainsString('<b>hi</b>', $page);
        $this->assertSame(1, $this->notifications('qa.question'), 'the course author hears about it');

        // A classmate answers; the asker hears about it and marks it as the answer.
        $this->as('tobi@school.test');
        $this->assertStringContainsString('Why does my loop never stop?', $this->get(self::HOST, $this->lessonPath())->body, 'everyone taking the course can read it');
        $answered = $this->post(self::HOST, '/courses/python-basics/questions/' . $qid . '/answers', ['answer' => 'The condition is always true. Use a counter.']);
        $this->assertRedirectTo($this->lessonPath() . '#q' . $qid, $answered);
        $aid = $this->lastId('lesson_answers');
        $this->assertSame(1, $this->notifications('qa.answer'));
        $this->assertSame(403, $this->post(self::HOST, '/courses/python-basics/questions/' . $qid . '/accept', ['answer' => (string) $aid])->status, 'only the asker or a teacher marks the answer');

        $this->as('teacher@school.test');
        $inbox = $this->get(self::HOST, '/teach/questions')->body;
        $this->assertStringContainsString('Waiting for a teacher <span class="tab-count">(1)</span>', $inbox);
        $this->assertStringContainsString('Why does my loop never stop?', $inbox);
        $this->assertMatchesRegularExpression('#href="/teach/questions"[^>]*>.*?<span[^>]*>1<#s', $inbox, 'the menu shows how many are waiting');

        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/python-basics/questions/' . $qid . '/accept', ['answer' => (string) $aid]);
        $this->assertSame($aid, (int) $this->db()->fetchValue('SELECT accepted_answer_id FROM lesson_questions WHERE id = ?', [$qid]));
        $this->assertSame(1, $this->notifications('qa.accepted'));
        $this->assertStringContainsString('The answer', $this->get(self::HOST, $this->lessonPath())->body);

        $this->as('teacher@school.test');
        $this->assertStringContainsString('Nothing waiting', $this->get(self::HOST, '/teach/questions')->body);
    }

    public function testTeachersAnswerFromTheInbox(): void
    {
        $this->as('ada@school.test');
        $this->ask('What does the second paragraph mean?');
        $qid = $this->lastId('lesson_questions');

        $this->as('teacher@school.test');
        $reply = $this->post(self::HOST, '/courses/python-basics/questions/' . $qid . '/answers', ['answer' => 'It means the order matters.', 'back' => 'teach']);
        $this->assertRedirectTo('/teach/questions#q' . $qid, $reply);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT staff_answered FROM lesson_questions WHERE id = ?', [$qid]));
        $this->assertStringContainsString('Your teacher answered your question', (string) $this->db()->fetchValue("SELECT title FROM notifications WHERE kind = 'qa.answer'"));

        $this->as('ada@school.test');
        $page = $this->get(self::HOST, $this->lessonPath())->body;
        $this->assertStringContainsString('Teacher replied', $page);
        $this->assertStringContainsString('It means the order matters.', $page);
    }

    public function testOnlyPeopleTakingTheCourseTakePart(): void
    {
        $this->as('ada@school.test');
        $this->ask('A question only classmates should see.');
        $qid = $this->lastId('lesson_questions');

        $this->as('outsider@school.test');
        $this->assertSame(403, $this->ask('Can I ask without joining?')->status);
        $this->assertSame(403, $this->post(self::HOST, '/courses/python-basics/questions/' . $qid . '/answers', ['answer' => 'Sneaky'])->status);

        // Even when the course is open to everyone, questions stay with its members.
        $this->app->get(CourseRepository::class)->updateAccess($this->tenant->id, $this->fx['course']->id, Course::ACCESS_OPEN);
        $this->newBrowser();
        $open = $this->get(self::HOST, '/courses/python-basics/preview/' . $this->fx['reading']->id)->body;
        $this->assertStringNotContainsString('A question only classmates should see.', $open);

        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'ada@school.test', Role::Learner);
        $this->newBrowser();
        $this->login('other.test', 'ada@school.test', 'correct-horse-battery');
        $this->assertSame(404, $this->post('other.test', '/courses/python-basics/questions/' . $qid . '/answers', ['answer' => 'From another school'])->status);
    }

    public function testMistakesKeepWhatWasTypedAndLimitsApply(): void
    {
        $this->as('ada@school.test');
        $this->assertRedirectTo($this->lessonPath() . '#ask', $this->ask('Help?'));
        $page = $this->get(self::HOST, $this->lessonPath())->body;
        $this->assertStringContainsString('at least 10 characters', $page);
        $this->assertStringContainsString('>Help?</textarea>', $page, 'the draft comes back');

        $this->ask('How do I run this on my phone?');
        $this->ask('How do I run this on my phone?');
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lesson_questions'), 'the same question is not posted twice');

        for ($i = 1; $i <= 10; $i++) {
            $this->ask('Question number ' . $i . ' about loops');
        }
        $this->assertSame(10, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lesson_questions'), 'ten an hour');
    }

    public function testAuthorsDeleteAndTeachersHide(): void
    {
        $this->as('ada@school.test');
        $this->ask('First question that I will delete.');
        $mine = $this->lastId('lesson_questions');
        $this->ask('Second question that stays here.');
        $kept = $this->lastId('lesson_questions');

        $this->as('tobi@school.test');
        $this->assertSame(403, $this->post(self::HOST, '/courses/python-basics/questions/' . $mine . '/delete')->status);
        $this->post(self::HOST, '/courses/python-basics/questions/' . $kept . '/answers', ['answer' => 'A rude answer']);
        $answer = $this->lastId('lesson_answers');

        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/python-basics/questions/' . $kept . '/accept', ['answer' => (string) $answer]);
        $this->assertRedirectTo($this->lessonPath() . '#questions', $this->post(self::HOST, '/courses/python-basics/questions/' . $mine . '/delete'));
        $this->assertStringNotContainsString('First question that I will delete.', $this->get(self::HOST, $this->lessonPath())->body);
        $this->assertSame(403, $this->post(self::HOST, '/courses/python-basics/questions/moderate', ['kind' => 'answer', 'id' => (string) $answer, 'hide' => '1'])->status);

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/courses/python-basics/questions/moderate', ['kind' => 'answer', 'id' => (string) $answer, 'hide' => '1']);
        $this->assertNull($this->db()->fetchValue('SELECT accepted_answer_id FROM lesson_questions WHERE id = ?', [$kept]), 'a hidden answer is no longer the answer');
        $this->post(self::HOST, '/courses/python-basics/questions/moderate', ['kind' => 'question', 'id' => (string) $kept, 'hide' => '1']);
        $this->assertStringContainsString('Second question that stays here.', $this->send(new \App\Core\Http\Request('GET', '/teach/questions', self::HOST, ['show' => 'hidden']))->body);
        $this->assertSame(404, $this->post(self::HOST, '/courses/python-basics/questions/moderate', ['kind' => 'question', 'id' => (string) $mine, 'hide' => '1'])->status, 'deleted stays deleted');

        $this->as('tobi@school.test');
        $page = $this->get(self::HOST, $this->lessonPath())->body;
        $this->assertStringNotContainsString('Second question that stays here.', $page);
        $this->assertSame(404, $this->post(self::HOST, '/courses/python-basics/questions/' . $kept . '/answers', ['answer' => 'Still here?'])->status);

        $this->as('teacher@school.test');
        $this->post(self::HOST, '/courses/python-basics/questions/moderate', ['kind' => 'question', 'id' => (string) $kept, 'hide' => '0']);
        $this->as('tobi@school.test');
        $this->assertStringContainsString('Second question that stays here.', $this->get(self::HOST, $this->lessonPath())->body);
        $this->assertStringNotContainsString('A rude answer', $this->get(self::HOST, $this->lessonPath())->body);
    }
}
