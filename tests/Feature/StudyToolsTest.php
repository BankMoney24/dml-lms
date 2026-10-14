<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Update 18 (ADR 0018): saved lessons and notes, streaks and due dates, calendar link, search, previews, appearance. */
final class StudyToolsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $teacher;
    private User $tobi;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $course;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->tobi = $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->course = $this->makeCourse($this->tenant, $this->teacher, 'physics');
    }

    private function signIn(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    /** @param array<string, string> $query */
    private function getWith(string $path, array $query): \App\Core\Http\Response
    {
        return $this->send(new \App\Core\Http\Request('GET', $path, self::HOST, $query));
    }

    private function lessonUrl(string $which = 'reading'): string
    {
        return '/courses/physics/lessons/' . ($which === 'quiz' ? $this->course['quiz']->id : $this->course['reading']->id);
    }

    public function testLearnersSaveLessonsAndKeepPrivateNotes(): void
    {
        $this->signIn('tobi@school.test');
        $this->assertSame(403, $this->post(self::HOST, $this->lessonUrl() . '/save', ['saved' => '1'])->status, 'enrol first');
        $this->post(self::HOST, '/courses/physics/enrol');

        $this->assertRedirectTo($this->lessonUrl(), $this->post(self::HOST, $this->lessonUrl() . '/save', ['saved' => '1']));
        $this->post(self::HOST, $this->lessonUrl() . '/note', ['note' => "Ohm's law: V = I x R\nAsk about <b>units</b>"]);
        $lesson = $this->get(self::HOST, $this->lessonUrl())->body;
        $this->assertStringContainsString('aria-pressed="true"', $lesson);
        $this->assertStringContainsString('Ask about &lt;b&gt;units&lt;/b&gt;', $lesson, 'notes are escaped');

        $saved = $this->get(self::HOST, '/saved')->body;
        $this->assertStringContainsString('Reading of school', $saved);
        $this->assertStringContainsString('V = I x R', $saved);
        $this->assertStringContainsString('Saved and notes', $this->get(self::HOST, '/')->body);

        $tooLong = $this->post(self::HOST, $this->lessonUrl() . '/note', ['note' => str_repeat('a', 5001)]);
        $this->assertSame(303, $tooLong->status);
        $this->assertStringContainsString('V = I x R', (string) $this->db()->fetchValue('SELECT body FROM lesson_notes'), 'a long note is refused, the old one kept');

        // Nobody else sees them: not another learner, not the teacher.
        $this->signIn('ada@school.test');
        $this->assertStringNotContainsString('V = I x R', $this->get(self::HOST, '/saved')->body);
        $this->signIn('teacher@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/saved')->status, 'teachers have no learner pages');

        // Unsaving and clearing the note leaves nothing behind.
        $this->signIn('tobi@school.test');
        $this->post(self::HOST, $this->lessonUrl() . '/save', ['saved' => '0']);
        $this->post(self::HOST, $this->lessonUrl() . '/note', ['note' => '  ']);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lesson_notes'));
    }

    public function testHomeShowsTheStreakAndWhatIsDueSoon(): void
    {
        $this->signIn('admin@school.test');
        $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold']);
        $group = (int) $this->db()->fetchValue('SELECT id FROM learner_groups');
        $this->post(self::HOST, '/groups/' . $group . '/learners', ['emails' => 'tobi@school.test']);
        $due = (new \DateTimeImmutable('now', new \DateTimeZone($this->tenant->timezone)))->modify('+3 days')->format('Y-m-d');
        $this->post(self::HOST, '/groups/' . $group . '/courses', ['course_id' => (string) $this->course['course']->id, 'due_on' => $due]);

        $this->signIn('tobi@school.test');
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('Coming up', $home);
        $this->assertStringContainsString('Due in 3 days', $home);
        $this->assertStringContainsString('Each day you learn lights up a square here.', $home);

        $this->post(self::HOST, $this->lessonUrl() . '/complete');
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('1 day in a row', $home);
        $this->assertSame(1, preg_match_all('/pc-day lvl-[1-4][^"]*is-today/', $home), 'today is lit');

        // Finished courses drop off the list.
        $this->post(self::HOST, $this->lessonUrl('quiz') . '/attempts', ['answers' => $this->answers($this->course['assessment_id'], true), 'idempotency_key' => str_repeat('b', 32)]);
        $this->assertStringNotContainsString('Due in 3 days', $this->get(self::HOST, '/')->body);
    }

    public function testTheCalendarLinkIsSecretPrivateAndCanBeReplaced(): void
    {
        $this->signIn('admin@school.test');
        $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold']);
        $group = (int) $this->db()->fetchValue('SELECT id FROM learner_groups');
        $this->post(self::HOST, '/groups/' . $group . '/learners', ['emails' => 'tobi@school.test']);
        $this->post(self::HOST, '/groups/' . $group . '/courses', ['course_id' => (string) $this->course['course']->id, 'due_on' => (new \DateTimeImmutable('+10 days'))->format('Y-m-d')]);

        $this->signIn('tobi@school.test');
        $page = $this->post(self::HOST, '/account/calendar');
        $this->assertSame(200, $page->status);
        $this->assertSame(1, preg_match('#/calendar/([0-9a-f]{40})\.ics#', $page->body, $m));
        $token = $m[1] ?? '';
        $this->assertSame(hash('sha256', $token), $this->db()->fetchValue('SELECT calendar_token_hash FROM memberships WHERE user_id = ?', [$this->tobi->id]), 'only a hash is kept');
        $this->assertStringNotContainsString($token, $this->get(self::HOST, '/account/calendar')->body, 'shown once');

        $this->newBrowser();
        $feed = $this->get(self::HOST, '/calendar/' . $token . '.ics');
        $this->assertSame(200, $feed->status);
        $this->assertStringStartsWith('text/calendar', (string) $feed->header('Content-Type'));
        $this->assertStringContainsString("BEGIN:VCALENDAR\r\n", $feed->body);
        $this->assertStringContainsString('SUMMARY:Due: Course school', $feed->body);
        $this->assertStringContainsString('DTSTART;VALUE=DATE:' . (new \DateTimeImmutable('+10 days'))->format('Ymd'), $feed->body);
        $this->assertSame(404, $this->get(self::HOST, '/calendar/' . str_repeat('a', 40) . '.ics')->status);

        $other = $this->makeTenant('other', 'other.test');
        $this->assertSame(404, $this->get('other.test', '/calendar/' . $token . '.ics')->status, 'a link belongs to one school');
        unset($other);

        // A new link replaces the old one; turning it off ends both.
        $this->signIn('tobi@school.test');
        $this->assertSame(1, preg_match('#/calendar/([0-9a-f]{40})\.ics#', $this->post(self::HOST, '/account/calendar')->body, $m2));
        $this->assertSame(404, $this->get(self::HOST, '/calendar/' . $token . '.ics')->status);
        $this->assertSame(200, $this->get(self::HOST, '/calendar/' . ($m2[1] ?? '') . '.ics')->status);
        $this->db()->execute("UPDATE memberships SET status = 'removed' WHERE user_id = ?", [$this->tobi->id]);
        $this->assertSame(404, $this->get(self::HOST, '/calendar/' . ($m2[1] ?? '') . '.ics')->status, 'people who left lose it');
        $this->db()->execute("UPDATE memberships SET status = 'active' WHERE user_id = ?", [$this->tobi->id]);
        $this->post(self::HOST, '/account/calendar/revoke');
        $this->assertSame(404, $this->get(self::HOST, '/calendar/' . ($m2[1] ?? '') . '.ics')->status);
    }

    public function testSearchFindsOnlyWhatThePersonMayOpen(): void
    {
        $draft = $this->app->get(\App\Modules\Courses\CourseRepository::class)->create($this->tenant->id, $this->teacher->id, 'draft-physics', 'Course school advanced', 'Not ready');
        $other = $this->makeTenant('other', 'other.test');
        $this->makeCourse($other, $this->makeUser($other, 'x@other.test', Role::Educator), 'elsewhere');

        $this->signIn('tobi@school.test');
        $json = json_decode($this->getWith('/search', ['format' => 'json', 'q' => 'course'])->body, true);
        $this->assertIsArray($json);
        $titles = array_merge(...array_map(static fn (array $g): array => array_column($g['items'], 'title'), $json['groups']));
        $this->assertContains('Course school', $titles);
        $this->assertNotContains('Course school advanced', $titles, 'drafts are for course builders');
        $this->assertNotContains('Course other', $titles, 'other schools never show');
        $this->assertStringNotContainsString('#person-', $this->getWith('/search', ['q' => 'school'])->body, 'learners do not search people');
        $this->assertStringContainsString('Nothing matches', $this->getWith('/search', ['q' => '%%'])->body, 'wildcards are literal');
        $this->assertStringContainsString('<mark>Course</mark> school', $this->getWith('/search', ['q' => 'course'])->body);

        $this->signIn('admin@school.test');
        $page = $this->getWith('/search', ['q' => 'school'])->body;
        $this->assertStringContainsString('/teach/courses/' . $draft->id, $page);
        $this->assertStringContainsString('#person-' . $this->tobi->id, $page);

        $this->newBrowser();
        $this->assertRedirectTo('/login', $this->getWith('/search', ['q' => 'course']));
    }

    public function testFreePreviewLessonsOpenWithoutJoining(): void
    {
        $url = '/courses/physics/preview/' . $this->course['reading']->id;
        $this->assertSame(404, $this->get(self::HOST, $url)->status, 'not a preview yet');

        $this->signIn('teacher@school.test');
        $edit = '/teach/courses/' . $this->course['course']->id . '/lessons/';
        $this->post(self::HOST, $edit . $this->course['reading']->id, ['lesson_title' => 'Reading of school', 'body' => 'Secret text of school', 'preview' => '1']);
        $this->post(self::HOST, $edit . $this->course['quiz']->id, ['lesson_title' => 'Quiz of school', 'body' => '', 'pass_threshold' => '50', 'preview' => '1']);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT is_preview FROM lessons WHERE id = ?', [$this->course['quiz']->id]), 'quizzes are never previews');

        $this->newBrowser();
        $page = $this->get(self::HOST, $url);
        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('Secret text of school', $page->body);
        $this->assertStringContainsString('Sign in to join', $page->body);
        $this->assertStringContainsString('href="' . $url . '"', $this->get(self::HOST, '/courses/physics')->body);
        $this->assertSame(404, $this->get(self::HOST, '/courses/physics/preview/' . $this->course['quiz']->id)->status);

        $this->db()->execute('UPDATE tenants SET public_catalogue = 0 WHERE id = ?', [$this->tenant->id]);
        $this->assertRedirectTo('/login', $this->get(self::HOST, $url), 'private catalogues stay private');

        $this->signIn('tobi@school.test');
        $this->post(self::HOST, '/courses/physics/enrol');
        $this->assertRedirectTo($this->lessonUrl(), $this->get(self::HOST, $url), 'enrolled learners go to the real lesson');
    }

    public function testEachPersonChoosesLightOrDark(): void
    {
        $this->signIn('tobi@school.test');
        $this->assertStringNotContainsString('data-appearance', $this->get(self::HOST, '/')->body);
        $this->assertRedirectTo('/courses', $this->post(self::HOST, '/account/appearance', ['appearance' => 'dark', 'back' => '/courses']));
        $this->assertStringContainsString('data-appearance="dark">', $this->get(self::HOST, '/')->body);
        $this->assertRedirectTo('/account', $this->post(self::HOST, '/account/appearance', ['appearance' => 'purple', 'back' => '//evil.example']));
        $this->assertSame('dark', $this->db()->fetchValue('SELECT appearance FROM users WHERE id = ?', [$this->tobi->id]));
        $this->post(self::HOST, '/account/appearance', ['appearance' => 'system']);
        $this->assertStringNotContainsString('data-appearance', $this->get(self::HOST, '/')->body);
    }

    public function testTheSidebarShowsEachPersonTheirOwnSections(): void
    {
        $this->signIn('tobi@school.test');
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('href="/saved"', $home);
        $this->assertStringNotContainsString('href="/people"', $home);
        $this->assertStringNotContainsString('href="/teach/reviews"', $home);
        $this->post(self::HOST, '/help', ['category' => 'other', 'subject' => 'Lost password', 'body' => 'Help me.']);

        $this->signIn('admin@school.test');
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('href="/people"', $home);
        $this->assertSame(1, preg_match('#href="/school/support".*?<span class="side-count">1<#s', $home), 'open help requests are counted');
        $this->assertStringContainsString('1 help request waiting for an answer', $home);
    }
}
