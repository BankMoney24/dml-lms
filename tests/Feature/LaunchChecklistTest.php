<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Tenancy\LaunchChecklist;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use Tests\Support\AppTestCase;

final class LaunchChecklistTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->app->get(TenantRepository::class)->markSetUp($this->tenant->id);
    }

    /** @return array<string, bool> item key => done */
    private function state(): array
    {
        $tenant = $this->app->get(TenantRepository::class)->findById($this->tenant->id) ?? $this->tenant;
        $out = [];
        foreach ($this->app->get(LaunchChecklist::class)->items($tenant) as $item) {
            $out[$item['key']] = $item['done'];
        }

        return $out;
    }

    public function testANewSchoolStartsWithEverythingToDo(): void
    {
        $this->assertSame(
            ['courses' => false, 'teachers' => false, 'payments' => false, 'website' => false, 'email' => false, 'classroom' => false, 'two_step' => false],
            $this->state(),
        );
    }

    public function testItemsTickThemselvesFromTheSchoolsRealSettings(): void
    {
        $db = $this->db();
        $this->makeUser($this->tenant, 'teacher@school.test', Role::Educator);
        $owner = (int) $db->fetchValue('SELECT id FROM users WHERE email = ?', ['owner@school.test']);
        $db->execute(
            "INSERT INTO courses (tenant_id, title, slug, summary, status, created_by, created_at, updated_at) VALUES (?, 'Networks', 'networks', '', 'published', ?, NOW(), NOW())",
            [$this->tenant->id, $owner],
        );
        $db->execute('UPDATE users SET totp_enabled_at = NOW() WHERE email = ?', ['owner@school.test']);
        foreach (LaunchChecklist::CORE_PAGES as $slug) {
            $db->execute(
                "INSERT INTO site_pages (tenant_id, slug, title, summary, body, status, updated_by, created_at, updated_at) VALUES (?, ?, ?, '', 'Text', 'published', NULL, NOW(), NOW())",
                [$this->tenant->id, $slug, ucfirst($slug)],
            );
        }

        $state = $this->state();
        $this->assertTrue($state['courses']);
        $this->assertTrue($state['teachers']);
        $this->assertTrue($state['two_step']);
        $this->assertTrue($state['website'], 'the four core pages are enough while no fees are taken online');
        $this->assertFalse($state['payments']);
        $this->assertFalse($state['email'], 'the test configuration writes mail to files, which is not real delivery');
    }

    public function testSettingsPageShowsTheListToAdministratorsOnly(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $page = $this->get(self::HOST, '/school')->body;
        $this->assertStringContainsString('Ready for learners?', $page);
        $this->assertStringContainsString('<strong>0 of 7</strong> ready', $page);
        $this->assertStringContainsString('Still to publish: about us, contact us, privacy policy, terms of use', $page);
        $this->assertStringContainsString('href="/school/payments">Connect the live key</a>', $page);

        $this->makeUser($this->tenant, 'learner@school.test', Role::Learner);
        $this->newBrowser();
        $this->login(self::HOST, 'learner@school.test', 'correct-horse-battery');
        $this->assertSame(403, $this->get(self::HOST, '/school')->status);
    }
}
