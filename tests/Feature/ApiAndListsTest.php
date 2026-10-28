<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

/** Update 36 (ADR 0036): API v1 with school keys, the payments list tools and the activity filters. */
final class ApiAndListsTest extends AppTestCase
{
    private const HOST = 'school.test';
    private const OTHER = 'other.test';

    private Tenant $tenant;
    private Tenant $other;
    private User $learner;
    private int $courseId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->other = $this->makeTenant('other', self::OTHER);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $teacher = $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $this->learner = $this->makeUser($this->tenant, 'ada@school.test', Role::Learner);
        $this->makeUser($this->tenant, 'bisi@school.test', Role::Learner);
        $this->makeUser($this->other, 'owner@other.test', Role::Owner);
        $this->makeUser($this->other, 'secret.learner@other.test', Role::Learner);
        $this->courseId = $this->makeCourse($this->tenant, $teacher, 'physics')['course']->id;
        $this->makeCourse($this->other, $this->makeUser($this->other, 't@other.test', Role::Educator), 'other-course');
    }

    private function as(string $email, string $host = self::HOST): void
    {
        $this->newBrowser();
        $this->login($host, $email, 'correct-horse-battery');
    }

    private function makeKey(string $host = self::HOST, string $owner = 'owner@school.test'): string
    {
        $this->as($owner, $host);
        $this->assertRedirectTo('/school/api', $this->post($host, '/school/api', ['name' => 'PrimeERP']));
        $page = $this->get($host, '/school/api')->body;
        $this->assertSame(1, preg_match('/(dmlk_[a-z0-9]{8}_[a-z0-9]{40})/', $page, $m), 'the key is shown once');
        $this->assertStringNotContainsString($m[1] ?? 'x', $this->get($host, '/school/api')->body, 'and never again');

        return $m[1] ?? '';
    }

    /** @param array<string, string> $query */
    private function api(string $path, string $key, string $host = self::HOST, array $query = []): Response
    {
        return $this->send(new Request('GET', $path, $host, $query, [], $key === '' ? [] : ['authorization' => 'Bearer ' . $key]));
    }

    /** @return array<string, mixed> */
    private static function json(Response $r): array
    {
        $data = json_decode($r->body, true);

        return is_array($data) ? $data : [];
    }

    public function testKeysReadOnlyTheirOwnSchoolAndStopWhenRevoked(): void
    {
        $this->assertSame(401, $this->api('/api/v1/courses', '')->status);
        $this->assertSame('unauthorised', self::json($this->api('/api/v1/courses', 'dmlk_nope'))['error']['code'] ?? null);

        $key = $this->makeKey();
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM api_keys'));
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM api_keys WHERE key_hash = ? OR prefix = ?', [$key, $key]), 'only a hash is stored');

        $courses = self::json($this->api('/api/v1/courses', $key));
        $this->assertSame(1, $courses['total']);
        $this->assertSame('physics', $courses['data'][0]['slug']);

        $learners = self::json($this->api('/api/v1/learners', $key));
        $emails = array_column($learners['data'], 'email');
        $this->assertContains('ada@school.test', $emails);
        $this->assertNotContains('secret.learner@other.test', $emails, 'never another school');
        $this->assertNotContains('owner@school.test', $emails, 'learners only');

        // The same key on another school's address is refused.
        $this->assertSame(401, $this->api('/api/v1/learners', $key, self::OTHER)->status);

        $this->as('owner@school.test');
        $id = (int) $this->db()->fetchValue('SELECT id FROM api_keys');
        $this->assertRedirectTo('/school/api', $this->post(self::HOST, '/school/api/' . $id . '/revoke'));
        $this->assertSame(401, $this->api('/api/v1/courses', $key)->status);
        $this->assertSame(2, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM audit_events WHERE action IN ('api.key_created', 'api.key_revoked')"));
    }

    public function testListsArePagedAndFiltered(): void
    {
        $key = $this->makeKey();
        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/physics/enrol');
        $page = self::json($this->api('/api/v1/learners', $key, self::HOST, ['per_page' => '1']));
        $this->assertSame(2, $page['total']);
        $this->assertCount(1, $page['data']);
        $this->assertStringContainsString('page=2', (string) $page['next']);
        $last = self::json($this->api('/api/v1/learners', $key, self::HOST, ['per_page' => '1', 'page' => '2']));
        $this->assertNull($last['next']);

        $enrol = self::json($this->api('/api/v1/enrolments', $key, self::HOST, ['learner' => (string) $this->learner->id]));
        $this->assertSame(1, $enrol['total']);
        $this->assertSame($this->courseId, $enrol['data'][0]['course_id']);
        $this->assertSame(2, $enrol['data'][0]['lessons_total']);
        $this->assertSame(0, self::json($this->api('/api/v1/enrolments', $key, self::HOST, ['status' => 'completed']))['total']);
        $this->assertSame(200, $this->api('/api/v1', $key)->status);
        $this->assertSame(200, $this->api('/api/v1/payments', $key, self::HOST, ['since' => '2026-01-01', 'status' => 'paid'])->status);
        $this->assertSame(200, $this->api('/api/v1/certificates', $key)->status);
    }

    public function testOnlySchoolManagersMakeKeys(): void
    {
        $this->as('teacher@school.test');
        $this->assertSame(403, $this->get(self::HOST, '/school/api')->status);
        $this->assertSame(403, $this->post(self::HOST, '/school/api', ['name' => 'Mine'])->status);
        $this->as('owner@school.test');
        $this->assertSame(422, $this->post(self::HOST, '/school/api', ['name' => 'x'])->status);
        $this->assertSame(0, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM api_keys'));
    }

    public function testPaymentHistoryFindsByNameAndCourseAndExportsWhatIsShown(): void
    {
        $other = $this->makeCourse($this->tenant, $this->makeUser($this->tenant, 'teacher2@school.test', Role::Educator), 'chemistry')['course']->id;
        $bisi = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'bisi@school.test'");
        $rows = [[$this->learner->id, $this->courseId, 'paid', 'REF-ADA-1'], [$bisi, $other, 'paid', 'REF-BISI-1'], [$bisi, $this->courseId, 'failed', 'REF-BISI-2']];
        foreach ($rows as [$user, $course, $status, $ref]) {
            $this->db()->execute(
                "INSERT INTO payments (tenant_id, user_id, course_id, reference, provider, mode, amount_minor, currency, status, created_at, updated_at, paid_at)
                 VALUES (?, ?, ?, ?, 'manual', 'live', 500000, 'NGN', ?, NOW(), NOW(), NOW())",
                [$this->tenant->id, $user, $course, $ref, $status],
            );
        }
        $this->as('owner@school.test');
        $all = $this->get(self::HOST, '/school/payments')->body;
        $this->assertStringContainsString('Paid <span class="tab-count">(2)</span>', $all);
        $this->assertStringContainsString('Showing 1 to 3 of 3 payments', $all);

        $bisiOnly = $this->get(self::HOST, '/school/payments?q=bisi')->body;
        $this->assertStringContainsString('REF-BISI-1', $bisiOnly);
        $this->assertStringNotContainsString('REF-ADA-1', $bisiOnly);
        $this->assertStringContainsString('All <span class="tab-count">(2)</span>', $bisiOnly, 'counts follow the words');

        $physics = $this->get(self::HOST, '/school/payments?course=' . $this->courseId . '&status=failed')->body;
        $this->assertStringContainsString('REF-BISI-2', $physics);
        $this->assertStringNotContainsString('REF-BISI-1', $physics);

        $csv = $this->get(self::HOST, '/school/payments.csv?q=REF-ADA')->body;
        $this->assertStringContainsString('REF-ADA-1', $csv);
        $this->assertStringNotContainsString('REF-BISI', $csv, 'the spreadsheet has what the list shows');
    }

    public function testActivityFiltersByKindAndPerson(): void
    {
        $this->as('ada@school.test');
        $this->post(self::HOST, '/courses/physics/enrol');
        $this->makeKey();
        $all = $this->get(self::HOST, '/activity')->body;
        $this->assertStringContainsString('Today', $all);
        $this->assertStringContainsString('made an API key for PrimeERP', $all);

        $learning = $this->get(self::HOST, '/activity?kind=learning')->body;
        $this->assertStringContainsString('enrolled in', $learning);
        $this->assertStringNotContainsString('signed in', $learning);

        $ada = $this->get(self::HOST, '/activity?who=ada%40school')->body;
        $this->assertStringContainsString('enrolled in', $ada);
        $this->assertStringNotContainsString('made an API key', $ada);
        $this->assertStringContainsString('Nothing matches', $this->get(self::HOST, '/activity?who=nobody-at-all')->body);
    }
}
