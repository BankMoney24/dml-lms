<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use Tests\Support\AppTestCase;

/** ADR 0024: joining from a course and coming back to pay or start, receipts, and the front page. */
final class EnrolAndPayTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeCourse($this->tenant, $teacher, 'free-course');
        $paid = $this->makeCourse($this->tenant, $teacher, 'paid-course')['course'];
        $this->app->get(CourseRepository::class)->updatePrice($this->tenant->id, $paid->id, 1500000, 'NGN');
        $this->app->get(TenantRepository::class)->updateJoining($this->tenant->id, 'open', null, true);
    }

    /** @param array<string, string> $extra */
    private function join(string $email, array $extra = []): \App\Core\Http\Response
    {
        return $this->post(self::HOST, '/join', $extra + [
            'name' => 'Adult Learner', 'email' => $email, 'password' => 'a long enough passphrase', 'code' => '', 'terms' => '1', 'age' => '1', 'website' => '',
        ]);
    }

    private function enrolments(string $email): int
    {
        return (int) $this->db()->fetchValue('SELECT COUNT(*) FROM enrolments e JOIN users u ON u.id = e.user_id WHERE u.email = ?', [$email]);
    }

    public function testJoiningForAPaidCourseComesBackToPay(): void
    {
        $page = $this->get(self::HOST, '/courses/paid-course')->body;
        $this->assertStringContainsString('href="/join?course=paid-course">Create an account and pay', $page);
        $this->assertStringContainsString('class="included"', $page, 'what the price includes');
        $this->assertStringContainsString('href="/login?next=%2Fcourses%2Fpaid-course"', $page);

        $join = $this->send(new Request('GET', '/join', self::HOST, ['course' => 'paid-course']))->body;
        $this->assertStringContainsString('You are joining to take', $join);
        $this->assertStringContainsString('name="course" value="paid-course"', $join);

        $this->assertRedirectTo('/courses/paid-course#pay', $this->join('ada@example.test', ['course' => 'paid-course']));
        $this->assertSame(0, $this->enrolments('ada@example.test'), 'paying comes first');
        $this->assertStringContainsString('pay below to start', $this->get(self::HOST, '/courses/paid-course')->body);
    }

    public function testJoiningForAFreeCourseStartsIt(): void
    {
        $this->assertRedirectTo('/courses/free-course#start', $this->join('bo@example.test', ['course' => 'free-course']));
        $this->assertSame(1, $this->enrolments('bo@example.test'));

        $this->newBrowser();
        $this->assertRedirectTo('/setup/welcome', $this->join('cy@example.test', ['course' => 'no-such-course']), 'an unknown course is ignored');
        $this->assertSame(0, $this->enrolments('cy@example.test'));
    }

    public function testSignInReturnsToTheCourseButNeverOffSite(): void
    {
        $this->makeUser($this->tenant, 'dee@example.test', Role::Learner);
        $this->send(new Request('GET', '/login', self::HOST, ['next' => '/courses/paid-course']));
        $this->assertRedirectTo('/courses/paid-course', $this->login(self::HOST, 'dee@example.test', 'correct-horse-battery'));

        foreach (['//evil.test/x', 'https://evil.test', '/\\evil.test'] as $bad) {
            $this->newBrowser();
            $this->send(new Request('GET', '/login', self::HOST, ['next' => $bad]));
            $this->assertRedirectTo('/', $this->login(self::HOST, 'dee@example.test', 'correct-horse-battery'), $bad);
        }
    }

    public function testReceiptsForThePayerAndTheSchoolOnly(): void
    {
        $ada = $this->makeUser($this->tenant, 'ada@example.test', Role::Learner);
        $this->makeUser($this->tenant, 'bo@example.test', Role::Learner);
        $course = $this->app->get(CourseRepository::class)->findBySlug($this->tenant->id, 'paid-course');
        $this->assertInstanceOf(Course::class, $course);
        foreach ([['RCPT-PAID', 'paid'], ['RCPT-WAIT', 'pending']] as [$ref, $status]) {
            $this->db()->execute(
                "INSERT INTO payments (tenant_id, user_id, course_id, reference, provider, mode, amount_minor, currency, status, channel, created_at, updated_at, paid_at)
                 VALUES (?, ?, ?, ?, 'paystack', 'live', 1500000, 'NGN', ?, 'card', UTC_TIMESTAMP(), UTC_TIMESTAMP(), " . ($status === 'paid' ? 'UTC_TIMESTAMP()' : 'NULL') . ')',
                [$this->tenant->id, $ada->id, $course->id, $ref, $status],
            );
        }

        $this->newBrowser();
        $this->login(self::HOST, 'ada@example.test', 'correct-horse-battery');
        $this->assertStringContainsString('href="/account/receipts/RCPT-PAID">Receipt', $this->get(self::HOST, '/account')->body);
        $receipt = $this->get(self::HOST, '/account/receipts/RCPT-PAID');
        $this->assertSame(200, $receipt->status, substr(strip_tags($receipt->body), 0, 3000));
        $this->assertStringContainsString('₦15,000', $receipt->body);
        $this->assertStringContainsString('Course: Course school', $receipt->body);
        $this->assertStringContainsString('ada@example.test', $receipt->body);
        $this->assertSame(404, $this->get(self::HOST, '/account/receipts/RCPT-WAIT')->status, 'only confirmed payments');

        $this->newBrowser();
        $this->login(self::HOST, 'bo@example.test', 'correct-horse-battery');
        $this->assertSame(404, $this->get(self::HOST, '/account/receipts/RCPT-PAID')->status, 'not someone else\'s');

        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery'));
        $this->assertSame(200, $this->get(self::HOST, '/account/receipts/RCPT-PAID')->status, 'the school can reprint it');
        $this->assertNotNull($this->app->get(UserRepository::class)->findByEmail('ada@example.test'));
    }

    public function testTheFrontPageShowsRealFiguresAndWaysIn(): void
    {
        $home = $this->get(self::HOST, '/')->body;
        $this->assertStringContainsString('action="/courses" role="search"', $home);
        $this->assertMatchesRegularExpression('#<dt>.*?courses</dt><dd>2</dd>#s', $home);
        $this->assertMatchesRegularExpression('#<dt>.*?lessons</dt><dd>4</dd>#s', $home);
        $this->assertStringContainsString('Popular courses', $home);
        $this->assertStringContainsString('Who learns here', $home);
        $this->assertStringContainsString('Adults changing career', $home);
        $this->assertStringContainsString('Create your free account', $home);
    }
}
