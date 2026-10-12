<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Database\Connection;
use App\Core\Security\RateLimiter;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Notifications\EmailComposer;
use App\Modules\Notifications\MailOutbox;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Emailed account links: password reset (IAM-001), invitations for new
 * members, and "you have been added" notices for existing accounts.
 */
final class AccountLinkService
{
    public const RESET_MINUTES = 60;
    public const INVITE_DAYS = 7;

    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly AccountTokenRepository $tokens,
        private readonly EmailComposer $composer,
        private readonly MailOutbox $outbox,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Sends a reset link if — and only if — the address belongs to an active
     * member here. The caller shows the same message either way, so the
     * page never reveals whether an account exists.
     */
    public function requestReset(Tenant $tenant, string $email, string $clientIp, string $baseUrl): void
    {
        $email = UserRepository::normaliseEmail($email);
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }
        // Limits stop the form being used to flood someone's inbox.
        if (
            !$this->limiter->attempt('reset-ip|' . $clientIp, 20, 3600)
            || !$this->limiter->attempt('reset-email|' . $tenant->id . '|' . $email, 3, 3600)
        ) {
            return;
        }
        $user = $this->users->findByEmail($email);
        $membership = $user === null ? null : $this->memberships->find($tenant->id, $user->id);
        if ($user === null || !$user->isActive() || $membership === null || !$membership->isActive()) {
            return;
        }

        $this->sendReset($tenant, $user, $baseUrl, null);
        $this->audit->record($tenant->id, $user->id, 'auth.reset_requested', 'user', $user->id, [], $clientIp);
    }

    /** Invitation for a newly created member: a link to choose their password. */
    public function sendInvitation(Tenant $tenant, User $user, CurrentUser $inviter, Role $role, string $baseUrl): void
    {
        $token = $this->tokens->issue($tenant->id, $user->id, AccountTokenRepository::INVITE, self::INVITE_DAYS * 86400, $inviter->user->id);
        $this->outbox->queue($tenant->id, $this->composer->compose('invitation', $user->email, $user->displayName, 'You are invited to ' . $tenant->name, [
            'schoolName' => $tenant->name,
            'name' => $user->displayName,
            'inviter' => $inviter->user->displayName,
            'role' => strtolower($role->label()),
            'email' => $user->email,
            'days' => self::INVITE_DAYS,
            'link' => $baseUrl . '/password/reset/' . $token,
        ]));
    }

    /** Someone who already has a DML account was added here; they keep their password. */
    public function sendAddedNotice(Tenant $tenant, User $user, CurrentUser $inviter, Role $role, string $baseUrl): void
    {
        $this->outbox->queue($tenant->id, $this->composer->compose('added_to_school', $user->email, $user->displayName, 'You now have access to ' . $tenant->name, [
            'schoolName' => $tenant->name,
            'name' => $user->displayName,
            'inviter' => $inviter->user->displayName,
            'role' => strtolower($role->label()),
            'link' => $baseUrl . '/login',
        ]));
    }

    /** Admin action: re-send an invitation (never signed in) or a reset link (everyone else). */
    public function sendLinkFor(Tenant $tenant, CurrentUser $actor, int $userId, string $baseUrl, string $clientIp): User
    {
        $membership = $this->memberships->find($tenant->id, $userId);
        $user = $this->users->findById($userId);
        if ($membership === null || !$membership->isActive() || $user === null) {
            throw ValidationException::single('member', 'That person is not a member here.');
        }
        if ($user->mustChangePassword) {
            $this->sendInvitation($tenant, $user, $actor, $membership->role, $baseUrl);
        } else {
            $this->sendReset($tenant, $user, $baseUrl, $actor->user->id);
        }
        $this->audit->record($tenant->id, $actor->user->id, 'member.link_sent', 'user', $user->id, [], $clientIp);

        return $user;
    }

    /** @return array{user: User, purpose: string}|null */
    public function inspect(Tenant $tenant, string $rawToken): ?array
    {
        $token = $this->tokens->findValid($tenant->id, $rawToken);
        $user = $token === null ? null : $this->users->findById($token['user_id']);
        $membership = $user === null ? null : $this->memberships->find($tenant->id, $user->id);
        if ($token === null || $user === null || !$user->isActive() || $membership === null || !$membership->isActive()) {
            return null;
        }

        return ['user' => $user, 'purpose' => $token['purpose']];
    }

    /** Sets the new password and spends the link. Every session and other link for the user ends. */
    public function complete(Tenant $tenant, string $rawToken, string $password, string $confirm, string $clientIp): bool
    {
        $problems = AuthService::passwordProblems($password);
        if ($problems !== []) {
            throw ValidationException::single('new_password', implode(' ', $problems));
        }
        if ($password !== $confirm) {
            throw ValidationException::single('confirm_password', 'The two passwords do not match.');
        }
        if ($this->inspect($tenant, $rawToken) === null) {
            return false;
        }

        return $this->db->transaction(function () use ($tenant, $rawToken, $password, $clientIp): bool {
            $token = $this->tokens->findValid($tenant->id, $rawToken);
            if ($token === null || !$this->tokens->consume($token['id'])) {
                return false;
            }
            $this->users->updatePassword($token['user_id'], AuthService::hashPassword($password));
            $this->tokens->revokeAllForUser($token['user_id']);
            $action = $token['purpose'] === AccountTokenRepository::INVITE ? 'auth.invitation_accepted' : 'auth.password_reset';
            $this->audit->record($tenant->id, $token['user_id'], $action, 'user', $token['user_id'], [], $clientIp);

            return true;
        });
    }

    private function sendReset(Tenant $tenant, User $user, string $baseUrl, ?int $createdBy): void
    {
        $token = $this->tokens->issue($tenant->id, $user->id, AccountTokenRepository::RESET, self::RESET_MINUTES * 60, $createdBy);
        $this->outbox->queue($tenant->id, $this->composer->compose('password_reset', $user->email, $user->displayName, 'Reset your password for ' . $tenant->name, [
            'schoolName' => $tenant->name,
            'name' => $user->displayName,
            'minutes' => self::RESET_MINUTES,
            'link' => $baseUrl . '/password/reset/' . $token,
        ]));
    }
}
