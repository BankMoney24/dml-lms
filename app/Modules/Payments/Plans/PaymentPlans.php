<?php

declare(strict_types=1);

namespace App\Modules\Payments\Plans;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Mail\MailSettings;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\UserRepository;
use App\Modules\Notifications\EmailComposer;
use App\Modules\Notifications\MailOutbox;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Payment;
use App\Modules\Payments\PaymentService;
use App\Modules\Tenancy\Tenant;
use App\Modules\Tenancy\TenantRepository;

/**
 * The school's side of payment plans (ADR 0037): follow who is paying in parts,
 * record a part paid at the office, stop asking for the rest, and the daily
 * reminders (a few days before a part is due, on the day it is late, and when
 * the course pauses).
 */
final class PaymentPlans
{
    public const NOTIFY_REMINDER = 'payment.plan_reminder';
    public const NOTIFY_PAUSED = 'payment.plan_paused';

    private const RANK = ['soon' => 1, 'due' => 2, 'paused' => 3];

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentPlanRepository $plans,
        private readonly PaymentService $payments,
        private readonly CourseRepository $courses,
        private readonly UserRepository $users,
        private readonly TenantRepository $tenants,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly ?Notifier $notifier = null,
        private readonly ?MailOutbox $outbox = null,
        private readonly ?EmailComposer $composer = null,
        private readonly ?MailSettings $mail = null,
    ) {
    }

    public function now(): \DateTimeImmutable
    {
        return $this->clock->now();
    }

    /** @return list<PaymentPlan> */
    public function forSchool(Tenant $tenant): array
    {
        return $this->plans->forSchool($tenant->id);
    }

    /** @return list<PaymentPlan> */
    public function forLearner(Tenant $tenant, int $userId): array
    {
        return $this->plans->forUser($tenant->id, $userId);
    }

    public function find(Tenant $tenant, int $id): PaymentPlan
    {
        return $this->plans->find($tenant->id, $id) ?? throw HttpException::notFound();
    }

    /** The money for the next part reached the school directly: record it. */
    public function recordPart(Tenant $tenant, CurrentUser $admin, int $planId, string $note, string $clientIp): Payment
    {
        $admin->authorise(Permission::SchoolManage);
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));
        if (mb_strlen($note) < 3 || mb_strlen($note) > 200) {
            throw ValidationException::single('note', 'Say how it was paid, for example “Bank transfer, teller 004512”.');
        }

        return $this->db->transaction(function () use ($tenant, $admin, $planId, $note, $clientIp): Payment {
            $plan = $this->plans->find($tenant->id, $planId, true) ?? throw HttpException::notFound();
            $part = $plan->nextPart();
            if ($part === null) {
                throw ValidationException::single('note', 'Nothing is left to pay on this plan.');
            }
            $course = $this->courses->findById($tenant->id, $plan->courseId) ?? throw HttpException::notFound();
            $user = $this->users->findById($plan->userId) ?? throw HttpException::notFound();

            return $this->payments->storeManual($tenant, $admin, $user, $course, $plan->partAmount($part), $note, $clientIp, $plan, $part);
        });
    }

    /** The school stops asking for the rest. The course stays open; what was paid stays paid. */
    public function writeOff(Tenant $tenant, CurrentUser $admin, int $planId, string $note, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));
        if (mb_strlen($note) < 3 || mb_strlen($note) > 200) {
            throw ValidationException::single('reason', 'Say why, for example “Scholarship from term 2”. Only staff see this.');
        }
        $plan = $this->find($tenant, $planId);
        if ($plan->nextPart() === null) {
            return;
        }
        $this->plans->writeOff($tenant->id, $plan->id, $admin->user->id, $note);
        $this->audit->record($tenant->id, $admin->user->id, 'payment.plan_written_off', 'user', $plan->userId, [
            'plan' => $plan->id, 'course' => $plan->courseId, 'balance' => $plan->balance(),
        ], $clientIp);
    }

    /**
     * The daily job: each reminder once per part, in order. Late jobs skip ahead
     * (a plan found already paused gets the pause message, not three at once).
     *
     * @return array{soon: int, due: int, paused: int}
     */
    public function sendReminders(): array
    {
        $sent = ['soon' => 0, 'due' => 0, 'paused' => 0];
        $now = $this->clock->now();
        $tenants = [];
        foreach ($this->plans->activeEverywhere() as $plan) {
            $part = $plan->nextPart();
            $due = $part === null ? null : $plan->dueAt($part);
            if ($part === null || $part === 1 || $due === null) {
                continue;
            }
            $kind = match ($plan->standing($now)) {
                'paused' => 'paused',
                'late' => 'due',
                'due_soon' => 'soon',
                default => null,
            };
            if ($kind === null || !$this->notYet($plan->reminded, $part, $kind)) {
                continue;
            }
            $tenant = $tenants[$plan->tenantId] ??= $this->tenants->findById($plan->tenantId);
            if ($tenant === null || !$tenant->isActive()) {
                continue;
            }
            $this->remind($tenant, $plan, $part, $due, $kind);
            $this->plans->markReminded($plan->tenantId, $plan->id, $part . ':' . $kind);
            $sent[$kind]++;
        }

        return $sent;
    }

    private function notYet(?string $reminded, int $part, string $kind): bool
    {
        if ($reminded === null || preg_match('/^(\d+):(soon|due|paused)$/', $reminded, $m) !== 1) {
            return true;
        }

        return (int) $m[1] < $part || ((int) $m[1] === $part && self::RANK[$m[2]] < self::RANK[$kind]);
    }

    private function remind(Tenant $tenant, PaymentPlan $plan, int $part, \DateTimeImmutable $due, string $kind): void
    {
        $amount = $plan->partLabel($part);
        $date = $due->setTimezone(new \DateTimeZone($tenant->timezone !== '' ? $tenant->timezone : 'UTC'))->format('j M Y');
        $pauses = $plan->pausesAt()?->setTimezone(new \DateTimeZone($tenant->timezone !== '' ? $tenant->timezone : 'UTC'))->format('j M Y') ?? $date;
        $title = match ($kind) {
            'soon' => sprintf('Part %d of %d for %s is due on %s', $part, $plan->parts, $plan->courseTitle, $date),
            'due' => sprintf('Part %d of %d for %s is late', $part, $plan->parts, $plan->courseTitle),
            default => sprintf('%s is paused until part %d is paid', $plan->courseTitle, $part),
        };
        $body = match ($kind) {
            'soon' => sprintf('Pay %s by %s to keep learning without a break.', $amount, $date),
            'due' => sprintf('%s was due on %s. Pay it by %s or the course pauses.', $amount, $date, $pauses),
            default => sprintf('Pay %s and the course opens again at once. Your progress is kept.', $amount),
        };
        $link = '/courses/' . $plan->courseSlug . '/subscribe#plan';
        $this->notifier?->notify($tenant->id, $plan->userId, self::NOTIFY_REMINDER, $title, $body, $link);
        if ($kind === 'paused') {
            $admins = array_map('intval', array_column($this->db->fetchAll(
                "SELECT user_id FROM memberships WHERE tenant_id = ? AND status = 'active' AND role IN ('owner', 'admin') LIMIT 50",
                [$tenant->id],
            ), 'user_id'));
            $this->notifier?->notifyMany(
                $tenant->id,
                $admins,
                self::NOTIFY_PAUSED,
                sprintf('%s’s course is paused: part %d of %d unpaid', $plan->learnerName, $part, $plan->parts),
                sprintf('%s for %s was due on %s.', $amount, $plan->courseTitle, $date),
                '/school/payments/plans/' . $plan->id,
            );
        }
        $host = $this->tenants->primaryHost($tenant->id);
        if ($this->outbox === null || $this->composer === null || $this->mail === null || !$this->mail->deliveryAvailable() || $host === null || $plan->learnerEmail === '') {
            return;
        }
        $this->outbox->queue($tenant->id, $this->composer->compose('plan_reminder', $plan->learnerEmail, $plan->learnerName, $title, [
            'schoolName' => $tenant->name,
            'name' => $plan->learnerName,
            'kind' => $kind,
            'body' => $body,
            'courseTitle' => $plan->courseTitle,
            'part' => $part,
            'parts' => $plan->parts,
            'amount' => $amount,
            'due' => $date,
            'link' => 'https://' . $host . $link,
        ]));
    }
}
