<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Identity\TwoFactor\Totp;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;
use Tests\Support\AppTestCase;

final class PlatformSchoolsTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
    }

    private function signInAsPlatformAdmin(): void
    {
        $this->db()->execute("UPDATE users SET is_platform_admin = 1 WHERE email = 'owner@school.test'");
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $page = $this->get(self::HOST, '/account/two-step')->body;
        preg_match('#<span class="secret">([A-Z2-7 ]+)</span>#', $page, $m);
        $this->post(self::HOST, '/account/two-step/enable', ['code' => Totp::codeAt(str_replace(' ', '', $m[1] ?? ''), Totp::step(time()))]);
    }

    /** @param array<string, string> $overrides */
    private function createSchool(array $overrides = []): \App\Core\Http\Response
    {
        return $this->post(self::HOST, '/system/schools', $overrides + [
            'name' => 'Sunrise Academy, Ibadan',
            'host' => 'https://Learn.Sunrise.test/',
            'owner_name' => 'Bisi Adeyemi',
            'owner_email' => 'bisi@sunrise.test',
        ]);
    }

    public function testOnlyPlatformAdminsManageSchools(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');

        $this->assertSame(404, $this->get(self::HOST, '/system/schools')->status, 'a school owner is not a platform admin');
        $this->assertSame(404, $this->createSchool()->status);
        $this->assertSame(404, $this->post(self::HOST, '/system/schools/' . $this->tenant->id . '/status', ['status' => 'suspended'])->status);
        $this->assertSame(1, (int) $this->db()->fetchValue('SELECT COUNT(*) FROM tenants'));
    }

    public function testCreatingASchoolGivesItAnAddressAndAnOwnerWhoCanSetItUp(): void
    {
        $this->signInAsPlatformAdmin();
        $this->assertRedirectTo('/system/schools', $this->createSchool());

        $school = $this->app->get(TenantRepository::class)->findBySlug('sunrise-academy-ibadan');
        $this->assertNotNull($school);
        $this->assertFalse($school->isSetUp());
        $this->assertSame([['host' => 'learn.sunrise.test', 'primary' => true, 'verified' => true]], $this->app->get(TenantRepository::class)->domains($school->id));

        $page = $this->get(self::HOST, '/system/schools')->body;
        $this->assertStringContainsString('Sunrise Academy, Ibadan was created', $page);
        $this->assertSame(1, preg_match('#<span class="secret">([A-Za-z0-9-]+)</span>#', $page, $m), 'one-time password shown (email is off)');
        $this->assertStringContainsString('school.created', (string) $this->db()->fetchValue('SELECT GROUP_CONCAT(action) FROM audit_events WHERE tenant_id = ?', [$school->id]));

        // The new owner signs in at the new address and is sent to choose a password, then set up the school.
        $this->newBrowser();
        $this->login('learn.sunrise.test', 'bisi@sunrise.test', $m[1] ?? '');
        $this->assertSame('owner', $this->db()->fetchValue('SELECT m.role FROM memberships m JOIN users u ON u.id = m.user_id WHERE u.email = ?', ['bisi@sunrise.test']));
        $elsewhere = $this->db()->fetchValue(
            'SELECT COUNT(*) FROM memberships m JOIN users u ON u.id = m.user_id WHERE u.email = ? AND m.tenant_id = ?',
            ['bisi@sunrise.test', $this->tenant->id],
        );
        $this->assertSame(0, (int) $elsewhere, 'nothing in the other school');
    }

    public function testAddressesAreCheckedAndUnique(): void
    {
        $this->signInAsPlatformAdmin();
        $this->createSchool();

        $taken = $this->createSchool(['name' => 'Copycat', 'host' => 'school.test']);
        $this->assertSame(422, $taken->status);
        $this->assertStringContainsString('already used by a school', $taken->body);
        foreach (['not a host', 'localhost', 'http://', '-bad-.test'] as $host) {
            $this->assertSame(422, $this->createSchool(['name' => 'Bad ' . $host, 'host' => $host])->status, $host);
        }

        $id = (int) $this->db()->fetchValue("SELECT id FROM tenants WHERE slug = 'sunrise-academy-ibadan'");
        $this->assertRedirectTo('/system/schools/' . $id, $this->post(self::HOST, '/system/schools/' . $id . '/addresses', ['host' => 'sunrise.test']));
        $this->assertSame($id, $this->app->get(TenantRepository::class)->findByVerifiedHost('sunrise.test')?->id);
        $this->assertSame(422, $this->post(self::HOST, '/system/schools/' . $id . '/addresses', ['host' => 'learn.sunrise.test'])->status);

        // Same name twice gets a different short name.
        $this->createSchool(['host' => 'two.sunrise.test', 'owner_email' => 'other@sunrise.test']);
        $this->assertNotNull($this->app->get(TenantRepository::class)->findBySlug('sunrise-academy-ibadan-2'));
    }

    public function testSuspendingASchoolClosesItButNotTheOneYouAreOn(): void
    {
        $this->signInAsPlatformAdmin();
        $this->createSchool();
        $id = (int) $this->db()->fetchValue("SELECT id FROM tenants WHERE slug = 'sunrise-academy-ibadan'");

        $this->post(self::HOST, '/system/schools/' . $id . '/status', ['status' => 'suspended']);
        $this->assertSame(503, $this->get('learn.sunrise.test', '/')->status);

        $this->post(self::HOST, '/system/schools/' . $this->tenant->id . '/status', ['status' => 'suspended']);
        $this->assertTrue($this->app->get(TenantRepository::class)->findById($this->tenant->id)?->isActive(), 'cannot lock yourself out');

        $this->post(self::HOST, '/system/schools/' . $id . '/status', ['status' => 'active']);
        $this->assertSame(200, $this->get('learn.sunrise.test', '/')->status);
        $this->assertSame(['school.suspended', 'school.reactivated'], array_column($this->db()->fetchAll(
            "SELECT action FROM audit_events WHERE tenant_id = ? AND action IN ('school.suspended', 'school.reactivated') ORDER BY id",
            [$id],
        ), 'action'));
    }
}
