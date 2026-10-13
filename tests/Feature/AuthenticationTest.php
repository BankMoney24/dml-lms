<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Modules\Identity\AuthService;
use App\Modules\Identity\LoginThrottle;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\MembershipRepository;
use Tests\Support\AppTestCase;

final class AuthenticationTest extends AppTestCase
{
    private const HOST = 'alpha.test';

    public function testSuccessfulLoginRegeneratesSessionAndRedirects(): void
    {
        $tenant = $this->makeTenant('alpha', self::HOST);
        $this->makeUser($tenant, 'ada@example.org', Role::Learner);
        $before = $this->session->regenerations;

        $response = $this->login(self::HOST, 'ADA@example.org ', 'correct-horse-battery');

        $this->assertRedirectTo('/', $response);
        $this->assertGreaterThan($before, $this->session->regenerations);
        $this->assertStringContainsString(', Test</h1>', $this->get(self::HOST, '/')->body);
    }

    public function testWrongPasswordAndUnknownAccountGetTheSameMessage(): void
    {
        $tenant = $this->makeTenant('alpha', self::HOST);
        $this->makeUser($tenant, 'ada@example.org', Role::Learner);

        $wrongPassword = $this->login(self::HOST, 'ada@example.org', 'not-the-password');
        $unknown = $this->login(self::HOST, 'nobody@example.org', 'not-the-password');

        $this->assertSame(422, $wrongPassword->status);
        $this->assertSame(422, $unknown->status);
        $message = htmlspecialchars(AuthService::GENERIC_FAILURE, ENT_QUOTES);
        $this->assertStringContainsString($message, $wrongPassword->body);
        $this->assertStringContainsString($message, $unknown->body);
        $this->assertStringContainsString('value="ada@example.org"', $wrongPassword->body, 'email is preserved');
        $this->assertStringNotContainsString('not-the-password', $wrongPassword->body, 'password is never echoed');
    }

    public function testAccountLocksAfterRepeatedFailures(): void
    {
        $tenant = $this->makeTenant('alpha', self::HOST);
        $this->makeUser($tenant, 'ada@example.org', Role::Learner);

        for ($i = 0; $i < LoginThrottle::ACCOUNT_LIMIT; $i++) {
            $this->login(self::HOST, 'ada@example.org', 'guess-' . $i);
        }
        $response = $this->login(self::HOST, 'ada@example.org', 'correct-horse-battery');

        $this->assertSame(422, $response->status);
        $this->assertStringContainsString('Too many sign-in attempts', $response->body);
    }

    public function testMemberOfAnotherInstitutionCannotSignInHere(): void
    {
        $alpha = $this->makeTenant('alpha', self::HOST);
        $this->makeTenant('beta', 'beta.test');
        $this->makeUser($alpha, 'ada@example.org', Role::Learner);

        $response = $this->login('beta.test', 'ada@example.org', 'correct-horse-battery');

        $this->assertSame(422, $response->status);
    }

    public function testSuspendedUserCannotSignIn(): void
    {
        $tenant = $this->makeTenant('alpha', self::HOST);
        $user = $this->makeUser($tenant, 'ada@example.org', Role::Learner);
        $this->app->get(UserRepository::class)->setStatus($user->id, User::STATUS_SUSPENDED);

        $this->assertSame(422, $this->login(self::HOST, 'ada@example.org', 'correct-horse-battery')->status);
    }

    public function testPasswordChangeEndsExistingSessions(): void
    {
        $tenant = $this->makeTenant('alpha', self::HOST);
        $user = $this->makeUser($tenant, 'ada@example.org', Role::Educator);
        $this->login(self::HOST, 'ada@example.org', 'correct-horse-battery');
        $this->assertSame(200, $this->get(self::HOST, '/teach')->status);

        $this->app->get(UserRepository::class)->updatePassword($user->id, AuthService::hashPassword('another-strong-pass'));

        $this->assertRedirectTo('/login', $this->get(self::HOST, '/teach'));
    }

    public function testRemovedMembershipEndsAccess(): void
    {
        $tenant = $this->makeTenant('alpha', self::HOST);
        $user = $this->makeUser($tenant, 'teacher@example.org', Role::Educator);
        $this->login(self::HOST, 'teacher@example.org', 'correct-horse-battery');
        $this->assertSame(200, $this->get(self::HOST, '/teach')->status);

        $this->app->get(MembershipRepository::class)->remove($tenant->id, $user->id);

        $this->assertRedirectTo('/login', $this->get(self::HOST, '/teach'));
    }

    public function testLogoutRequiresCsrfAndEndsSession(): void
    {
        $tenant = $this->makeTenant('alpha', self::HOST);
        $this->makeUser($tenant, 'teacher@example.org', Role::Educator);
        $this->login(self::HOST, 'teacher@example.org', 'correct-horse-battery');

        $this->assertSame(419, $this->post(self::HOST, '/logout', [], false)->status);
        $this->assertSame(200, $this->get(self::HOST, '/teach')->status);

        $this->assertRedirectTo('/login', $this->post(self::HOST, '/logout'));
        $this->assertRedirectTo('/login', $this->get(self::HOST, '/teach'));
    }

    public function testLoginIsAuditedWithoutStoringTheRawIp(): void
    {
        $tenant = $this->makeTenant('alpha', self::HOST);
        $this->makeUser($tenant, 'ada@example.org', Role::Learner);
        $this->login(self::HOST, 'ada@example.org', 'correct-horse-battery');

        $row = $this->db()->fetchOne("SELECT * FROM audit_events WHERE action = 'auth.login'");
        $this->assertNotNull($row);
        $this->assertSame($tenant->id, (int) $row['tenant_id']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $row['ip_hash']);
    }
}
