<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\LoginThrottle;
use App\Modules\Identity\Role;
use App\Modules\Identity\TwoFactor\Totp;
use App\Modules\Tenancy\Tenant;
use Tests\Support\AppTestCase;

final class TwoStepSignInTest extends AppTestCase
{
    private const HOST = 'school.test';

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config['TWO_FACTOR_REQUIRED_ROLES'] = 'owner,admin';
        $this->app = $this->freshApp($this->session);
        $this->tenant = $this->makeTenant('school', self::HOST);
        $this->makeUser($this->tenant, 'owner@school.test', Role::Owner);
        $this->makeUser($this->tenant, 'admin@school.test', Role::Admin);
        $this->makeUser($this->tenant, 'tobi@school.test', Role::Learner);
    }

    /** @return array{secret: string, codes: list<string>} */
    private function enrol(): array
    {
        $page = $this->get(self::HOST, '/account/two-step')->body;
        $this->assertSame(1, preg_match('#<span class="secret">([A-Z2-7 ]+)</span>#', $page, $m), 'setup key shown');
        $secret = str_replace(' ', '', $m[1] ?? '');

        $result = $this->post(self::HOST, '/account/two-step/enable', ['code' => Totp::codeAt($secret, Totp::step(time()))]);
        $this->assertSame(200, $result->status);
        preg_match_all('#<li><code>([a-z2-7]{4}-[a-z2-7]{4})</code></li>#', $result->body, $codes);
        $this->assertCount(10, $codes[1], 'ten recovery codes shown once');

        return ['secret' => $secret, 'codes' => $codes[1]];
    }

    private function freshCode(string $secret): string
    {
        // A step not used yet: the next one (accepted as clock drift).
        return Totp::codeAt($secret, Totp::step(time()) + 1);
    }

    public function testOwnerMustTurnItOnBeforeDoingAnything(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');

        $this->assertRedirectTo('/account/two-step', $this->get(self::HOST, '/'));
        $this->assertRedirectTo('/account/two-step', $this->get(self::HOST, '/people'));

        $this->enrol();
        $this->assertSame(200, $this->get(self::HOST, '/people')->status);
    }

    public function testLearnersAreNotForced(): void
    {
        $this->login(self::HOST, 'tobi@school.test', 'correct-horse-battery');

        $this->assertSame(200, $this->get(self::HOST, '/')->status);
    }

    public function testSecretIsEncryptedAtRest(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $secret = $this->enrol()['secret'];

        $stored = (string) $this->db()->fetchValue("SELECT totp_secret FROM users WHERE email = 'owner@school.test'");
        $this->assertStringStartsWith('v1:', $stored);
        $this->assertStringNotContainsString($secret, $stored);
        $this->assertSame(0, (int) $this->db()->fetchValue("SELECT COUNT(*) FROM recovery_codes WHERE code_hash LIKE '%-%'"), 'codes stored hashed');
    }

    public function testSignInNeedsTheCodeAfterThePassword(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $secret = $this->enrol()['secret'];
        $this->post(self::HOST, '/logout');

        $afterPassword = $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->assertRedirectTo('/login/verify', $afterPassword);
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/people'), 'not signed in yet');

        $wrong = $this->post(self::HOST, '/login/verify', ['code' => '000000']);
        $this->assertSame(422, $wrong->status);

        $this->assertRedirectTo('/people', $this->post(self::HOST, '/login/verify', ['code' => $this->freshCode($secret)]), 'back to the page they asked for');
        $this->assertSame(200, $this->get(self::HOST, '/people')->status);
    }

    public function testACodeCannotBeReplayed(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $secret = $this->enrol()['secret'];
        $code = $this->freshCode($secret);
        $this->post(self::HOST, '/logout');
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->post(self::HOST, '/login/verify', ['code' => $code]);
        $this->post(self::HOST, '/logout');

        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->assertSame(422, $this->post(self::HOST, '/login/verify', ['code' => $code])->status);
    }

    public function testRecoveryCodeWorksOnce(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $code = $this->enrol()['codes'][0];
        $this->post(self::HOST, '/logout');

        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->assertRedirectTo('/', $this->post(self::HOST, '/login/verify', ['code' => strtoupper($code)]));
        $this->post(self::HOST, '/logout');

        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->assertSame(422, $this->post(self::HOST, '/login/verify', ['code' => $code])->status);
    }

    public function testRepeatedWrongCodesLockTheAccount(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $secret = $this->enrol()['secret'];
        $this->post(self::HOST, '/logout');
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');

        for ($i = 0; $i < LoginThrottle::ACCOUNT_LIMIT; $i++) {
            $this->post(self::HOST, '/login/verify', ['code' => '111111']);
        }
        $locked = $this->post(self::HOST, '/login/verify', ['code' => $this->freshCode($secret)]);

        $this->assertRedirectTo('/login', $locked);
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/people'));
    }

    public function testRequiredRolesCannotTurnItOff(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $secret = $this->enrol()['secret'];

        $response = $this->post(self::HOST, '/account/two-step/disable', ['password' => 'correct-horse-battery', 'code' => $this->freshCode($secret)]);

        $this->assertSame(422, $response->status);
        $this->assertNotNull($this->db()->fetchValue("SELECT totp_enabled_at FROM users WHERE email = 'owner@school.test'"));
    }

    public function testOwnerResetsAnAdminWhoLostTheirPhone(): void
    {
        $this->login(self::HOST, 'admin@school.test', 'correct-horse-battery');
        $this->enrol();
        $adminBrowser = $this->session;

        $this->newBrowser();
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->enrol();
        $adminId = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'admin@school.test'");
        $this->post(self::HOST, '/people/' . $adminId . '/reset-two-step');

        $this->assertNull($this->db()->fetchValue('SELECT totp_enabled_at FROM users WHERE id = ?', [$adminId]));
        $this->useBrowser($adminBrowser);
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/people'), 'their sessions ended');
        $this->login(self::HOST, 'admin@school.test', 'correct-horse-battery');
        $this->assertRedirectTo('/account/two-step', $this->get(self::HOST, '/'), 'must set it up again');
    }

    public function testAdminsCannotResetTwoStep(): void
    {
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->enrol();
        $ownerId = (int) $this->db()->fetchValue("SELECT id FROM users WHERE email = 'owner@school.test'");
        $this->post(self::HOST, '/logout');

        $this->newBrowser();
        $this->login(self::HOST, 'admin@school.test', 'correct-horse-battery');
        $this->enrol();
        $this->post(self::HOST, '/people/' . $ownerId . '/reset-two-step');

        $this->assertNotNull($this->db()->fetchValue('SELECT totp_enabled_at FROM users WHERE id = ?', [$ownerId]));
    }

    public function testPasswordResetDoesNotBypassTwoStep(): void
    {
        $this->configure('MAIL_DRIVER', 'file');
        $this->login(self::HOST, 'owner@school.test', 'correct-horse-battery');
        $this->enrol();
        $this->post(self::HOST, '/logout');

        $this->post(self::HOST, '/password/forgot', ['email' => 'owner@school.test']);
        $link = $this->linkIn($this->deliverMail()[0], '/password/reset/');
        $this->post(self::HOST, $link, ['new_password' => 'reset but still safe', 'confirm_password' => 'reset but still safe']);

        $this->assertRedirectTo('/login/verify', $this->login(self::HOST, 'owner@school.test', 'reset but still safe'));
    }
}
