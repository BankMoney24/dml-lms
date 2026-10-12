<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Bootstrap\Application;
use App\Core\Config;
use App\Core\Container;
use App\Core\Database\Connection;
use App\Core\Http\Kernel;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Security\Csrf;
use App\Core\Session\ArraySession;
use App\Core\Session\Session;
use App\Modules\Assessments\AssessmentRepository;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Courses\Lesson;
use App\Modules\Courses\LessonRepository;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use PHPUnit\Framework\TestCase;

/**
 * Base for integration and feature tests: a real database, the real service
 * graph and an in-memory session that persists across requests in one test
 * (like one browser).
 */
abstract class AppTestCase extends TestCase
{
    protected Container $app;
    protected ArraySession $session;

    protected function setUp(): void
    {
        parent::setUp();
        TestDatabase::truncateAll();
        $this->session = new ArraySession();
        $this->app = $this->freshApp($this->session);
    }

    protected function freshApp(ArraySession $session): Container
    {
        return Application::create(new Config(TestDatabase::config()), [
            Connection::class => TestDatabase::connection(),
            Session::class => $session,
        ]);
    }

    /** Switches to a different "browser". */
    protected function newBrowser(): void
    {
        $this->session = new ArraySession();
        $this->app = $this->freshApp($this->session);
    }

    /** Switches back to an earlier "browser" (its session and form token). */
    protected function useBrowser(ArraySession $session): void
    {
        $this->session = $session;
        $this->app = $this->freshApp($session);
    }

    protected function db(): Connection
    {
        return TestDatabase::connection();
    }

    protected function get(string $host, string $path): Response
    {
        return $this->send(new Request('GET', $path, $host));
    }

    /** @param array<string, mixed> $data */
    protected function post(string $host, string $path, array $data = [], bool $withCsrf = true): Response
    {
        if ($withCsrf) {
            $data['_token'] = $this->app->get(Csrf::class)->token();
        }

        return $this->send(new Request('POST', $path, $host, [], $data));
    }

    protected function send(Request $request): Response
    {
        // Each request gets a fresh service graph (as under PHP-FPM) sharing the session.
        $this->app = $this->freshApp($this->session);

        return $this->app->get(Kernel::class)->handle($request);
    }

    protected function login(string $host, string $email, string $password): Response
    {
        return $this->post($host, '/login', ['email' => $email, 'password' => $password]);
    }

    protected function makeTenant(string $slug, string $host): Tenant
    {
        $tenants = $this->app->get(TenantRepository::class);
        $tenant = $tenants->create($slug, ucfirst($slug) . ' Academy');
        $tenants->addDomain($tenant->id, $host, true, true);

        return $tenant;
    }

    protected function makeUser(Tenant $tenant, string $email, Role $role, string $password = 'correct-horse-battery'): User
    {
        $users = $this->app->get(UserRepository::class);
        $user = $users->findByEmail($email) ?? $users->create($email, 'Test ' . $role->label(), AuthService::hashPassword($password));
        $this->app->get(MembershipRepository::class)->add($tenant->id, $user->id, $role);

        return $user;
    }

    /**
     * A published course: one reading lesson and one quiz with two questions
     * (first option correct in each).
     *
     * @return array{course: Course, reading: Lesson, quiz: Lesson, assessment_id: int}
     */
    protected function makeCourse(Tenant $tenant, User $author, string $slug = 'physics-101', ?int $maxAttempts = null): array
    {
        $courses = $this->app->get(CourseRepository::class);
        $lessons = $this->app->get(LessonRepository::class);
        $assessments = $this->app->get(AssessmentRepository::class);

        $course = $courses->create($tenant->id, $author->id, $slug, 'Course ' . $tenant->slug, 'Summary for ' . $tenant->slug);
        $module = $lessons->addModule($tenant->id, $course->id, 'Module');
        $readingId = $lessons->addLesson($tenant->id, $course->id, $module, Lesson::TYPE_TEXT, 'Reading of ' . $tenant->slug, 'Secret text of ' . $tenant->slug);
        $quizId = $lessons->addLesson($tenant->id, $course->id, $module, Lesson::TYPE_QUIZ, 'Quiz of ' . $tenant->slug, '');
        $assessmentId = $assessments->create($tenant->id, $quizId, 50, $maxAttempts);
        $assessments->addQuestion($tenant->id, $assessmentId, 'Q1', ['right', 'wrong'], 0);
        $assessments->addQuestion($tenant->id, $assessmentId, 'Q2', ['right', 'wrong'], 0);
        $courses->markPublished($tenant->id, $course->id);

        return [
            'course' => $courses->findById($tenant->id, $course->id) ?? $course,
            'reading' => $lessons->findInCourse($tenant->id, $course->id, $readingId) ?? throw new \LogicException(),
            'quiz' => $lessons->findInCourse($tenant->id, $course->id, $quizId) ?? throw new \LogicException(),
            'assessment_id' => $assessmentId,
        ];
    }

    /**
     * Answers for the quiz built by makeCourse.
     *
     * @return array<string, string>
     */
    protected function answers(int $assessmentId, bool $correct): array
    {
        $tenantQuestions = $this->db()->fetchAll(
            'SELECT q.id AS qid, o.id AS oid, o.is_correct FROM questions q JOIN question_options o ON o.question_id = q.id WHERE q.assessment_id = ?',
            [$assessmentId],
        );
        $answers = [];
        foreach ($tenantQuestions as $row) {
            if ((bool) $row['is_correct'] === $correct) {
                $answers[(string) $row['qid']] = (string) $row['oid'];
            }
        }

        return $answers;
    }

    protected function assertRedirectTo(string $location, Response $response, string $message = ''): void
    {
        $this->assertContains($response->status, [301, 302, 303], trim($message . ' Expected a redirect, got ' . $response->status));
        $this->assertSame($location, $response->header('Location'), $message);
    }
}
