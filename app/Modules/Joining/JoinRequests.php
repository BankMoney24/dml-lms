<?php

declare(strict_types=1);

namespace App\Modules\Joining;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Security\RateLimiter;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Guardians\GuardianLink;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\MemberService;
use App\Modules\Tenancy\Tenant;

/**
 * Requests the school decides on (ADR 0020):
 *  - a member asks to teach; approving makes them an educator;
 *  - a parent or guardian asks to follow their child; the school checks who
 *    they are and links them with the usual guardian flow (ADR 0016), then
 *    marks the request done. Nothing is linked automatically.
 * Administrators who manage members are told about each new request.
 */
final class JoinRequests
{
    public function __construct(
        private readonly Connection $db,
        private readonly JoinRequestRepository $requests,
        private readonly MemberService $members,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /**
     * @param array{subjects: string, experience: string, link: string} $input
     */
    public function applyToTeach(Tenant $tenant, CurrentUser $user, array $input, bool $emailMustBeConfirmed, string $clientIp): int
    {
        if ($user->can(Permission::CourseManage)) {
            throw ValidationException::single('form', 'You can already build courses here.');
        }
        if ($emailMustBeConfirmed && !$user->user->emailVerified) {
            throw ValidationException::single('form', 'Confirm your email address first: use the link we sent you, or send a new one from your account page.');
        }
        $previous = $this->requests->pendingFor($tenant->id, $user->user->id, JoinRequest::TEACH);
        if ($previous !== null && $previous->isPending()) {
            throw ValidationException::single('form', 'You have already asked. The school will let you know.');
        }
        $subjects = self::clean($input['subjects'], 200);
        $experience = trim(str_replace("\r\n", "\n", $input['experience']));
        $link = trim($input['link']);
        $errors = [];
        if (mb_strlen($subjects) < 2) {
            $errors['subjects'] = 'Say what you would like to teach, for example "Python for beginners" or "SS 2 Physics".';
        }
        if (mb_strlen($experience) < 20 || mb_strlen($experience) > 3000) {
            $errors['experience'] = 'Tell the school about your teaching or work experience in a few sentences (20 to 3,000 characters).';
        }
        if ($link !== '' && (preg_match('#^https://[^\s"<>]{3,250}$#', $link) !== 1)) {
            $errors['link'] = 'Paste a full link starting with https://, or leave this empty.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $message = 'Would like to teach: ' . $subjects . "\n\n" . $experience . ($link !== '' ? "\n\nLink: " . $link : '');
        $id = $this->requests->create($tenant->id, JoinRequest::TEACH, $user->user->id, $user->user->displayName, $user->user->email, '', '', $message);
        $this->audit->record($tenant->id, $user->user->id, 'join.teach_requested', 'join_request', $id, [], $clientIp);
        $this->notifyManagers($tenant, 'New request to teach: ' . $user->user->displayName);

        return $id;
    }

    /**
     * A parent or guardian, often without an account, asks to follow their child.
     *
     * @param array{name: string, email: string, phone: string, child: string, relationship: string, message: string, agree: bool} $input
     */
    public function requestGuardianAccess(Tenant $tenant, array $input, string $clientIp): int
    {
        if (!$tenant->parentRequests) {
            throw HttpException::notFound();
        }
        $name = self::clean($input['name'], 120);
        $email = UserRepository::normaliseEmail($input['email']);
        $phone = self::clean($input['phone'], 40);
        $child = self::clean($input['child'], 120);
        $relationship = $input['relationship'];
        $message = trim(str_replace("\r\n", "\n", $input['message']));
        $errors = [];
        if (mb_strlen($name) < 2) {
            $errors['name'] = 'Enter your full name.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $errors['email'] = 'Enter an email address the school can reach you on.';
        }
        if ($phone !== '' && preg_match('/^[0-9 +()-]{7,40}$/', $phone) !== 1) {
            $errors['phone'] = 'Enter a phone number with digits only, for example 0803 123 4567, or leave it empty.';
        }
        if (mb_strlen($child) < 2) {
            $errors['child'] = 'Enter your child\'s full name as the school knows it.';
        }
        if (!isset(GuardianLink::RELATIONSHIPS[$relationship])) {
            $errors['relationship'] = 'Choose how you are related to the learner.';
        }
        if (mb_strlen($message) > 2000) {
            $errors['message'] = 'Keep the message under 2,000 characters.';
        }
        if (!$input['agree']) {
            $errors['agree'] = 'Tick this so the school can contact you to check who you are.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (
            !$this->limiter->attempt('guardian-request|' . $tenant->id . '|' . $clientIp, 5, 3600)
            || !$this->limiter->attempt('guardian-request-email|' . $tenant->id . '|' . $email, 3, 86400)
        ) {
            throw ValidationException::single('form', 'We already have your request. The school will contact you.');
        }
        $text = 'Relationship: ' . GuardianLink::RELATIONSHIPS[$relationship] . ($message !== '' ? "\n\n" . $message : '');
        $id = $this->requests->create($tenant->id, JoinRequest::GUARDIAN, null, $name, $email, $phone, $child, $text);
        $this->audit->record($tenant->id, null, 'join.guardian_requested', 'join_request', $id, [], $clientIp);
        $this->notifyManagers($tenant, 'A parent asks to follow ' . $child);

        return $id;
    }

    public function approve(Tenant $tenant, CurrentUser $admin, int $requestId, string $clientIp): JoinRequest
    {
        $admin->authorise(Permission::MembersManage);

        return $this->db->transaction(function () use ($tenant, $admin, $requestId, $clientIp): JoinRequest {
            $request = $this->requests->find($tenant->id, $requestId, true);
            if ($request === null) {
                throw HttpException::notFound();
            }
            if (!$request->isPending()) {
                return $request;
            }
            if ($request->kind === JoinRequest::TEACH) {
                if ($request->userId === null) {
                    throw ValidationException::single('request', 'This person no longer has an account here.');
                }
                $this->members->changeRole($tenant, $admin, $request->userId, Role::Educator->value, $clientIp);
                $this->notifier?->notify($tenant->id, $request->userId, 'join.decision', 'You can now build courses at ' . $tenant->name, 'Open Your courses in the menu to start one.', '/teach');
            }
            $this->requests->decide($tenant->id, $requestId, JoinRequest::APPROVED, $admin->user->id);
            $this->audit->record($tenant->id, $admin->user->id, 'join.request_approved', 'join_request', $requestId, ['kind' => $request->kind], $clientIp);

            return $request;
        });
    }

    public function decline(Tenant $tenant, CurrentUser $admin, int $requestId, string $clientIp): void
    {
        $admin->authorise(Permission::MembersManage);
        $request = $this->requests->find($tenant->id, $requestId);
        if ($request === null) {
            throw HttpException::notFound();
        }
        if (!$request->isPending()) {
            return;
        }
        $this->requests->decide($tenant->id, $requestId, JoinRequest::DECLINED, $admin->user->id);
        $this->audit->record($tenant->id, $admin->user->id, 'join.request_declined', 'join_request', $requestId, ['kind' => $request->kind], $clientIp);
        if ($request->kind === JoinRequest::TEACH && $request->userId !== null) {
            $this->notifier?->notify($tenant->id, $request->userId, 'join.decision', 'Your request to teach was not approved', 'Ask the school office if you would like to know more.', '/help');
        }
    }

    private function notifyManagers(Tenant $tenant, string $title): void
    {
        $ids = array_map('intval', array_column($this->db->fetchAll(
            "SELECT user_id FROM memberships WHERE tenant_id = ? AND status = 'active' AND role IN ('owner', 'admin') LIMIT 50",
            [$tenant->id],
        ), 'user_id'));
        $this->notifier?->notifyMany($tenant->id, $ids, 'join.request', $title, null, '/school/joining');
    }

    private static function clean(string $value, int $max): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $value)), 0, $max);
    }
}
