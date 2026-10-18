<?php

declare(strict_types=1);

namespace App\Modules\Applications;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Mail\MailSettings;
use App\Core\Security\RateLimiter;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\AccountLinkService;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\Role;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\MemberService;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Applying for a place and waiting to be added (ADR 0025).
 *  - Anyone can apply to a course that takes applications or is full: members
 *    with their account, visitors with name, email and phone.
 *  - Course builders offer places in the order people applied (or decline).
 *    A free course joins the person at once; a paid one waits for payment.
 *  - A visitor with no account here gets one (learner) when offered a place,
 *    by an emailed invitation or a one-time password for staff to pass on;
 *    creating accounts needs permission to manage members.
 */
final class Admissions
{
    public function __construct(
        private readonly Connection $db,
        private readonly ApplicationRepository $applications,
        private readonly CourseRepository $courses,
        private readonly LearningService $learning,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly MemberService $members,
        private readonly AccountLinkService $links,
        private readonly MailSettings $mail,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /**
     * @param array{name: string, email: string, phone: string, message: string, agree: bool} $input name/email ignored for members
     */
    public function apply(Tenant $tenant, Course $course, ?CurrentUser $member, array $input, string $clientIp): Application
    {
        if (!$course->isPublished()) {
            throw HttpException::notFound();
        }
        if ($member !== null) {
            if (!$this->learning->mustApply($course, $member)) {
                throw ValidationException::single('form', 'You can join this course directly; there is no need to apply.');
            }
            $name = $member->user->displayName;
            $email = $member->user->email;
        } else {
            if (!$this->learning->isGated($course)) {
                throw ValidationException::single('form', 'This course does not take applications. Create an account to join it.');
            }
            $name = trim((string) preg_replace('/\s+/u', ' ', $input['name']));
            $email = UserRepository::normaliseEmail($input['email']);
        }
        $phone = trim((string) preg_replace('/\s+/', ' ', $input['phone']));
        $message = trim(str_replace("\r\n", "\n", $input['message']));
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 120) {
            $errors['name'] = 'Enter your full name.';
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false || mb_strlen($email) > 254) {
            $errors['email'] = 'Enter an email address the school can reach you on.';
        }
        if ($phone !== '' && preg_match('/^[0-9 +()-]{7,40}$/', $phone) !== 1) {
            $errors['phone'] = 'Enter a phone number with digits only, for example 0803 123 4567, or leave it empty.';
        }
        if (mb_strlen($message) > 2000) {
            $errors['message'] = 'Keep your note under 2,000 characters.';
        }
        if ($member === null && !$input['agree']) {
            $errors['agree'] = 'Tick this so the school can contact you about your place.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        $existing = $this->applications->latestFor($tenant->id, $course->id, $member?->user->id, $email);
        if ($existing !== null && $existing->isActive()) {
            throw ValidationException::single('form', 'You have already applied for this course. The school will be in touch.');
        }
        if (
            !$this->limiter->attempt('apply-ip|' . $tenant->id . '|' . $clientIp, 10, 3600)
            || !$this->limiter->attempt('apply-email|' . $tenant->id . '|' . $email, 5, 86400)
        ) {
            throw ValidationException::single('form', 'Too many applications from here. Try again later.');
        }
        $id = $this->applications->create($tenant->id, $course->id, $member?->user->id, $name, $email, $phone, $message);
        $this->audit->record($tenant->id, $member?->user->id, 'application.created', 'course', $course->id, ['application' => $id], $clientIp);

        $recipients = array_map('intval', array_column($this->db->fetchAll(
            "SELECT user_id FROM memberships WHERE tenant_id = ? AND status = 'active' AND role IN ('owner', 'admin') LIMIT 50",
            [$tenant->id],
        ), 'user_id'));
        $author = $this->courses->authorId($tenant->id, $course->id);
        if ($author !== null) {
            $recipients[] = $author;
        }
        $this->notifier?->notifyMany($tenant->id, $recipients, 'application.new', $name . ' applied for ' . $course->title, null, '/teach/applications');

        return $this->applications->find($tenant->id, $id) ?? throw new \LogicException('Application not saved');
    }

    /** The applicant changes their mind. */
    public function withdraw(Tenant $tenant, CurrentUser $member, int $id): void
    {
        $application = $this->applications->find($tenant->id, $id);
        if ($application === null || $application->userId !== $member->user->id) {
            throw HttpException::notFound();
        }
        if ($application->isActive()) {
            $this->applications->decide($tenant->id, $id, Application::WITHDRAWN, null);
        }
    }

    /**
     * Offers the applicant a place. Returns a one-time password when an account
     * was made and the school cannot send email (staff pass it on), else null.
     *
     * @return array{status: string, one_time_password: ?string, account_created: bool}
     */
    public function offer(Tenant $tenant, CurrentUser $staff, int $id, string $clientIp, string $baseUrl): array
    {
        $staff->authorise(Permission::CourseManage);

        return $this->db->transaction(function () use ($tenant, $staff, $id, $clientIp, $baseUrl): array {
            $application = $this->applications->find($tenant->id, $id, true) ?? throw HttpException::notFound();
            if ($application->status !== Application::WAITING) {
                return ['status' => $application->status, 'one_time_password' => null, 'account_created' => false];
            }
            $course = $this->courses->findById($tenant->id, $application->courseId) ?? throw HttpException::notFound();

            // Find or make the learner's account here.
            $password = null;
            $created = false;
            $user = $application->userId !== null ? $this->users->findById($application->userId) : $this->users->findByEmail($application->email);
            $membership = $user === null ? null : $this->memberships->find($tenant->id, $user->id);
            if ($user === null || $membership === null || !$membership->isActive()) {
                if (!$staff->can(Permission::MembersManage)) {
                    throw ValidationException::single('application', $application->name . ' has no account here yet. Ask an administrator to offer this place; they can create the account.');
                }
                $emailing = $this->mail->deliveryAvailable();
                $added = $this->members->add($tenant, $staff, $application->name, $application->email, Role::Learner->value, $clientIp, !$emailing);
                $user = $this->users->findById($added['user_id']) ?? throw new \LogicException('Member not saved');
                $membership = $this->memberships->find($tenant->id, $user->id);
                $created = !$added['existing_account'];
                $password = $added['one_time_password'];
                if ($emailing && $created) {
                    $this->links->sendInvitation($tenant, $user, $staff, Role::Learner, $baseUrl);
                }
            }
            $learner = new CurrentUser($user, $membership);

            // A place is offered; a free course starts at once.
            $this->applications->decide($tenant->id, $id, Application::OFFERED, $staff->user->id, $user->id);
            $status = Application::OFFERED;
            if (!$this->learning->needsPayment($course, $learner) && $learner->can(Permission::CourseLearn)) {
                $this->learning->enrol($course, $learner);
                $status = Application::ENROLLED;
            }
            $this->audit->record($tenant->id, $staff->user->id, 'application.offered', 'course', $course->id, ['application' => $id, 'status' => $status], $clientIp);
            $this->notifier?->notify(
                $tenant->id,
                $user->id,
                'application.decision',
                $status === Application::ENROLLED ? 'You have a place on ' . $course->title : 'A place is ready for you on ' . $course->title,
                $status === Application::ENROLLED ? 'Open the course to start.' : 'Pay on the course page to start.',
                '/courses/' . $course->slug,
            );

            return ['status' => $status, 'one_time_password' => $password, 'account_created' => $created];
        });
    }

    public function decline(Tenant $tenant, CurrentUser $staff, int $id, string $clientIp): void
    {
        $staff->authorise(Permission::CourseManage);
        $application = $this->applications->find($tenant->id, $id) ?? throw HttpException::notFound();
        if (!$application->isActive()) {
            return;
        }
        $this->applications->decide($tenant->id, $id, Application::DECLINED, $staff->user->id);
        $this->audit->record($tenant->id, $staff->user->id, 'application.declined', 'course', $application->courseId, ['application' => $id], $clientIp);
        if ($application->userId !== null) {
            $this->notifier?->notify(
                $tenant->id,
                $application->userId,
                'application.decision',
                'No place on ' . $application->courseTitle . ' this time',
                'Ask the school office about the next intake.',
                '/courses/' . $application->courseSlug,
            );
        }
    }
}
