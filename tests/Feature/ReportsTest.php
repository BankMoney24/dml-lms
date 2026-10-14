<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** ADR 0022: school reports and spreadsheet downloads. */
final class ReportsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    /** @var array{course: \App\Modules\Courses\Course, reading: \App\Modules\Courses\Lesson, quiz: \App\Modules\Courses\Lesson, assessment_id: int} */
    private array $fx;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $evil = $this->makeUser($this->tenant, 'evil@school.test', Role::Learner);
        $this->db()->execute('UPDATE users SET display_name = ? WHERE id = ?', ['=HYPERLINK("http://bad.test","click")', $evil->id]);
        $this->makeUser($this->tenant, 'idle@school.test', Role::Learner);
        $this->fx = $this->makeCourse($this->tenant, $teacher, 'python-basics');

        // Ada finishes the reading; the other learner joins but does nothing yet.
        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/python-basics/enrol');
        $this->post(self::HOST, '/courses/python-basics/lessons/' . $this->fx['reading']->id . '/complete');
        $this->as('evil@school.test');
        $this->post(self::HOST, '/courses/python-basics/enrol');

        // Another school's learner never appears here.
        $other = $this->makeTenant('other', 'other.test');
        $this->makeUser($other, 'stranger@other.test', Role::Learner);

        $ada = $this->app->get(UserRepository::class)->findByEmail('ada@school.test');
        $this->db()->execute(
            "INSERT INTO payments (tenant_id, user_id, course_id, reference, provider, mode, amount_minor, currency, status, created_at, updated_at, paid_at)
             VALUES (?, ?, ?, 'ref-1', 'manual', 'live', 1500000, 'NGN', 'paid', UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())",
            [$this->tenant->id, $ada?->id, $this->fx['course']->id],
        );
    }

    private function as(string $email): void
    {
        $this->newBrowser();
        $this->assertRedirectTo('/', $this->login(self::HOST, $email, 'correct-horse-battery'));
    }

    public function testOwnersSeeTheWholePicture(): void
    {
        $this->as('owner@school.test');
        $page = $this->get(self::HOST, '/school/reports');
        $this->assertSame(200, $page->status);
        $this->assertMatchesRegularExpression('#<dt>Learners</dt>\s*<dd><span class="report-value">3</span>#', $page->body);
        $this->assertMatchesRegularExpression('#<dt>Course places</dt>\s*<dd><span class="report-value">2</span>#', $page->body);
        $this->assertStringContainsString('aria-label="0 completed, 1 in progress, 1 not started"', $page->body);
        $this->assertStringContainsString('ada@school.test', $page->body);
        $this->assertStringNotContainsString('stranger@other.test', $page->body);
        $this->assertStringContainsString('₦15,000', $page->body, 'owners see payments');
        $this->assertStringContainsString('Learners (CSV)', $page->body);

        $found = $this->send(new Request('GET', '/school/reports', self::HOST, ['find' => 'ada']))->body;
        $this->assertStringContainsString('ada@school.test', $found);
        $this->assertStringNotContainsString('idle@school.test', $found);
    }

    public function testTeachersSeeCoursesButNotPeopleOrMoney(): void
    {
        $this->as('teacher@school.test');
        $page = $this->get(self::HOST, '/school/reports')->body;
        $this->assertStringContainsString('Course school', $page);
        $this->assertStringNotContainsString('ada@school.test', $page);
        $this->assertStringNotContainsString('₦15,000', $page);
        $this->assertSame(403, $this->get(self::HOST, '/school/reports/learners.csv')->status);
        $this->assertSame(200, $this->get(self::HOST, '/school/reports/courses.csv')->status);

        $this->as('ada@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/school/reports')->status);
        $this->assertSame(403, $this->get(self::HOST, '/school/reports/courses.csv')->status);
    }

    public function testDownloadsAreSafeSpreadsheets(): void
    {
        $this->as('owner@school.test');
        $csv = $this->get(self::HOST, '/school/reports/learners.csv');
        $this->assertSame(200, $csv->status);
        $this->assertStringStartsWith('text/csv', (string) $csv->header('Content-Type'));
        $this->assertMatchesRegularExpression('/^attachment; filename="school-learners-\d{4}-\d{2}-\d{2}\.csv"$/', (string) $csv->header('Content-Disposition'));
        $this->assertStringStartsWith("\xEF\xBB\xBF\"Name\",\"Email\"", $csv->body);
        $this->assertStringContainsString('"\'=HYPERLINK(""http://bad.test"",""click"")"', $csv->body, 'formulas are neutralised');
        $this->assertStringContainsString('"ada@school.test"', $csv->body);
        $this->assertStringNotContainsString('stranger@other.test', $csv->body);
        $this->assertSame(4, substr_count($csv->body, "\r\n"), 'a header and three learners');

        $courses = $this->get(self::HOST, '/school/reports/courses.csv')->body;
        $this->assertStringContainsString('"Course school","Published",2,2,1,1,0,0,', $courses);
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action = 'reports.exported'"));
    }
}
