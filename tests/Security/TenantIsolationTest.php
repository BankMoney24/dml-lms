<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\Role;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use PDOException;
use Tests\Support\AppTestCase;

/**
 * Release-blocking: two institutions with identically named users and
 * courses must never see or change each other's data (PRD §19.2–19.3).
 */
final class TenantIsolationTest extends AppTestCase
{
    private Tenant $alpha;
    private Tenant $beta;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $alphaCourse;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $betaCourse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->alpha = $this->makeTenant('alpha', 'alpha.test');
        $this->beta = $this->makeTenant('beta', 'beta.test');

        $alphaTeacher = $this->makeUser($this->alpha, 'teacher@alpha.test', Role::Educator);
        $betaTeacher = $this->makeUser($this->beta, 'teacher@beta.test', Role::Educator);
        $this->makeUser($this->alpha, 'learner@alpha.test', Role::Learner);
        $this->makeUser($this->beta, 'learner@beta.test', Role::Learner);

        // Same slug in both tenants on purpose.
        $this->alphaCourse = $this->makeCourse($this->alpha, $alphaTeacher, 'physics-101');
        $this->betaCourse = $this->makeCourse($this->beta, $betaTeacher, 'physics-101');
    }

    public function testSameSlugResolvesToEachTenantsOwnCourse(): void
    {
        $alphaPage = $this->get('alpha.test', '/courses/physics-101')->body;
        $betaPage = $this->get('beta.test', '/courses/physics-101')->body;

        $this->assertStringContainsString('Summary for alpha', $alphaPage);
        $this->assertStringNotContainsString('Summary for beta', $alphaPage);
        $this->assertStringContainsString('Summary for beta', $betaPage);
    }

    public function testCatalogueListsOnlyOwnCourses(): void
    {
        $body = $this->get('alpha.test', '/courses')->body;

        $this->assertStringContainsString('Course alpha', $body);
        $this->assertStringNotContainsString('Course beta', $body);
    }

    public function testLearnerCannotOpenAnotherTenantsLessonById(): void
    {
        $this->login('alpha.test', 'learner@alpha.test', 'correct-horse-battery');
        $this->post('alpha.test', '/courses/physics-101/enrol');

        $own = $this->get('alpha.test', '/courses/physics-101/lessons/' . $this->alphaCourse['reading']->id);
        $foreign = $this->get('alpha.test', '/courses/physics-101/lessons/' . $this->betaCourse['reading']->id);

        $this->assertSame(200, $own->status);
        $this->assertSame(404, $foreign->status);
        $this->assertStringNotContainsString('Secret text of beta', $foreign->body);
    }

    public function testSessionFromOneTenantIsNotValidOnAnother(): void
    {
        $this->login('alpha.test', 'teacher@alpha.test', 'correct-horse-battery');
        $this->assertSame(200, $this->get('alpha.test', '/teach')->status);

        // Same browser session presented to beta's host.
        $this->assertRedirectTo('/login', $this->get('beta.test', '/teach'));
    }

    public function testEducatorCannotEditAnotherTenantsCourseById(): void
    {
        $this->login('alpha.test', 'teacher@alpha.test', 'correct-horse-battery');
        $betaId = $this->betaCourse['course']->id;

        $this->assertSame(404, $this->get('alpha.test', '/teach/courses/' . $betaId)->status);
        $this->assertSame(404, $this->post('alpha.test', '/teach/courses/' . $betaId . '/modules', ['module_title' => 'Injected'])->status);
        $this->assertSame(404, $this->post('alpha.test', '/teach/courses/' . $betaId . '/publish')->status);
        $this->assertSame(
            0,
            (int) $this->db()->fetchValue("SELECT COUNT(*) FROM course_modules WHERE title = 'Injected'"),
        );
    }

    public function testEducatorCannotAttachLessonToAnotherTenantsModule(): void
    {
        $this->login('alpha.test', 'teacher@alpha.test', 'correct-horse-battery');
        $betaModule = $this->betaCourse['reading']->moduleId;

        $response = $this->post('alpha.test', '/teach/courses/' . $this->alphaCourse['course']->id . '/lessons', [
            'module_id' => (string) $betaModule,
            'type' => 'text',
            'lesson_title' => 'Cross-tenant',
            'body' => 'x',
        ]);

        $this->assertSame(422, $response->status);
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM lessons WHERE title = 'Cross-tenant'"));
    }

    public function testQuizAttemptResultsAreOwnerOnly(): void
    {
        $this->login('alpha.test', 'learner@alpha.test', 'correct-horse-battery');
        $this->post('alpha.test', '/courses/physics-101/enrol');
        $quizUrl = '/courses/physics-101/lessons/' . $this->alphaCourse['quiz']->id;
        $submit = $this->post('alpha.test', $quizUrl . '/attempts', [
            'answers' => $this->answers($this->alphaCourse['assessment_id'], true),
            'idempotency_key' => str_repeat('a', 32),
        ]);
        $resultUrl = (string) $submit->header('Location');
        $this->assertSame(200, $this->get('alpha.test', $resultUrl)->status);

        // A second learner in the same tenant cannot read it.
        $this->newBrowser();
        $this->makeUser($this->alpha, 'other@alpha.test', Role::Learner);
        $this->login('alpha.test', 'other@alpha.test', 'correct-horse-battery');
        $this->post('alpha.test', '/courses/physics-101/enrol');

        $this->assertSame(404, $this->get('alpha.test', $resultUrl)->status);
    }

    public function testEditingEndpointsRefuseAnotherTenantsLessons(): void
    {
        $this->login('alpha.test', 'teacher@alpha.test', 'correct-horse-battery');
        $alphaCourse = $this->alphaCourse['course']->id;
        $betaLesson = $this->betaCourse['reading']->id;
        $betaQuiz = $this->betaCourse['quiz']->id;
        $betaQuestion = (int) $this->db()->fetchValue('SELECT id FROM questions WHERE tenant_id = ? LIMIT 1', [$this->beta->id]);

        // Beta's ids under alpha's own course URL.
        $this->assertSame(404, $this->get('alpha.test', "/teach/courses/$alphaCourse/lessons/$betaLesson")->status);
        $this->assertSame(404, $this->get('alpha.test', "/teach/courses/$alphaCourse/lessons/$betaLesson/preview")->status);
        $this->assertSame(404, $this->post('alpha.test', "/teach/courses/$alphaCourse/lessons/$betaLesson", ['lesson_title' => 'Hacked', 'body' => 'x'])->status);
        $this->assertSame(404, $this->post('alpha.test', "/teach/courses/$alphaCourse/lessons/$betaLesson/delete")->status);
        $this->assertSame(404, $this->post('alpha.test', "/teach/courses/$alphaCourse/lessons/$betaLesson/move", ['direction' => 'up'])->status);
        // Alpha's quiz URL with beta's question id.
        $alphaQuiz = $this->alphaCourse['quiz']->id;
        $this->post('alpha.test', "/teach/courses/$alphaCourse/lessons/$alphaQuiz/questions/$betaQuestion/delete");
        $this->assertSame(404, $this->get('alpha.test', '/teach/courses/' . $this->betaCourse['course']->id . '/progress')->status);

        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM questions WHERE id = ?', [$betaQuestion]));
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM lessons WHERE title = 'Hacked'"));
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM lessons WHERE id = ?', [$betaQuiz]));
    }

    public function testPeopleManagementCannotReachAnotherTenantsMembers(): void
    {
        $this->makeUser($this->alpha, 'owner@alpha.test', Role::Owner);
        $this->login('alpha.test', 'owner@alpha.test', 'correct-horse-battery');
        $betaTeacher = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'teacher@beta.test'");

        $this->assertSame(404, $this->post('alpha.test', "/people/$betaTeacher/role", ['role' => 'learner'])->status);
        $this->assertSame(404, $this->post('alpha.test', "/people/$betaTeacher/remove")->status);
        $this->assertStringNotContainsString('teacher@beta.test', $this->get('alpha.test', '/people')->body);
        $this->assertSame('educator', $this->db()->fetchValue('SELECT role FROM memberships WHERE tenant_id = ? AND user_id = ?', [$this->beta->id, $betaTeacher]));
    }

    public function testActivityLogShowsOnlyOwnTenantsEvents(): void
    {
        $this->login('beta.test', 'teacher@beta.test', 'correct-horse-battery');
        $this->newBrowser();
        $this->makeUser($this->alpha, 'owner@alpha.test', Role::Owner);
        $this->login('alpha.test', 'owner@alpha.test', 'correct-horse-battery');

        $body = $this->get('alpha.test', '/activity')->body;

        $this->assertStringNotContainsString('Test Educator', $body);
    }

    public function testRepositoriesNeverReturnAnotherTenantsRows(): void
    {
        $courses = $this->app->get(CourseRepository::class);
        $lessons = $this->app->get(LessonRepository::class);

        $this->assertNull($courses->findById($this->alpha->id, $this->betaCourse['course']->id));
        $this->assertNull($lessons->findInCourse($this->alpha->id, $this->betaCourse['course']->id, $this->betaCourse['reading']->id));
        $this->assertSame([], $lessons->outline($this->alpha->id, $this->betaCourse['course']->id));
    }

    public function testDatabaseRejectsRowsThatPointAcrossTenants(): void
    {
        $this->expectException(PDOException::class);
        // Defence in depth: composite foreign keys refuse alpha's tenant_id with beta's course id.
        $this->db()->insert(
            'INSERT INTO course_modules (tenant_id, course_id, title, position, created_at, updated_at) VALUES (?, ?, ?, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$this->alpha->id, $this->betaCourse['course']->id, 'Sneaky'],
        );
    }

    public function testUnverifiedDomainDoesNotResolve(): void
    {
        $this->app->get(TenantRepository::class)->addDomain($this->alpha->id, 'pending.alpha.test', false);

        $this->assertSame(404, $this->get('pending.alpha.test', '/courses')->status);
    }

    public function testSuspendedTenantIsUnavailable(): void
    {
        $this->app->get(TenantRepository::class)->setStatus($this->beta->id, Tenant::STATUS_SUSPENDED);

        $this->assertSame(503, $this->get('beta.test', '/courses')->status);
        $this->assertSame(200, $this->get('alpha.test', '/courses')->status);
    }
}
