<?php

declare(strict_types=1);

namespace App\Modules\Identity;

use App\Core\Session\Session;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;

/** Self-service account changes for the signed-in user. */
final class AccountService
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly Session $session,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Changes the password, signs out every other session and keeps this one.
     * The current password is required even right after sign-in, so an
     * unattended session cannot be used to take over the account.
     */
    public function changePassword(CurrentUser $me, int $tenantId, string $current, string $new, string $confirm, string $clientIp): void
    {
        $errors = [];
        $hash = $this->users->passwordHashFor($me->user->id);
        if ($hash === null || !password_verify($current, $hash)) {
            $errors['current_password'] = 'Your current password is not right. Check it and try again.';
        }
        $problems = AuthService::passwordProblems($new);
        if ($problems !== []) {
            $errors['new_password'] = implode(' ', $problems);
        } elseif ($hash !== null && password_verify($new, $hash)) {
            $errors['new_password'] = 'Choose a password you have not used for this account just now.';
        }
        if (!isset($errors['new_password']) && $new !== $confirm) {
            $errors['confirm_password'] = 'The two new passwords do not match.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->users->updatePassword($me->user->id, AuthService::hashPassword($new));
        $fresh = $this->users->findById($me->user->id);
        $this->session->regenerate();
        $this->session->set(AuthService::SESSION_VERSION, $fresh?->sessionVersion);
        $this->audit->record($tenantId, $me->user->id, 'account.password_changed', 'user', $me->user->id, [], $clientIp);
    }

    public function updateDisplayName(CurrentUser $me, int $tenantId, string $name): void
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            throw ValidationException::single('display_name', 'Enter a name between 2 and 120 characters.');
        }
        $this->users->updateDisplayName($me->user->id, $name);
        $this->audit->record($tenantId, $me->user->id, 'account.name_changed', 'user', $me->user->id);
    }
}
