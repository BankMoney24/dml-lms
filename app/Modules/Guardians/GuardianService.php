<?php

declare(strict_types=1);

namespace App\Modules\Guardians;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Tenancy\MemberService;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Linking parents and guardians to learners (PAR-001, PAR-002).
 *
 * Only people who manage members link a guardian, after checking who they
 * are; the link records who verified it. Each link says what the guardian
 * may see. Guardians can look, never change, and see only their own links.
 */
final class GuardianService
{
    public function __construct(
        private readonly Connection $db,
        private readonly GuardianRepository $links,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly MemberService $members,
        private readonly AuditLog $audit,
    ) {
    }

    /**
     * Links a guardian by email; someone new is added to the school as a guardian first.
     *
     * @param array{progress: bool, results: bool, notices: bool} $may
     * @return array{guardian_id: int, guardian_name: string, added: ?array{user_id: int, name: string, email: string, role: string, one_time_password: ?string, existing_account: bool}}
     */
    public function link(Tenant $tenant, CurrentUser $admin, int $learnerId, string $name, string $email, string $relationship, array $may, bool $revealPassword, string $clientIp): array
    {
        $admin->authorise(Permission::MembersManage);
        $learner = $this->learnerOf($tenant, $learnerId);
        if (!isset(GuardianLink::RELATIONSHIPS[$relationship])) {
            throw ValidationException::single('relationship', 'Choose how they are related to the learner.');
        }
        $existing = $this->users->findByEmail($email);
        $membership = $existing === null ? null : $this->memberships->find($tenant->id, $existing->id);

        return $this->db->transaction(function () use ($tenant, $admin, $learner, $name, $email, $relationship, $may, $revealPassword, $clientIp, $existing, $membership): array {
            $added = null;
            if ($existing !== null && $membership !== null && $membership->isActive()) {
                $guardianId = $existing->id;
                $guardianName = $existing->displayName;
            } else {
                $added = $this->members->add($tenant, $admin, $name, $email, Role::Guardian->value, $clientIp, $revealPassword);
                $guardianId = $added['user_id'];
                $guardianName = $added['name'];
            }
            if ($guardianId === $learner['id']) {
                throw ValidationException::single('email', 'A learner cannot be their own guardian.');
            }
            $this->links->link($tenant->id, $guardianId, $learner['id'], $relationship, $may['progress'], $may['results'], $may['notices'], $admin->user->id);
            $this->audit->record($tenant->id, $admin->user->id, 'guardian.linked', 'user', $learner['id'], [
                'guardian' => $guardianId, 'relationship' => $relationship,
                'progress' => $may['progress'], 'results' => $may['results'], 'notices' => $may['notices'],
            ], $clientIp);

            return ['guardian_id' => $guardianId, 'guardian_name' => $guardianName, 'added' => $added];
        });
    }

    /** @param array{progress: bool, results: bool, notices: bool} $may */
    public function setPermissions(Tenant $tenant, CurrentUser $admin, int $learnerId, int $linkId, array $may, string $clientIp): void
    {
        $admin->authorise(Permission::MembersManage);
        $link = $this->linkOf($tenant, $learnerId, $linkId);
        $this->links->setPermissions($tenant->id, $link->id, $may['progress'], $may['results'], $may['notices']);
        $this->audit->record($tenant->id, $admin->user->id, 'guardian.permissions', 'user', $learnerId, ['guardian' => $link->guardianId] + $may, $clientIp);
    }

    public function revoke(Tenant $tenant, CurrentUser $admin, int $learnerId, int $linkId, string $clientIp): void
    {
        $admin->authorise(Permission::MembersManage);
        $link = $this->linkOf($tenant, $learnerId, $linkId);
        $this->links->revoke($tenant->id, $link->id, $admin->user->id);
        $this->audit->record($tenant->id, $admin->user->id, 'guardian.unlinked', 'user', $learnerId, ['guardian' => $link->guardianId], $clientIp);
    }

    /** The link that lets this person see this learner, or "not found" (nothing is revealed about others). */
    public function viewLink(Tenant $tenant, CurrentUser $viewer, int $learnerId): GuardianLink
    {
        return $this->links->active($tenant->id, $viewer->user->id, $learnerId) ?? throw HttpException::notFound();
    }

    /** @return array{id: int, name: string, email: string} */
    public function learnerOf(Tenant $tenant, int $learnerId): array
    {
        $user = $this->users->findById($learnerId);
        $membership = $user === null ? null : $this->memberships->find($tenant->id, $learnerId);
        if ($user === null || $membership === null || !$membership->isActive() || $membership->role !== Role::Learner) {
            throw HttpException::notFound();
        }

        return ['id' => $user->id, 'name' => $user->displayName, 'email' => $user->email];
    }

    private function linkOf(Tenant $tenant, int $learnerId, int $linkId): GuardianLink
    {
        $link = $this->links->find($tenant->id, $linkId);
        if ($link === null || $link->learnerId !== $learnerId) {
            throw HttpException::notFound();
        }

        return $link;
    }
}
