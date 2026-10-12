<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Security\Csrf;
use App\Core\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\TwoFactor\TwoFactorService;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

final class AuthService
{
    public const SESSION_USER = 'auth.user_id';
    public const SESSION_TENANT = 'auth.tenant_id';
    public const SESSION_VERSION = 'auth.session_version';
    private const PENDING_USER = 'auth.pending_user_id';
    private const PENDING_TENANT = 'auth.pending_tenant_id';
    private const PENDING_AT = 'auth.pending_at';
    private const PENDING_SECONDS = 300;

    public const GENERIC_FAILURE = 'That email and password did not match an account here. Check them and try again.';
    public const LOCKED_FAILURE = 'Too many sign-in attempts. Wait 15 minutes, then try again.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
        private readonly TwoFactorService $twoFactor,
    ) {
    }

    /**
     * First step: email and password. When the account has two-step sign-in,
     * the user is not signed in yet; the caller sends them to the code step.
     *
     * @return array{ok: bool, message: ?string, user: ?User, second_factor?: bool}
     */
    public function attempt(Tenant $tenant, string $email, string $password, string $clientIp): array
    {
        if ($this->throttle->isLocked($tenant->id, $email, $clientIp)) {
            $this->audit->record($tenant->id, null, 'auth.login_locked', null, null, [], $clientIp);

            return ['ok' => false, 'message' => self::LOCKED_FAILURE, 'user' => null];
        }

        $user = $this->users->findByEmail($email);
        $hash = $user === null ? null : $this->users->passwordHashFor($user->id);
        // Verify against a dummy hash when the account is unknown, so timing does not reveal existence.
        $passwordOk = password_verify($password, $hash ?? self::dummyHash());

        $membership = $user === null ? null : $this->memberships->find($tenant->id, $user->id);
        $allowed = $user !== null
            && $passwordOk
            && $user->isActive()
            && $membership !== null
            && $membership->isActive();

        if (!$allowed) {
            $this->throttle->recordFailure($tenant->id, $email, $clientIp);
            $this->audit->record($tenant->id, $user?->id, 'auth.login_failed', null, null, [], $clientIp);

            return ['ok' => false, 'message' => self::GENERIC_FAILURE, 'user' => null];
        }

        if ($hash !== null && password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            $this->users->rehashPassword($user->id, password_hash($password, PASSWORD_DEFAULT));
        }

        if ($this->twoFactor->isEnabled($user->id)) {
            $this->session->regenerate();
            $this->csrf->rotate();
            $this->session->set(self::PENDING_USER, $user->id);
            $this->session->set(self::PENDING_TENANT, $tenant->id);
            $this->session->set(self::PENDING_AT, time());

            return ['ok' => true, 'message' => null, 'user' => $user, 'second_factor' => true];
        }

        $this->completeLogin($tenant, $user, $clientIp, 'password');

        return ['ok' => true, 'message' => null, 'user' => $user, 'second_factor' => false];
    }

    public function hasPendingSecondFactor(Tenant $tenant): bool
    {
        return $this->pendingUserId($tenant) !== null;
    }

    /**
     * Second step: a code from the authenticator app or a recovery code.
     * Failures count towards the same lock as wrong passwords.
     *
     * @return array{ok: bool, message: ?string, restart: bool}
     */
    public function verifySecondFactor(Tenant $tenant, string $code, string $clientIp): array
    {
        $userId = $this->pendingUserId($tenant);
        $user = $userId === null ? null : $this->users->findById($userId);
        if ($user === null) {
            $this->clearPending();

            return ['ok' => false, 'message' => 'Your sign-in timed out. Enter your email and password again.', 'restart' => true];
        }
        if ($this->throttle->isLocked($tenant->id, $user->email, $clientIp)) {
            $this->clearPending();

            return ['ok' => false, 'message' => self::LOCKED_FAILURE, 'restart' => true];
        }

        $method = $this->twoFactor->verify($user->id, $code);
        if ($method === null) {
            $this->throttle->recordFailure($tenant->id, $user->email, $clientIp);
            $this->audit->record($tenant->id, $user->id, 'auth.second_step_failed', 'user', $user->id, [], $clientIp);

            return ['ok' => false, 'message' => 'That code did not work. Enter the newest code from your app, or one of your recovery codes.', 'restart' => false];
        }

        $membership = $this->memberships->find($tenant->id, $user->id);
        if (!$user->isActive() || $membership === null || !$membership->isActive()) {
            $this->clearPending();

            return ['ok' => false, 'message' => self::GENERIC_FAILURE, 'restart' => true];
        }

        $this->clearPending();
        $this->completeLogin($tenant, $user, $clientIp, $method);

        return ['ok' => true, 'message' => null, 'restart' => false];
    }

    private function completeLogin(Tenant $tenant, User $user, string $clientIp, string $method): void
    {
        $this->throttle->clear($tenant->id, $user->email);
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set(self::SESSION_USER, $user->id);
        $this->session->set(self::SESSION_TENANT, $tenant->id);
        $this->session->set(self::SESSION_VERSION, $user->sessionVersion);
        $this->audit->record($tenant->id, $user->id, 'auth.login', 'user', $user->id, $method === 'password' ? [] : ['method' => $method], $clientIp);
    }

    private function pendingUserId(Tenant $tenant): ?int
    {
        $userId = $this->session->get(self::PENDING_USER);
        $at = $this->session->get(self::PENDING_AT);
        if (!is_int($userId) || !is_int($at) || $this->session->get(self::PENDING_TENANT) !== $tenant->id) {
            return null;
        }
        if (time() - $at > self::PENDING_SECONDS) {
            $this->clearPending();

            return null;
        }

        return $userId;
    }

    private function clearPending(): void
    {
        $this->session->remove(self::PENDING_USER);
        $this->session->remove(self::PENDING_TENANT);
        $this->session->remove(self::PENDING_AT);
    }

    public function logout(?int $tenantId, ?int $userId, string $clientIp): void
    {
        if ($userId !== null) {
            $this->audit->record($tenantId, $userId, 'auth.logout', 'user', $userId, [], $clientIp);
        }
        $this->session->invalidate();
        $this->csrf->rotate();
    }

    /**
     * Resolves the signed-in user for this tenant, or null. Any mismatch —
     * different tenant, suspended account, changed password, removed
     * membership — ends the session.
     */
    public function resolve(Tenant $tenant): ?CurrentUser
    {
        $userId = $this->session->get(self::SESSION_USER);
        if (!is_int($userId)) {
            return null;
        }

        $user = $this->users->findById($userId);
        $membership = $user === null ? null : $this->memberships->find($tenant->id, $user->id);
        $valid = $user !== null
            && $this->session->get(self::SESSION_TENANT) === $tenant->id
            && $this->session->get(self::SESSION_VERSION) === $user->sessionVersion
            && $user->isActive()
            && $membership !== null
            && $membership->isActive();

        if (!$valid) {
            $this->session->invalidate();

            return null;
        }

        return new CurrentUser($user, $membership);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    /** @return list<string> problems with the password, empty when acceptable */
    public static function passwordProblems(string $password): array
    {
        $problems = [];
        if (mb_strlen($password) < 12) {
            $problems[] = 'Use at least 12 characters.';
        }
        if (mb_strlen($password) > 200) {
            $problems[] = 'Use at most 200 characters.';
        }

        return $problems;
    }

    private static function dummyHash(): string
    {
        static $hash = null;

        return $hash ??= password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
    }
}
