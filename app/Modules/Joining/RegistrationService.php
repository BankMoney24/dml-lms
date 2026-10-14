<?php

declare(strict_types=1);

namespace App\Modules\Joining;

use App\Core\Database\Connection;
use App\Core\Mail\MailSettings;
use App\Core\Security\RateLimiter;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\Role;
use App\Modules\Identity\User;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Signing yourself up as a learner (ADR 0020). Only when the school allows it
 * (open, or with its join code). Everyone joins as a learner; teaching and
 * following a child are requests the school decides on. Someone who already
 * has a DML account joins with that account's password. Limits per network
 * address stop the form being used to mass-create accounts.
 */
final class RegistrationService
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly ProfileRepository $profiles,
        private readonly AuthService $auth,
        private readonly AccountLinkService $links,
        private readonly MailSettings $mail,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @param array{name: string, email: string, password: string, code: string, terms: bool, age: bool} $input
     * @return array{user: User, existing_account: bool, confirmation_sent: bool, second_factor: bool}
     * @throws ValidationException
     */
    public function register(Tenant $tenant, array $input, string $clientIp, string $baseUrl): array
    {
        if (!$tenant->signUpOpen()) {
            throw ValidationException::single('form', 'This school adds learners itself. Ask the school office for an account.');
        }
        $name = trim((string) preg_replace('/\s+/u', ' ', $input['name']));
        $email = UserRepository::normaliseEmail($input['email']);
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter your full name, as you would like it on certificates.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $errors['email'] = 'Enter an email address like name@example.com.';
        }
        if ($tenant->registration === 'code' && ($tenant->joinCode === null || $tenant->joinCode === '' || !hash_equals(self::normaliseCode($tenant->joinCode), self::normaliseCode($input['code'])))) {
            $errors['code'] = 'That join code is not right. Check it with your school or teacher.';
        }
        if (!$input['terms']) {
            $errors['terms'] = 'Tick this to agree to the school\'s rules and to it keeping your learning records.';
        }
        if (!$input['age']) {
            $errors['age'] = 'Tick this to confirm you are 18 or older, or that a parent or guardian agrees.';
        }
        $existing = $errors === [] ? $this->users->findByEmail($email) : null;
        if ($existing === null && !isset($errors['email'])) {
            $problems = AuthService::passwordProblems($input['password']);
            if ($problems !== []) {
                $errors['password'] = 'Choose a password you do not use elsewhere. ' . implode(' ', $problems);
            }
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!$this->limiter->attempt('join|' . $tenant->id . '|' . $clientIp, 10, 3600)) {
            throw ValidationException::single('form', 'Too many accounts were started from this connection. Try again in an hour.');
        }

        if ($existing !== null) {
            return $this->joinWithExistingAccount($tenant, $existing, $input['password'], $clientIp);
        }

        $user = $this->db->transaction(function () use ($tenant, $name, $email, $input, $clientIp): User {
            $user = $this->users->register($email, $name, AuthService::hashPassword($input['password']), false);
            $this->memberships->add($tenant->id, $user->id, Role::Learner);
            $this->profiles->createForSignUp($tenant->id, $user->id);
            $this->audit->record($tenant->id, $user->id, 'member.self_registered', 'user', $user->id, ['mode' => $tenant->registration], $clientIp);

            return $user;
        });
        $sent = false;
        if ($this->mail->deliveryAvailable()) {
            $this->links->sendEmailConfirmation($tenant, $user, $baseUrl);
            $sent = true;
        }
        $this->auth->signInAfterJoining($tenant, $user, $clientIp);

        return ['user' => $user, 'existing_account' => false, 'confirmation_sent' => $sent, 'second_factor' => false];
    }

    /** @return array{user: User, existing_account: bool, confirmation_sent: bool, second_factor: bool} */
    private function joinWithExistingAccount(Tenant $tenant, User $user, string $password, string $clientIp): array
    {
        $check = $this->auth->checkPassword($tenant, $user->email, $password, $clientIp);
        if (!$check['ok']) {
            throw ValidationException::single('password', $check['message']
                ?? 'This email already has a DML account. Enter that account\'s password to join, or use "Forgotten your password?" on the sign-in page.');
        }
        $membership = $this->memberships->find($tenant->id, $user->id);
        if ($membership !== null && $membership->isActive()) {
            throw ValidationException::single('email', 'You are already a member here. Sign in instead.');
        }
        if ($membership !== null) {
            // Removed by the school: only the school can bring them back.
            throw ValidationException::single('email', 'Your place here was closed by the school. Ask the school office to add you again.');
        }
        $this->db->transaction(function () use ($tenant, $user, $clientIp): void {
            $this->memberships->add($tenant->id, $user->id, Role::Learner);
            $this->profiles->createForSignUp($tenant->id, $user->id);
            $this->audit->record($tenant->id, $user->id, 'member.self_registered', 'user', $user->id, ['mode' => $tenant->registration, 'existing_account' => 1], $clientIp);
        });
        $twoStep = $user->twoFactorEnabled;
        if (!$twoStep) {
            $this->auth->signInAfterJoining($tenant, $user, $clientIp);
        }

        return ['user' => $user, 'existing_account' => true, 'confirmation_sent' => false, 'second_factor' => $twoStep];
    }

    public static function normaliseCode(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    /** A code that is easy to read out and type: no 0/O or 1/I. */
    public static function newCode(): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $code;
    }
}
