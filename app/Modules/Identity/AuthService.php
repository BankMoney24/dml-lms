<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Security\Csrf;
use App\Core\Session\Session;
use App\Modules\Audit\AuditLog;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

final class AuthService
{
    public const SESSION_USER = 'auth.user_id';
    public const SESSION_TENANT = 'auth.tenant_id';
    public const SESSION_VERSION = 'auth.session_version';

    public const GENERIC_FAILURE = 'That email and password did not match an account here. Check them and try again.';
    public const LOCKED_FAILURE = 'Too many sign-in attempts. Wait 15 minutes, then try again.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly LoginThrottle $throttle,
        private readonly Session $session,
        private readonly Csrf $csrf,
        private readonly AuditLog $audit,
    ) {
    }

    /** @return array{ok: bool, message: ?string, user: ?User} */
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

        $this->throttle->clear($tenant->id, $email);
        $this->session->regenerate();
        $this->csrf->rotate();
        $this->session->set(self::SESSION_USER, $user->id);
        $this->session->set(self::SESSION_TENANT, $tenant->id);
        $this->session->set(self::SESSION_VERSION, $user->sessionVersion);
        $this->audit->record($tenant->id, $user->id, 'auth.login', 'user', $user->id, [], $clientIp);

        return ['ok' => true, 'message' => null, 'user' => $user];
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
