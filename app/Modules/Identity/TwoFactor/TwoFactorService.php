<?php

declare(strict_types=1);

namespace App\Modules\Identity\TwoFactor;

use App\Core\Clock;
use App\Core\Http\HttpException;
use App\Core\Security\SecretBox;
use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Two-step sign-in with an authenticator app (IAM-005). Secrets are
 * encrypted at rest; recovery codes are stored as keyed hashes and shown
 * once. Roles listed in TWO_FACTOR_REQUIRED_ROLES must have it enabled.
 */
final class TwoFactorService
{
    private const SETUP_KEY = '2fa.setup_secret';
    private const RECOVERY_CODE_COUNT = 10;

    /** @param list<string> $requiredRoles role values */
    public function __construct(
        private readonly TwoFactorRepository $repo,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly SecretBox $box,
        private readonly Session $session,
        private readonly Clock $clock,
        private readonly AuditLog $audit,
        private readonly string $hashKey,
        private readonly array $requiredRoles,
    ) {
    }

    public function isEnabled(int $userId): bool
    {
        return $this->repo->find($userId) !== null;
    }

    public function isRequiredFor(?Role $role): bool
    {
        return $role !== null && in_array($role->value, $this->requiredRoles, true);
    }

    public function unusedRecoveryCodes(int $userId): int
    {
        return $this->repo->unusedRecoveryCodes($userId);
    }

    /** The secret being set up, kept in the session (not the database) until confirmed. */
    public function pendingSecret(): string
    {
        $secret = $this->session->get(self::SETUP_KEY);
        if (!is_string($secret) || strlen($secret) !== 32) {
            $secret = Totp::generateSecret();
            $this->session->set(self::SETUP_KEY, $secret);
        }

        return $secret;
    }

    /** @return list<string> recovery codes, shown to the user exactly once */
    public function confirmSetup(CurrentUser $me, int $tenantId, string $code): array
    {
        if ($this->isEnabled($me->user->id)) {
            throw ValidationException::single('code', 'Two-step sign-in is already on.');
        }
        $secret = $this->pendingSecret();
        $step = Totp::verify($secret, $code, $this->clock->now()->getTimestamp(), null);
        if ($step === null) {
            throw ValidationException::single('code', 'That code did not match. Check the time on your phone is set automatically, then enter the newest code.');
        }

        $this->repo->enable($me->user->id, $this->box->encrypt($secret), $step);
        $this->session->remove(self::SETUP_KEY);
        $codes = $this->issueRecoveryCodes($me->user->id);
        $this->audit->record($tenantId, $me->user->id, 'account.two_step_enabled', 'user', $me->user->id);

        return $codes;
    }

    /** Checks a sign-in code: an authenticator code or an unused recovery code. */
    public function verify(int $userId, string $code): ?string
    {
        $stored = $this->repo->find($userId);
        if ($stored === null) {
            return null;
        }
        $digits = preg_replace('/\D/', '', $code) ?? '';
        if (strlen($digits) === Totp::DIGITS) {
            $secret = $this->box->decrypt($stored['secret']);
            if ($secret === null) {
                return null;
            }
            $step = Totp::verify($secret, $digits, $this->clock->now()->getTimestamp(), $stored['last_step']);

            return $step !== null && $this->repo->consumeStep($userId, $step) ? 'authenticator' : null;
        }

        return $this->repo->useRecoveryCode($userId, $this->hashCode($code)) ? 'recovery_code' : null;
    }

    public function disable(CurrentUser $me, int $tenantId, string $password, string $code): void
    {
        if ($this->isRequiredFor($me->membership?->role)) {
            throw ValidationException::single('code', 'Two-step sign-in is required for your role and cannot be turned off.');
        }
        $this->requirePassword($me, $password);
        if ($this->verify($me->user->id, $code) === null) {
            throw ValidationException::single('code', 'That code did not match.');
        }
        $this->repo->disable($me->user->id);
        $this->audit->record($tenantId, $me->user->id, 'account.two_step_disabled', 'user', $me->user->id);
    }

    /** @return list<string> */
    public function regenerateRecoveryCodes(CurrentUser $me, int $tenantId, string $password): array
    {
        if (!$this->isEnabled($me->user->id)) {
            throw HttpException::badRequest();
        }
        $this->requirePassword($me, $password);
        $codes = $this->issueRecoveryCodes($me->user->id);
        $this->audit->record($tenantId, $me->user->id, 'account.recovery_codes_renewed', 'user', $me->user->id);

        return $codes;
    }

    /** For a member who lost their phone and codes. Owners only; the member sets it up again at next sign-in. */
    public function resetFor(Tenant $tenant, CurrentUser $actor, int $userId, string $clientIp): void
    {
        if ($actor->membership?->role !== Role::Owner) {
            throw ValidationException::single('member', 'Only an owner can reset someone’s two-step sign-in.');
        }
        if ($userId === $actor->user->id) {
            throw ValidationException::single('member', 'Use your recovery codes, or ask another owner to reset it for you.');
        }
        $member = $this->memberships->find($tenant->id, $userId);
        if ($member === null || !$member->isActive()) {
            throw HttpException::notFound();
        }
        $this->repo->disable($userId);
        // End their sessions so the reset cannot be used by someone already signed in.
        $this->users->endAllSessions($userId);
        $this->audit->record($tenant->id, $actor->user->id, 'member.two_step_reset', 'user', $userId, [], $clientIp);
    }

    /** @return list<string> */
    private function issueRecoveryCodes(int $userId): array
    {
        $codes = [];
        $hashes = [];
        for ($i = 0; $i < self::RECOVERY_CODE_COUNT; $i++) {
            $raw = strtolower(Totp::base32Encode(random_bytes(5)));
            $code = substr($raw, 0, 4) . '-' . substr($raw, 4, 4);
            $codes[] = $code;
            $hashes[] = $this->hashCode($code);
        }
        $this->repo->replaceRecoveryCodes($userId, $hashes);

        return $codes;
    }

    private function hashCode(string $code): string
    {
        $normalised = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $code));

        return hash_hmac('sha256', 'recovery|' . $normalised, $this->hashKey);
    }

    private function requirePassword(CurrentUser $me, string $password): void
    {
        $hash = $this->users->passwordHashFor($me->user->id);
        if ($hash === null || !password_verify($password, $hash)) {
            throw ValidationException::single('password', 'Your password is not right.');
        }
    }
}
