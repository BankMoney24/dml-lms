<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Core\Config;
use App\Modules\Identity\Role;
use App\Modules\Platform\SampleSchool;
use Tests\Support\AppTestCase;
use Tests\Support\TestDatabase;

/** ADR 0027: filling a school with sample data for testing, safely. */
final class SampleSchoolTest extends AppTestCase
{
    public function testItFillsANewSampleSchoolWithEveryKindOfActivity(): void
    {
        $result = (new SampleSchool(new Config(TestDatabase::config())))->run('sample', ['create' => 'Sample School', 'host' => 'sample.test', 'size' => 'small', 'seed' => '7']);
        $tid = $result['tenant']->id;
        $kinds = [
            'enrolments', 'lessons completed', 'quiz attempts', 'lab sessions', 'certificates', 'payments',
            'payment notes', 'applications', 'questions', 'groups', 'help requests', 'notifications', 'live classes', 'attendance marks',
        ];
        foreach ($kinds as $what) {
            $this->assertGreaterThan(0, $result['counts'][$what], $what);
        }
        $this->assertGreaterThanOrEqual(24 + 3 + 2, $result['counts']['people']);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9-_K]{16}$/', $result['password']);
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM users WHERE email NOT LIKE '%@sample.test'"), 'every account is a sample address');
        $soon = gmdate('Y-m-d H:i:s', time() + 120);
        $dated = [
            'enrolments' => 'enrolled_at', 'quiz_attempts' => 'submitted_at', 'notifications' => 'created_at',
            'certificates' => 'issued_at', 'lab_sessions' => 'started_at', 'audit_events' => 'created_at',
        ];
        foreach ($dated as $table => $column) {
            $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM {$table} WHERE tenant_id = ? AND {$column} > ?", [$tid, $soon]), $table . ' has nothing in the future');
        }
        $this->assertGreaterThan(30, (int) $this->db()->fetchValue('SELECT DATEDIFF(MAX(enrolled_at), MIN(enrolled_at)) FROM enrolments WHERE tenant_id = ?', [$tid]), 'activity is spread over weeks');
        $pendingNotes = (int) $this->db()->fetchValue("SELECT COUNT(*) FROM payment_claims WHERE tenant_id = ? AND status = 'pending'", [$tid]);
        $this->assertGreaterThanOrEqual(3, $pendingNotes, 'payment notes waiting for the school (3 set up, plus any made late today)');

        // The owner can sign in with the printed password and sees a busy school.
        $this->assertRedirectTo('/', $this->login('sample.test', 'owner@sample.test', $result['password']));
        $this->assertStringContainsString('Payments to confirm', $this->get('sample.test', '/school/payments')->body);
        $this->assertSame(200, $this->get('sample.test', '/school/reports')->status);
        $this->assertStringContainsString("Disallow: /\n", $this->get('sample.test', '/robots.txt')->body, 'kept out of search engines');

        $this->expectExceptionMessage('already has sample data');
        (new SampleSchool(new Config(TestDatabase::config())))->run('sample', []);
    }

    public function testItNeverMixesSamplePeopleIntoARealSchoolInProduction(): void
    {
        $tenant = $this->makeTenant('real', 'real.test');
        $this->makeUser($tenant, 'head@real.test', Role::Owner);
        $production = new Config(['APP_ENV' => 'production'] + TestDatabase::config());
        try {
            (new SampleSchool($production))->run('real', ['size' => 'small']);
            $this->fail('A real school must be refused in production.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('only goes into a school this command created', $e->getMessage());
        }
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM memberships WHERE tenant_id = ?', [$tenant->id]));

        try {
            (new SampleSchool($production))->run('nowhere', []);
            $this->fail('An unknown school needs --create.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('--create', $e->getMessage());
        }
        $this->assertNull($this->db()->fetchValue("SELECT id FROM tenants WHERE slug = 'nowhere'"));
    }
}
