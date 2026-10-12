<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Learning\CourseCards;
use App\Modules\Learning\EnrolmentRepository;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class HomeScreensTest extends AppTestCase
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
        $this->fixture = $this->makeCourse($this->tenant, $teacher);
    }

    public function testWelcomeShowsCourseCardsAndCertificateCheck(): void
    {
        $body = $this->get(self::HOST, '/')->body;

        $this->assertStringContainsString('class="course-card', $body);
        $this->assertStringContainsString('Course school', $body);
        $this->assertStringContainsString('action="/verify"', $body);
    }

    public function testDashboardOffersToContinueWithTheNextLesson(): void
    {
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/physics-101/enrol');
        $this->post(self::HOST, '/courses/physics-101/lessons/' . $this->fixture['reading']->id . '/complete');

        $body = $this->get(self::HOST, '/')->body;

        $this->assertStringContainsString('Continue where you stopped', $body);
        $this->assertStringContainsString('Continue: Quiz of school', $body);
        $this->assertStringContainsString('1 of 2 lessons done', $body);
    }

    public function testCourseCardsCountOnlyThisLearnersProgress(): void
    {
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/physics-101/enrol');
        $this->post(self::HOST, '/courses/physics-101/lessons/' . $this->fixture['reading']->id . '/complete');
        $learnerId = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'learner@school.test'");
        $teacherId = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'teacher@school.test'");
        $cards = $this->app->get(CourseCards::class);

        $mine = $cards->build($this->tenant->id, [$this->fixture['course']], $learnerId)[0];
        $theirs = $cards->build($this->tenant->id, [$this->fixture['course']], $teacherId)[0];
        $guest = $cards->build($this->tenant->id, [$this->fixture['course']])[0];

        $this->assertSame([2, 1, true], [$mine['lessons'], $mine['done'], $mine['enrolled']]);
        $this->assertSame([2, 0, false], [$theirs['lessons'], $theirs['done'], $theirs['enrolled']]);
        $this->assertSame(0, $guest['done']);
        $this->assertCount(1, $mine['modules']);
    }

    public function testTeachersSeeLearnerNumbersPerCourse(): void
    {
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/courses/physics-101/enrol');
        $this->post(self::HOST, '/courses/physics-101/lessons/' . $this->fixture['reading']->id . '/complete');

        $stats = $this->app->get(EnrolmentRepository::class)->courseStats($this->tenant->id);
        $this->assertSame(['enrolled' => 1, 'completed' => 0, 'active' => 1], $stats[$this->fixture['course']->id]);

        $this->newBrowser();
        $this->login(self::HOST, 'teacher@school.test', 'correct-horse-battery');
        $body = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('Learners in each course', $body);
        $this->assertStringContainsString('/teach/courses/' . $this->fixture['course']->id . '/progress', $body);
    }

    public function testUnknownPagesKeepTheSchoolsFrame(): void
    {
        $response = $this->get(self::HOST, '/no-such-page');

        $this->assertSame(404, $response->status);
        $this->assertStringContainsString('Page not found', $response->body);
        $this->assertStringContainsString($this->tenant->name, $response->body);
        $this->assertSame(404, $this->get('unknown.test', '/no-such-page')->status);
    }
}
