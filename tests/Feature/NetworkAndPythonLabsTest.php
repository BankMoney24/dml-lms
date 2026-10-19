<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Courses\CourseAuthoring;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;
use App\Modules\VirtualLabs\LabCatalogue;
use App\Modules\VirtualLabs\Seed;
use Tests\Support\AppTestCase;
use Tests\Unit\VirtualLabsTest as Answers;

/** ADR 0027: the packet capture, network trace and Python labs inside a course, and teachers following lesson links. */
final class NetworkAndPythonLabsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private User $teacher;
    /** @var array<string, int> lab key => lesson id */
    private array $lessons = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $authoring = $this->app->get(CourseAuthoring::class);
        $author = new CurrentUser($this->teacher, $this->app->get(MembershipRepository::class)->find($this->tenant->id, $this->teacher->id));
        $course = $authoring->createCourse($this->tenant->id, $author, 'Networks and code', 'Labs');
        $module = $authoring->addModule($course, $author, 'Labs');
        foreach (['packet-capture', 'network-trace', 'py-leap'] as $key) {
            $this->lessons[$key] = $authoring->addLesson($course, $author, $module, ['type' => 'lab', 'title' => '', 'body' => 'Go.', 'lab_key' => $key]);
        }
        $authoring->publish($course, $author);
    }

    public function testEachLabIsMarkedOnTheServerAndCompletesItsLesson(): void
    {
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/networks-and-code/enrol');
        foreach ($this->lessons as $key => $lessonId) {
            $start = $this->post(self::HOST, '/courses/networks-and-code/lessons/' . $lessonId . '/lab');
            $this->assertSame(1, preg_match('#^/labs/(\d+)$#', (string) $start->header('Location'), $m), $key);
            $id = (int) ($m[1] ?? 0);
            $page = $this->get(self::HOST, '/labs/' . $id)->body;
            $this->assertStringContainsString('data-lab="' . $key . '"', $page);
            $this->assertStringContainsString('/assets/labs/' . $key . '.js', $page);
            $seed = (string) $this->db()->fetchValue('SELECT seed FROM lab_sessions WHERE id = ?', [$id]);
            $lab = LabCatalogue::builtIn()->find($key) ?? throw new \LogicException();
            $params = $lab->params(new Seed($seed));
            if ($key === 'network-trace') {
                $this->assertStringNotContainsString('&quot;problem&quot;', $page, 'the fault is not sent to the browser');
            }
            $this->post(self::HOST, '/labs/' . $id . '/submit', ['answers' => Answers::correctAnswers($key, $params)]);
            $this->assertSame(100, (int) $this->db()->fetchValue('SELECT score FROM lab_sessions WHERE id = ?', [$id]), $key);
        }
        $this->assertSame('completed', $this->db()->fetchValue('SELECT status FROM enrolments'));
    }

    public function testTeachersFollowingALessonLinkSeeThePreview(): void
    {
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
        $course = (int) $this->db()->fetchValue('SELECT id FROM courses');
        $lesson = $this->lessons['network-trace'];
        $this->assertRedirectTo('/teach/courses/' . $course . '/lessons/' . $lesson . '/preview', $this->get(self::HOST, '/courses/networks-and-code/lessons/' . $lesson));
    }
}
