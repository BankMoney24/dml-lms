<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** The notification centre (NTF-001) and class announcements. */
final class NotificationsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $author;
    private User $classTeacher;
    private User $tobi;
    private int $assignmentId;
    private int $courseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->author = $this->makeUser($this->tenant, 'author@school.test', Role::Educator);
        $this->classTeacher = $this->makeUser($this->tenant, 'class@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'other@school.test', Role::Educator);
        $this->tobi = $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);

        $courses = $this->app->get(CourseRepository::class);
        $lessons = $this->app->get(LessonRepository::class);
        $course = $courses->create($this->tenant->id, $this->author->id, 'servers', 'Servers', 'Summary');
        $module = $lessons->addModule($this->tenant->id, $course->id, 'Module');
        $this->assignmentId = $lessons->addLesson($this->tenant->id, $course->id, $module, Lesson::TYPE_ASSIGNMENT, 'Harden SSH', 'Do it.');
        $courses->markPublished($this->tenant->id, $course->id);
        $this->courseId = $course->id;
    }

    private function signIn(string $email): void
    {
        $this->newBrowser();
        $this->login(self::HOST, $email, 'correct-horse-battery');
    }

    /** SS 2 Gold with Tobi as learner, the class teacher, and the course set. Returns its id. */
    private function classWithCourse(): int
    {
        $this->signIn('admin@school.test');
        $this->post(self::HOST, '/groups', ['name' => 'SS 2 Gold']);
        $id = (int) $this->db()->fetchValue('SELECT id FROM learner_groups');
        $this->post(self::HOST, '/groups/' . $id . '/teachers', ['teachers' => [(string) $this->classTeacher->id]]);
        $this->post(self::HOST, '/groups/' . $id . '/learners', ['emails' => 'tobi@school.test']);
        $this->post(self::HOST, '/groups/' . $id . '/courses', ['course_id' => (string) $this->courseId, 'due_on' => '2026-11-30']);

        return $id;
    }

    /** @return list<string> titles for this user (or email), newest first */
    private function titlesFor(User|string $who): array
    {
        $id = $who instanceof User ? $who->id : (int) $this->db()->fetchValue('SELECT id FROM users WHERE email = ?', [$who]);

        return array_map('strval', array_column($this->db()->fetchAll('SELECT title FROM notifications WHERE user_id = ? ORDER BY id DESC', [$id]), 'title'));
    }

    public function testTeachersHearAboutNewWorkAndLearnersAboutTheReview(): void
    {
        $this->classWithCourse();
        $this->assertContains('You now teach SS 2 Gold', $this->titlesFor($this->classTeacher));
        $this->assertContains('You are now in SS 2 Gold', $this->titlesFor($this->tobi));
        $this->assertContains('New course for SS 2 Gold: Servers', $this->titlesFor($this->tobi));

        $this->signIn('tobi@school.test');
        $this->post(self::HOST, '/courses/servers/lessons/' . $this->assignmentId . '/submissions', ['answer' => 'Done.']);
        $this->post(self::HOST, '/courses/servers/lessons/' . $this->assignmentId . '/submissions', ['answer' => 'Done, fixed a typo.']);
        $submission = (int) $this->db()->fetchValue('SELECT MAX(id) FROM assignment_submissions');
        $authorNews = array_values(array_filter($this->titlesFor($this->author), static fn (string $t): bool => str_starts_with($t, 'New work')));
        $this->assertSame(['New work to review: Harden SSH'], $authorNews, 'once, not per correction');
        $this->assertContains('New work to review: Harden SSH', $this->titlesFor($this->classTeacher));
        $this->assertSame([], $this->titlesFor('other@school.test'), 'teachers of other classes are not told');

        $this->signIn('author@school.test');
        $this->assertStringContainsString('<span class="bell-count" aria-hidden="true">1</span>', $this->get(self::HOST, '/')->body);
        $note = (int) $this->db()->fetchValue("SELECT id FROM notifications WHERE user_id = ? AND kind = 'assignment.submitted'", [$this->author->id]);
        $this->assertRedirectTo('/teach/reviews/' . $submission, $this->get(self::HOST, '/notifications/' . $note));
        $this->assertNotNull($this->db()->fetchValue('SELECT read_at FROM notifications WHERE id = ?', [$note]));
        $this->post(self::HOST, '/teach/reviews/' . $submission, ['decision' => 'approve', 'feedback' => 'Good.']);

        $this->signIn('tobi@school.test');
        $page = $this->get(self::HOST, '/notifications')->body;
        $this->assertStringContainsString('Approved: Harden SSH', $page);
        $this->assertStringContainsString('Test Educator reviewed your work in Servers.', $page);
        $this->assertSame(404, $this->get(self::HOST, '/notifications/' . $note)->status, 'someone else\'s notification');
        $this->assertRedirectTo('/notifications', $this->post(self::HOST, '/notifications/read-all'));
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL', [$this->tobi->id]));
    }

    public function testAnnouncementsReachTheClassOnly(): void
    {
        $id = $this->classWithCourse();
        $this->signIn('other@school.test');
        $this->assertSame(404, $this->post(self::HOST, '/groups/' . $id . '/announcements', ['announcement' => 'Hello'])->status);

        $this->signIn('class@school.test');
        $this->assertSame(422, $this->post(self::HOST, '/groups/' . $id . '/announcements', ['announcement' => ' '])->status);
        $this->assertRedirectTo('/groups/' . $id, $this->post(self::HOST, '/groups/' . $id . '/announcements', ['announcement' => "Lab day on Friday.\n\nBring your charger."]));
        $this->assertStringContainsString('Lab day on Friday.', $this->get(self::HOST, '/groups/' . $id)->body);

        $this->signIn('tobi@school.test');
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('<p>Lab day on Friday.</p><p>Bring your charger.</p>', $home);
        $this->assertContains('Test Educator posted in SS 2 Gold', $this->titlesFor($this->tobi));

        $this->signIn('ada@school.test');
        $this->assertStringNotContainsString('Lab day', $this->get(self::HOST, '/')->body, 'not in the class');
        $this->assertSame([], $this->titlesFor('ada@school.test'));
    }

    public function testOnlyCurrentMembersAndOnlySiteLinks(): void
    {
        $this->assertNull(Notifier::safeLink('https://evil.example/'));
        $this->assertNull(Notifier::safeLink('//evil.example/'));
        $this->assertNull(Notifier::safeLink('/\\evil.example'));
        $this->assertSame('/courses/servers', Notifier::safeLink('/courses/servers'));

        $this->db()->execute("UPDATE memberships SET status = 'removed' WHERE user_id = ?", [$this->tobi->id]);
        $this->app->get(Notifier::class)->notify($this->tenant->id, $this->tobi->id, Notifier::ANNOUNCEMENT, 'Hello');
        $this->assertSame([], $this->titlesFor($this->tobi));
    }
}
