<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Security\RateLimiter;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Notifications\Notifier;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Paying the school directly (ADR 0026), for schools without online payments
 * or learners who prefer a transfer:
 *  - the school writes how to pay it (bank, account name and number);
 *  - the learner pays, then tells the school: how, how much, when, reference;
 *  - the school checks its bank and confirms, which records the payment and
 *    enrols the learner, or rejects it with a reason the learner sees.
 * The learner's note never opens a course by itself.
 */
final class TransferPayments
{
    public const NOTIFY_CLAIM = 'payment.claim';
    public const NOTIFY_DECISION = 'payment.claim_decision';
    public const MAX_AGE_DAYS = 90;

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentClaimRepository $claims,
        private readonly PaymentService $payments,
        private readonly CourseRepository $courses,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly LearningService $learning,
        private readonly RateLimiter $limiter,
        private readonly AuditLog $audit,
        private readonly Clock $clock,
        private readonly ?Notifier $notifier = null,
        private readonly ?Plans\PaymentPlanRepository $plans = null,
    ) {
    }

    public function instructions(Tenant $tenant): string
    {
        return $this->claims->instructions($tenant->id);
    }

    public function saveInstructions(Tenant $tenant, CurrentUser $admin, string $text, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $text = trim(str_replace("\r\n", "\n", $text));
        if (mb_strlen($text) > 2000) {
            throw ValidationException::single('instructions', 'Keep the payment details under 2,000 characters.');
        }
        if (preg_match('/\b(sk|pk)_(live|test)_[A-Za-z0-9]{6,}/', $text) === 1) {
            // Learners read this text. A Paystack key pasted here by mistake would be public.
            throw ValidationException::single(
                'instructions',
                'That looks like a Paystack key. Never put keys here: learners can read this text. Write your bank name, account name and account number instead.',
            );
        }
        $this->claims->saveInstructions($tenant->id, $text, $admin->user->id);
        $this->audit->record($tenant->id, $admin->user->id, 'payments.instructions_saved', null, null, ['length' => mb_strlen($text)], $clientIp);
    }

    public function latestFor(Tenant $tenant, CurrentUser $learner, Course $course): ?PaymentClaim
    {
        return $this->claims->latestFor($tenant->id, $learner->user->id, $course->id);
    }

    /** @return list<PaymentClaim> */
    public function forLearner(Tenant $tenant, CurrentUser $learner): array
    {
        return $this->claims->forUser($tenant->id, $learner->user->id);
    }

    /** @return list<PaymentClaim> */
    public function pending(Tenant $tenant): array
    {
        return $this->claims->pending($tenant->id);
    }

    /**
     * The learner says they paid.
     *
     * With "for" = "part", it pays the first part of a new payment plan; a learner
     * already paying in parts always pays their next part (ADR 0037).
     *
     * @param array{method: string, amount: string, paid_on: string, reference: string, note: string, for?: string} $input
     */
    public function claim(Tenant $tenant, Course $course, CurrentUser $learner, array $input, string $clientIp): PaymentClaim
    {
        $learner->authorise(Permission::CourseLearn);
        if (!$course->isPublished() || !$course->isPaid() || $course->tenantId !== $tenant->id) {
            throw HttpException::notFound();
        }
        $plan = $this->payments->planFor($tenant, $learner->user->id, $course);
        $part = $plan !== null && $plan->status === Plans\PaymentPlan::ACTIVE ? $plan->nextPart() : null;
        if ($part === null) {
            $plan = null;
            if ($this->learning->mustApply($course, $learner)) {
                throw ValidationException::single('form', 'This course takes applications. Apply for a place first; pay once the school offers you one.');
            }
            if (!$this->learning->needsPayment($course, $learner)) {
                throw ValidationException::single('form', 'There is nothing to pay: you can start this course now.');
            }
            if (($input['for'] ?? 'full') === 'part' && $course->offersInstalments()) {
                $part = 1;
            }
        }
        $expected = match (true) {
            $plan !== null && $part !== null => $plan->partAmount($part),
            $part !== null => Plans\PaymentPlan::split((int) $course->priceMinor, (int) $course->instalments, 1),
            default => (int) $course->priceMinor,
        };
        $existing = $this->claims->latestFor($tenant->id, $learner->user->id, $course->id);
        if ($existing !== null && $existing->isPending()) {
            throw ValidationException::single('form', 'The school is already checking a payment you told them about for this course.');
        }

        $errors = [];
        $method = $input['method'];
        if (!isset(PaymentClaim::METHODS[$method])) {
            $errors['method'] = 'Choose how you paid.';
        }
        $amount = Money::parse($input['amount']);
        if ($amount === null || $amount < 1 || $amount > Money::MAX_MINOR) {
            $errors['amount'] = 'Enter the amount you paid, for example ' . number_format($expected / 100, 0, '.', '') . '.';
        }
        $today = $this->clock->now()->format('Y-m-d');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $input['paid_on']);
        $oldest = $this->clock->now()->modify('-' . self::MAX_AGE_DAYS . ' days')->format('Y-m-d');
        if ($date === false || $date->format('Y-m-d') !== $input['paid_on']) {
            $errors['paid_on'] = 'Enter the date you paid.';
        } elseif ($input['paid_on'] > $today) {
            $errors['paid_on'] = 'The date cannot be in the future.';
        } elseif ($input['paid_on'] < $oldest) {
            $errors['paid_on'] = 'That is more than 90 days ago. Speak to the school office about older payments.';
        }
        $reference = trim((string) preg_replace('/\s+/u', ' ', $input['reference']));
        if (mb_strlen($reference) > 80) {
            $errors['reference'] = 'Keep the reference under 80 characters.';
        }
        if (in_array($method, ['transfer', 'deposit', 'pos'], true) && mb_strlen($reference) < 3) {
            $errors['reference'] = 'Enter the reference, session ID or teller number from your receipt so the school can find it.';
        }
        $note = trim(str_replace("\r\n", "\n", $input['note']));
        if (mb_strlen($note) > 500) {
            $errors['note'] = 'Keep the note under 500 characters.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if (!$this->limiter->attempt('pay-claim|' . $tenant->id . '|' . $learner->user->id, 5, 86400)) {
            throw ValidationException::single('form', 'You have sent several payment notes today. Wait for the school to answer, or speak to the office.');
        }

        if ($plan === null && $part !== null) {
            // The first part of a new plan: the plan is made only once the note is valid.
            $plan = $this->payments->openPlan($tenant, $course, $learner);
        }
        $id = $this->claims->create(
            $tenant->id,
            $learner->user->id,
            $course->id,
            (int) $amount,
            $plan !== null ? $plan->currency : (string) $course->currency,
            $method,
            $input['paid_on'],
            $reference,
            $note,
            $plan?->id,
            $plan === null ? null : $part,
        );
        $meta = ['claim' => $id, 'amount' => (int) $amount, 'part' => $plan === null ? null : $part];
        $this->audit->record($tenant->id, $learner->user->id, 'payment.claimed', 'course', $course->id, $meta, $clientIp);
        $admins = array_map('intval', array_column($this->db->fetchAll(
            "SELECT user_id FROM memberships WHERE tenant_id = ? AND status = 'active' AND role IN ('owner', 'admin') LIMIT 50",
            [$tenant->id],
        ), 'user_id'));
        $this->notifier?->notifyMany(
            $tenant->id,
            $admins,
            self::NOTIFY_CLAIM,
            $learner->user->displayName . ' says they paid ' . Money::format((int) $amount, (string) $course->currency) . ' for ' . $course->title
                . ($plan !== null ? sprintf(' (part %d of %d)', $part, $plan->parts) : ''),
            'Check your bank, then confirm it to enrol them.',
            '/school/payments#claims',
        );

        return $this->claims->find($tenant->id, $id) ?? throw new \LogicException('Claim not saved');
    }

    public function withdraw(Tenant $tenant, CurrentUser $learner, int $id): void
    {
        $claim = $this->claims->find($tenant->id, $id);
        if ($claim === null || $claim->userId !== $learner->user->id) {
            throw HttpException::notFound();
        }
        if ($claim->isPending()) {
            $this->claims->decide($tenant->id, $id, PaymentClaim::WITHDRAWN, null);
        }
    }

    /** The money is in the school's account: record it and enrol the learner. */
    public function confirm(Tenant $tenant, CurrentUser $admin, int $id, string $clientIp): PaymentClaim
    {
        $admin->authorise(Permission::SchoolManage);

        return $this->db->transaction(function () use ($tenant, $admin, $id, $clientIp): PaymentClaim {
            $claim = $this->claims->find($tenant->id, $id, true) ?? throw HttpException::notFound();
            if (!$claim->isPending()) {
                return $claim;
            }
            $course = $this->courses->findById($tenant->id, $claim->courseId) ?? throw HttpException::notFound();
            $user = $this->users->findById($claim->userId);
            $membership = $user === null ? null : $this->memberships->find($tenant->id, $user->id);
            if ($user === null || $membership === null || !$membership->isActive()) {
                throw ValidationException::single('claim', $claim->learnerName . ' no longer belongs to this school. Add them on the People page, then confirm.');
            }
            $paymentId = null;
            $plan = $claim->planId === null ? null : $this->plans?->find($tenant->id, $claim->planId, true);
            if ($plan !== null && $claim->planPart !== null) {
                // A part of a payment plan: record it unless that part was paid another way meanwhile.
                if ($plan->nextPart() === $claim->planPart) {
                    $paymentId = $this->payments->storeManual($tenant, $admin, $user, $course, $claim->amountMinor, $claim->paymentNote(), $clientIp, $plan, $claim->planPart)->id;
                }
            } elseif (!$this->payments->hasPaid($tenant, $user->id, $course)) {
                $paymentId = $this->payments->storeManual($tenant, $admin, $user, $course, $claim->amountMinor, $claim->paymentNote(), $clientIp)->id;
            }
            $this->claims->decide($tenant->id, $id, PaymentClaim::CONFIRMED, $admin->user->id, null, $paymentId);
            $this->audit->record($tenant->id, $admin->user->id, 'payment.claim_confirmed', 'course', $course->id, ['claim' => $id, 'payment' => $paymentId], $clientIp);

            return $this->claims->find($tenant->id, $id) ?? $claim;
        });
    }

    /** Nothing arrived, or not enough: the learner is told why. */
    public function reject(Tenant $tenant, CurrentUser $admin, int $id, string $reason, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
        if (mb_strlen($reason) < 3 || mb_strlen($reason) > 255) {
            throw ValidationException::single('reason', 'Tell the learner why, for example “No transfer with that reference arrived”.');
        }
        $claim = $this->claims->find($tenant->id, $id) ?? throw HttpException::notFound();
        if (!$claim->isPending()) {
            return;
        }
        $this->claims->decide($tenant->id, $id, PaymentClaim::REJECTED, $admin->user->id, $reason);
        $this->audit->record($tenant->id, $admin->user->id, 'payment.claim_rejected', 'course', $claim->courseId, ['claim' => $id], $clientIp);
        $this->notifier?->notify(
            $tenant->id,
            $claim->userId,
            self::NOTIFY_DECISION,
            'The school could not confirm your payment for ' . $claim->courseTitle,
            $reason,
            '/courses/' . $claim->courseSlug . '/subscribe',
        );
    }
}
