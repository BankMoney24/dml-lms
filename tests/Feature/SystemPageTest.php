<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\Role;
use App\Modules\Identity\TwoFactor\Totp;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class SystemPageTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->storage = sys_get_temp_dir() . '/dml-lms-test-storage';
        @mkdir($this->storage, 0770, true);
        @unlink($this->storage . '/backup-status.json');
        @unlink($this->storage . '/restore-check.json');
    }

    protected function tearDown(): void
    {
        @unlink($this->storage . '/backup-status.json');
        @unlink($this->storage . '/restore-check.json');
        parent::tearDown();
    }

    private function signInAsPlatformAdmin(): void
    {
        $this->db()->execute("UPDATE users SET is_platform_admin = 1 WHERE email = 'owner@school.test'");
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        // Platform administrators must turn on two-step sign-in first.
        $this->assertRedirectTo('/account/two-step', $this->get(self::HOST, '/system'));
        $page = $this->get(self::HOST, '/account/two-step')->body;
        preg_match('#<span class="secret">([A-Z2-7 ]+)</span>#', $page, $m);
        $secret = str_replace(' ', '', $m[1] ?? '');
        $this->post(self::HOST, '/account/two-step/enable', ['code' => Totp::codeAt($secret, Totp::step(time()))]);
    }

    /** @param array<string, mixed> $data */
    private function writeStatus(string $name, array $data): void
    {
        file_put_contents($this->storage . '/' . $name, json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function testOnlyPlatformAdminsSeeIt(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');

        $this->assertSame(404, $this->get(self::HOST, '/system')->status, 'a school owner is not a platform admin');
        $this->assertStringNotContainsString('href="/system"', $this->get(self::HOST, '/')->body);
    }

    public function testNothingConfiguredShowsWhatToDo(): void
    {
        $this->signInAsPlatformAdmin();

        $page = $this->get(self::HOST, '/system');

        $this->assertSame(200, $page->status);
        $this->assertStringContainsString('No backup has run yet', $page->body);
        $this->assertStringContainsString('No restore test has run', $page->body);
        $this->assertStringContainsString('Email is off', $page->body);
        $this->assertStringContainsString('not virus-scanned', $page->body);
        $this->assertStringContainsString('All database changes are applied', $page->body);
        $this->assertStringContainsString('href="/system"', $page->body, 'nav link for platform admins');
    }

    public function testHealthyBackupsAndRestoreTest(): void
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $this->writeStatus('backup-status.json', [
            'last_run_at' => $now, 'last_result' => 'ok', 'last_message' => 'ok', 'last_success_at' => $now,
            'last_success_file' => 'dml-lms-x.tar.gpg', 'last_success_bytes' => 2048, 'last_success_offsite' => 'copied to backup@host:/b/', 'keep_days' => 14,
        ]);
        $this->writeStatus('restore-check.json', ['checked_at' => $now, 'result' => 'ok', 'message' => 'ok', 'file' => 'dml-lms-x.tar.gpg', 'duration_seconds' => 3]);
        $this->signInAsPlatformAdmin();

        $page = $this->get(self::HOST, '/system')->body;

        $this->assertStringContainsString('Backups are recent and copied off the server.', $page);
        $this->assertStringContainsString('matched', $page);
        $this->assertStringContainsString('dml-lms-x.tar.gpg', $page);
    }

    public function testStaleOrFailingBackupsAreFlagged(): void
    {
        $old = gmdate('Y-m-d\TH:i:s\Z', time() - 3 * 86400);
        $this->writeStatus('backup-status.json', [
            'last_run_at' => gmdate('Y-m-d\TH:i:s\Z'), 'last_result' => 'failed', 'last_message' => 'failed at line 89',
            'last_success_at' => $old, 'last_success_file' => 'old.tar.gpg', 'last_success_bytes' => 1, 'last_success_offsite' => 'not configured', 'keep_days' => 14,
        ]);
        $this->writeStatus('restore-check.json', ['checked_at' => $old, 'result' => 'failed', 'message' => 'users: manifest 6, restored 5', 'file' => 'old.tar.gpg', 'duration_seconds' => 3]);
        $this->signInAsPlatformAdmin();

        $page = $this->get(self::HOST, '/system')->body;

        $this->assertStringContainsString('The last good backup is 72 hours old', $page);
        $this->assertStringContainsString('The last restore test failed: users: manifest 6, restored 5', $page);
        $this->assertStringContainsString('✕ Problem', $page);
    }

    public function testPlatformAdminsCannotTurnTwoStepOff(): void
    {
        $this->signInAsPlatformAdmin();

        $response = $this->post(self::HOST, '/account/two-step/disable', ['password' => 'correct-horse-battery', 'code' => '000000']);

        $this->assertSame(422, $response->status);
    }
}
