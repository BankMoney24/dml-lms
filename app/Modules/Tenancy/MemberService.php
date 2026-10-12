<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\AuthService;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;

/**
 * Adding, re-roling and removing members of one institution.
 *
 * Rules: only owners grant or change the owner role; nobody changes their
 * own role or removes themselves; the last active owner cannot be demoted
 * or removed. Removal ends the person's sessions on their next request.
 */
final class MemberService
{
    public function __construct(
        private readonly Connection $db,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * @return array{user_id: int, name: string, email: string, one_time_password: ?string, existing_account: bool}
     */
    public function add(Tenant $tenant, CurrentUser $actor, string $name, string $email, string $roleValue, string $clientIp): array
    {
        $actor->authorise(Permission::MembersManage);
        $name = trim((string) preg_replace('/\s+/u', ' ', $name));
        $email = UserRepository::normaliseEmail($email);
        $role = Role::tryFrom($roleValue);

        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter their full name (2 to 120 characters).';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $errors['email'] = 'Enter an email address like name@school.ng.';
        }
        if ($role === null) {
            $errors['role'] = 'Choose a role.';
        } elseif (!$this->mayAssign($actor, $role)) {
            $errors['role'] = 'Only an owner can make someone an owner.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        /** @var Role $role */

        return $this->db->transaction(function () use ($tenant, $actor, $name, $email, $role, $clientIp): array {
            $user = $this->users->findByEmail($email);
            $password = null;
            if ($user === null) {
                $password = self::oneTimePassword();
                $user = $this->users->create($email, $name, AuthService::hashPassword($password), false, true);
            }

            $existing = $this->memberships->find($tenant->id, $user->id);
            if ($existing !== null && $existing->isActive()) {
                throw ValidationException::single('email', 'That person is already a member here.');
            }
            if ($existing !== null) {
                $this->memberships->reactivate($tenant->id, $user->id, $role);
            } else {
                $this->memberships->add($tenant->id, $user->id, $role);
            }

            $this->audit->record($tenant->id, $actor->user->id, 'member.added', 'user', $user->id, ['role' => $role->value], $clientIp);

            return [
                'user_id' => $user->id,
                'name' => $password === null ? $user->displayName : $name,
                'email' => $email,
                'one_time_password' => $password,
                'existing_account' => $password === null,
            ];
        });
    }

    public function changeRole(Tenant $tenant, CurrentUser $actor, int $userId, string $roleValue, string $clientIp): void
    {
        $actor->authorise(Permission::MembersManage);
        $target = $this->activeMember($tenant, $userId);
        $role = Role::tryFrom($roleValue) ?? throw ValidationException::single('role', 'Choose a role.');

        if ($userId === $actor->user->id) {
            throw ValidationException::single('role', 'You cannot change your own role. Ask another owner.');
        }
        if (($target->role === Role::Owner || $role === Role::Owner) && $actor->membership?->role !== Role::Owner) {
            throw ValidationException::single('role', 'Only an owner can give or take away the owner role.');
        }
        if ($target->role === $role) {
            return;
        }

        $this->db->transaction(function () use ($tenant, $actor, $target, $role, $clientIp): void {
            if ($target->role === Role::Owner && $this->memberships->lockAndCountActiveOwners($tenant->id) <= 1) {
                throw ValidationException::single('role', 'This is the only owner. Make someone else an owner first.');
            }
            $this->memberships->changeRole($tenant->id, $target->userId, $role);
            $this->audit->record($tenant->id, $actor->user->id, 'member.role_changed', 'user', $target->userId, [
                'from' => $target->role->value,
                'to' => $role->value,
            ], $clientIp);
        });
    }

    public function remove(Tenant $tenant, CurrentUser $actor, int $userId, string $clientIp): void
    {
        $actor->authorise(Permission::MembersManage);
        $target = $this->activeMember($tenant, $userId);

        if ($userId === $actor->user->id) {
            throw ValidationException::single('member', 'You cannot remove yourself.');
        }
        if ($target->role === Role::Owner && $actor->membership?->role !== Role::Owner) {
            throw ValidationException::single('member', 'Only an owner can remove another owner.');
        }

        $this->db->transaction(function () use ($tenant, $actor, $target, $clientIp): void {
            if ($target->role === Role::Owner && $this->memberships->lockAndCountActiveOwners($tenant->id) <= 1) {
                throw ValidationException::single('member', 'This is the only owner and cannot be removed.');
            }
            $this->memberships->remove($tenant->id, $target->userId);
            $this->audit->record($tenant->id, $actor->user->id, 'member.removed', 'user', $target->userId, [
                'role' => $target->role->value,
            ], $clientIp);
        });
    }

    /** @return array<string, string> role value => label, limited to what this person may grant */
    public function assignableRoles(CurrentUser $actor): array
    {
        $roles = [];
        foreach (Role::cases() as $role) {
            if ($this->mayAssign($actor, $role)) {
                $roles[$role->value] = $role->label();
            }
        }

        return $roles;
    }

    private function mayAssign(CurrentUser $actor, Role $role): bool
    {
        return $role !== Role::Owner || $actor->membership?->role === Role::Owner;
    }

    private function activeMember(Tenant $tenant, int $userId): Membership
    {
        $membership = $this->memberships->find($tenant->id, $userId);
        if ($membership === null || !$membership->isActive()) {
            throw HttpException::notFound();
        }

        return $membership;
    }

    /** 16 characters from an unambiguous alphabet, easy to read out or type from paper. */
    private static function oneTimePassword(): string
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $out = '';
        for ($i = 0; $i < 16; $i++) {
            $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return implode('-', str_split($out, 4));
    }
}
