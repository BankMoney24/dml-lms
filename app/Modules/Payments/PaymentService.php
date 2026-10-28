<?php

declare(strict_types=1);

namespace App\Modules\Payments;

use App\Core\Clock;
use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Log\Logger;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Courses\Course;
use App\Modules\Courses\CourseRepository;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Identity\UserRepository;
use App\Modules\Learning\LearningService;
use App\Modules\Tenancy\MembershipRepository;
use App\Modules\Tenancy\Tenant;

/**
 * Paid courses (PRD 7.6).
 *
 * Access is granted only after the payment is confirmed on the server:
 * either by asking Paystack directly (when the learner comes back from the
 * checkout) or by a webhook whose signature is checked with the school's
 * secret key. Never from the browser's word (PAY-003). Every confirmation
 * checks the reference, the amount and the currency, and is applied under a
 * row lock, so the same payment confirmed twice, or by both routes at once,
 * enrols the learner once (PAY-002).
 */
final class PaymentService
{
    public const MAX_PENDING_PER_HOUR = 10;

    public function __construct(
        private readonly Connection $db,
        private readonly PaymentRepository $payments,
        private readonly PaymentAccountRepository $accounts,
        private readonly PaystackApi $paystack,
        private readonly CourseRepository $courses,
        private readonly UserRepository $users,
        private readonly MembershipRepository $memberships,
        private readonly LearningService $learning,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly Clock $clock,
        private readonly ?\App\Modules\Notifications\Notifier $notifier = null,
        private readonly ?Coupons\Coupons $coupons = null,
        private readonly ?Refunds\Refunds $refunds = null,
        private readonly ?Plans\PaymentPlanRepository $plans = null,
    ) {
    }

    public function isConnected(Tenant $tenant): bool
    {
        return $this->accounts->find($tenant->id) !== null;
    }

    public function hasPaid(Tenant $tenant, int $userId, Course $course): bool
    {
        return $this->payments->hasPaid($tenant->id, $userId, $course->id);
    }

    /** The newest unfinished checkout for this course, to tell a returning learner it is still being confirmed. */
    public function waiting(Tenant $tenant, int $userId, Course $course): ?Payment
    {
        foreach ($this->payments->forUser($tenant->id, $userId) as $row) {
            $p = $row['payment'];
            if ($p->courseId === $course->id && $p->status === Payment::PENDING && !$p->looksAbandoned($this->clock->now()->getTimestamp())) {
                return $p;
            }
        }

        return null;
    }

    /**
     * The plan this learner pays the course through (ADR 0037): the one they are
     * already paying, or a new one when the course offers parts and they have not
     * paid yet. An unpaid plan made at an older price is replaced.
     */
    public function openPlan(Tenant $tenant, Course $course, CurrentUser $user): Plans\PaymentPlan
    {
        if ($this->plans === null || $course->tenantId !== $tenant->id || !$course->isPaid()) {
            throw HttpException::notFound();
        }
        $plan = $this->plans->current($tenant->id, $user->user->id, $course->id);
        if ($plan !== null && $plan->status === Plans\PaymentPlan::ACTIVE) {
            return $plan;
        }
        if ($course->instalments === null) {
            throw ValidationException::single('payment', 'This course is paid in full.');
        }
        if ($this->payments->hasPaid($tenant->id, $user->user->id, $course->id)) {
            throw ValidationException::single('payment', 'You have already paid for this course.');
        }
        $same = $plan !== null && $plan->parts === $course->instalments && $plan->totalMinor === $course->priceMinor && $plan->currency === $course->currency;
        if ($plan !== null && $same && $plan->status === Plans\PaymentPlan::PENDING) {
            return $plan;
        }
        $id = $this->plans->create($tenant->id, $user->user->id, $course->id, $course->instalments, (int) $course->priceMinor, (string) $course->currency);

        return $this->plans->find($tenant->id, $id) ?? throw new \RuntimeException('Plan not stored.');
    }

    /** The learner's plan for this course, if they chose to pay in parts. */
    public function planFor(Tenant $tenant, int $userId, Course $course): ?Plans\PaymentPlan
    {
        return $this->plans?->current($tenant->id, $userId, $course->id);
    }

    /**
     * Starts a Paystack checkout and returns its address. A discount code lowers the
     * amount charged (ADR 0032). With a plan, it charges the plan's next part (ADR 0037).
     */
    public function startCheckout(Tenant $tenant, Course $course, CurrentUser $user, string $baseUrl, string $clientIp, ?string $code = null, ?Plans\PaymentPlan $plan = null): string
    {
        if (!$course->isPaid() || !$course->isPublished() || $course->tenantId !== $tenant->id) {
            throw HttpException::notFound();
        }
        $account = $this->accounts->find($tenant->id);
        $secret = $this->accounts->secret($tenant->id);
        if ($account === null || $secret === null) {
            throw ValidationException::single('payment', 'This school has not set up online payments yet. Ask the school how to pay.');
        }
        $part = null;
        if ($plan !== null) {
            $part = $plan->nextPart();
            if ($part === null || $plan->userId !== $user->user->id || $plan->courseId !== $course->id) {
                throw ValidationException::single('payment', 'There is nothing left to pay on this plan.');
            }
            if ($code !== null && trim($code) !== '') {
                throw ValidationException::single('code', 'Discount codes are for paying in full. Pay in full to use your code.');
            }
        } elseif ($this->payments->hasPaid($tenant->id, $user->user->id, $course->id)) {
            throw ValidationException::single('payment', 'You have already paid for this course.');
        }
        $hourAgo = $this->clock->now()->modify('-1 hour')->format('Y-m-d H:i:s');
        if ($this->payments->pendingSince($tenant->id, $user->user->id, $hourAgo) >= self::MAX_PENDING_PER_HOUR) {
            throw ValidationException::single('payment', 'You have started several payments in the last hour. If money left your account, wait a few minutes: it will show up here.');
        }

        $price = $plan !== null && $part !== null ? $plan->partAmount($part) : (int) $course->priceMinor;
        $currency = $plan !== null ? $plan->currency : (string) $course->currency;
        $coupon = null;
        if ($plan === null && $code !== null && trim($code) !== '' && $this->coupons !== null) {
            $quote = $this->coupons->quote($tenant, $course, $code);
            if ($quote['price'] === 0) {
                throw ValidationException::single('code', 'That code covers the whole price, so there is nothing to pay. Use it to start straight away.');
            }
            $price = $quote['price'];
            $coupon = $quote['coupon'];
        }

        $reference = sprintf('DML-%d-%s', $tenant->id, strtoupper(bin2hex(random_bytes(8))));
        $id = $this->payments->create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->user->id,
            'course_id' => $course->id,
            'reference' => $reference,
            'provider' => 'paystack',
            'mode' => $account['mode'],
            'amount_minor' => $price,
            'currency' => $currency,
            'status' => Payment::PENDING,
            'coupon_id' => $coupon?->id,
            'list_price_minor' => $coupon === null ? null : (int) $course->priceMinor,
            'plan_id' => $plan?->id,
            'plan_part' => $part,
        ]);
        $this->payments->recordEvent($tenant->id, $id, 'created:' . $reference, 'platform', 'checkout.started', 'pending');

        try {
            $url = $this->paystack->initialize($secret, $user->user->email, $price, $currency, $reference, $baseUrl . '/payments/paystack/callback', [
                'school' => $tenant->slug,
                'course' => $course->slug,
                'payment_id' => $id,
            ] + ($plan === null ? [] : ['plan' => $plan->id, 'part' => $part . ' of ' . $plan->parts]));
        } catch (PaymentProviderError $e) {
            $this->payments->markFailed($tenant->id, $id, 'Checkout could not start: ' . $e->getMessage());
            $this->logger->error('Paystack initialize failed', ['tenant' => $tenant->id, 'payment' => $id, 'error' => $e->getMessage()]);
            throw ValidationException::single('payment', 'The payment page could not be opened just now. Nothing was charged. Please try again in a few minutes.');
        }
        $meta = ['reference' => $reference, 'amount' => $price, 'coupon' => $coupon?->code] + ($plan === null ? [] : ['part' => $part, 'parts' => $plan->parts]);
        $this->audit->record($tenant->id, $user->user->id, 'payment.started', 'course', $course->id, $meta, $clientIp);

        return $url;
    }

    /**
     * A code that covers the whole price: the learner gets a place without paying.
     * The code's row is locked while its uses are counted, so a code with one use
     * left cannot be taken twice at the same moment.
     */
    public function redeemFree(Tenant $tenant, Course $course, CurrentUser $user, string $code, string $clientIp): Payment
    {
        if ($this->coupons === null || !$course->isPaid() || !$course->isPublished() || $course->tenantId !== $tenant->id) {
            throw \App\Core\Http\HttpException::notFound();
        }
        $payment = $this->db->transaction(function () use ($tenant, $course, $user, $code): Payment {
            $quote = $this->coupons->quote($tenant, $course, $code, true);
            if ($quote['price'] !== 0) {
                throw ValidationException::single('code', 'That code takes ' . $quote['coupon']->label() . ' off. Pay the rest to start.');
            }
            if ($this->payments->hasPaid($tenant->id, $user->user->id, $course->id)) {
                throw ValidationException::single('code', 'You already have this course.');
            }
            $reference = sprintf('CPN-%d-%s', $tenant->id, strtoupper(bin2hex(random_bytes(6))));
            $id = $this->payments->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->user->id,
                'course_id' => $course->id,
                'reference' => $reference,
                'provider' => 'coupon',
                'mode' => 'live',
                'amount_minor' => 0,
                'currency' => (string) $course->currency,
                'status' => Payment::PAID,
                'note' => 'Discount code ' . $quote['coupon']->code,
                'coupon_id' => $quote['coupon']->id,
                'list_price_minor' => (int) $course->priceMinor,
            ]);
            $this->payments->recordEvent($tenant->id, $id, 'coupon:' . $reference, 'platform', 'coupon.redeemed', 'paid', ['code' => $quote['coupon']->code]);
            $payment = $this->payments->find($tenant->id, $id) ?? throw new \RuntimeException('Payment not stored.');
            $this->enrolAfterPayment($tenant, $payment);

            return $payment;
        });
        $this->audit->record($tenant->id, $user->user->id, 'coupon.redeemed', 'course', $course->id, ['reference' => $payment->reference], $clientIp);

        return $payment;
    }

    /**
     * The learner is back from the checkout: ask Paystack what happened. The
     * browser's word that it paid is never enough; only Paystack's answer is.
     *
     * @return array{payment: ?Payment, outcome: string} outcome: paid | pending | failed | unknown
     */
    public function confirmReturn(Tenant $tenant, int $userId, string $reference): array
    {
        $payment = $this->payments->findByReference($tenant->id, $reference);
        if ($payment === null || $payment->userId !== $userId || $payment->provider !== 'paystack') {
            // Someone else's reference is treated as unknown: no lookup, nothing revealed.
            return ['payment' => null, 'outcome' => 'unknown'];
        }
        $secret = $this->accounts->secret($tenant->id);
        if ($secret === null) {
            return ['payment' => $payment, 'outcome' => $payment->isPaid() ? 'paid' : 'pending'];
        }
        if ($payment->isPaid()) {
            return ['payment' => $payment, 'outcome' => 'paid'];
        }
        try {
            $tx = $this->paystack->verify($secret, $reference);
        } catch (PaymentProviderError $e) {
            $this->logger->error('Paystack verify failed', ['tenant' => $tenant->id, 'reference' => $reference, 'error' => $e->getMessage()]);

            return ['payment' => $payment, 'outcome' => 'pending'];
        }
        $outcome = $this->apply($tenant, $reference, $tx, 'verify', 'verify:' . $reference . ':' . $tx['status'] . ':' . $tx['id']);

        return ['payment' => $this->payments->find($tenant->id, $payment->id), 'outcome' => $outcome];
    }

    /**
     * A Paystack webhook. Returns false when the signature is wrong (the caller
     * answers 401) and true otherwise, including for events we ignore, so
     * Paystack stops retrying them.
     */
    public function handleWebhook(Tenant $tenant, string $rawBody, ?string $signature): bool
    {
        $secret = $this->accounts->secret($tenant->id);
        if ($secret === null || $signature === null || !hash_equals(hash_hmac('sha512', $rawBody, $secret), strtolower(trim($signature)))) {
            $this->logger->error('Paystack webhook with a bad signature', ['tenant' => $tenant->id]);

            return false;
        }
        $event = json_decode($rawBody, true);
        if (!is_array($event) || !is_string($event['event'] ?? null) || !is_array($event['data'] ?? null)) {
            return true;
        }
        $data = $event['data'];
        if (str_starts_with($event['event'], 'refund.')) {
            // Refund webhooks settle refunds started from the payments page (ADR 0032). Repeats are ignored.
            if ($this->payments->recordEvent($tenant->id, null, 'webhook:' . $event['event'] . ':' . hash('sha256', $rawBody), 'webhook', $event['event'], 'received')) {
                $this->refunds?->settleFromWebhook($tenant, $event['event'], $data);
            }

            return true;
        }
        $reference = is_string($data['reference'] ?? null) ? $data['reference'] : '';
        if ($event['event'] !== 'charge.success' || $reference === '') {
            $this->payments->recordEvent($tenant->id, null, 'webhook:' . $event['event'] . ':' . hash('sha256', $rawBody), 'webhook', $event['event'], 'ignored');

            return true;
        }
        $tx = [
            'status' => is_string($data['status'] ?? null) ? $data['status'] : 'unknown',
            'amount' => is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : 0,
            'currency' => is_string($data['currency'] ?? null) ? $data['currency'] : '',
            'reference' => $reference,
            'id' => is_scalar($data['id'] ?? null) ? (string) $data['id'] : '',
            'channel' => is_string($data['channel'] ?? null) ? $data['channel'] : null,
        ];
        $this->apply($tenant, $reference, $tx, 'webhook', 'webhook:charge.success:' . $tx['id'] . ':' . $reference);

        return true;
    }

    /**
     * Applies what Paystack reported, once per distinct event.
     *
     * @param array{status: string, amount: int, currency: string, reference: string, id: string, channel: ?string} $tx
     * @return string paid | pending | failed | unknown
     */
    private function apply(Tenant $tenant, string $reference, array $tx, string $source, string $eventKey): string
    {
        return $this->db->transaction(function () use ($tenant, $reference, $tx, $source, $eventKey): string {
            $payment = $this->payments->findByReference($tenant->id, $reference, true);
            if ($payment === null) {
                $this->payments->recordEvent($tenant->id, null, $eventKey, $source, 'charge', 'unknown reference', ['reference' => mb_substr($reference, 0, 64)]);

                return 'unknown';
            }
            $details = ['status' => $tx['status'], 'amount' => $tx['amount'], 'currency' => $tx['currency'], 'transaction' => $tx['id'], 'channel' => $tx['channel']];
            if ($payment->isPaid()) {
                $this->payments->recordEvent($tenant->id, $payment->id, $eventKey, $source, 'charge', 'already paid', $details);

                return 'paid';
            }
            if ($tx['status'] !== 'success') {
                $failed = in_array($tx['status'], ['failed', 'reversed'], true);
                if ($failed) {
                    $this->payments->markFailed($tenant->id, $payment->id, 'Paystack reported ' . $tx['status']);
                }
                $this->payments->recordEvent($tenant->id, $payment->id, $eventKey, $source, 'charge', $tx['status'], $details);

                return $failed ? 'failed' : 'pending';
            }
            if ($tx['reference'] !== $payment->reference || $tx['amount'] !== $payment->amountMinor || strtoupper($tx['currency']) !== $payment->currency) {
                // Never grant access for a different amount or currency (PAY-003).
                $this->payments->markFailed($tenant->id, $payment->id, 'Paystack amount or currency did not match; not accepted');
                $this->payments->recordEvent($tenant->id, $payment->id, $eventKey, $source, 'charge', 'mismatch', $details);
                $this->logger->error('Paystack amount mismatch', ['tenant' => $tenant->id, 'payment' => $payment->id] + $details);

                return 'failed';
            }
            $this->payments->markPaid($tenant->id, $payment->id, $tx['id'] !== '' ? $tx['id'] : null, $tx['channel']);
            $this->payments->recordEvent($tenant->id, $payment->id, $eventKey, $source, 'charge', 'paid', $details);
            if ($payment->planId !== null) {
                $this->plans?->sync($tenant->id, $payment->planId);
            }
            $this->enrolAfterPayment($tenant, $payment);
            $this->audit->record($tenant->id, $payment->userId, 'payment.paid', 'course', $payment->courseId, ['reference' => $payment->reference, 'via' => $source]);

            return 'paid';
        });
    }

    /**
     * An admin records a payment made by bank transfer or cash, and the learner is enrolled.
     */
    public function recordManual(Tenant $tenant, CurrentUser $admin, string $email, int $courseId, string $amountText, string $note, string $clientIp): Payment
    {
        $admin->authorise(Permission::SchoolManage);
        $errors = [];
        $course = $this->courses->findById($tenant->id, $courseId);
        if ($course === null || !$course->isPaid()) {
            $errors['course_id'] = 'Choose a paid course.';
        }
        $user = $this->users->findByEmail(UserRepository::normaliseEmail($email));
        $membership = $user === null ? null : $this->memberships->find($tenant->id, $user->id);
        if ($membership === null || !$membership->isActive()) {
            $errors['email'] = 'No one with that email belongs to this school. Add them on the People page first.';
        }
        $amount = Money::parse($amountText);
        if ($amount === null || $amount < 1 || $amount > Money::MAX_MINOR) {
            $errors['amount'] = 'Enter the amount received, for example 15000.';
        }
        $note = trim((string) preg_replace('/\s+/u', ' ', $note));
        if (mb_strlen($note) < 3 || mb_strlen($note) > 200) {
            $errors['note'] = 'Say how it was paid, for example “Bank transfer, teller 004512”.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        /** @var Course $course */
        /** @var \App\Modules\Identity\User $user */
        $plan = $this->plans?->current($tenant->id, $user->id, $course->id);
        if ($plan !== null && $plan->status === Plans\PaymentPlan::ACTIVE) {
            throw ValidationException::single('email', 'That learner is paying this course in parts. Record the next part on their plan, under Payment plans.');
        }
        if ($this->payments->hasPaid($tenant->id, $user->id, $course->id)) {
            throw ValidationException::single('email', 'That learner has already paid for this course.');
        }

        return $this->storeManual($tenant, $admin, $user, $course, (int) $amount, $note, $clientIp);
    }

    /**
     * Stores a payment the school received directly and enrols the learner.
     * Callers have checked the person, the course and the amount. Runs inside
     * the caller's transaction when there is one.
     */
    public function storeManual(
        Tenant $tenant,
        CurrentUser $admin,
        \App\Modules\Identity\User $user,
        Course $course,
        int $amount,
        string $note,
        string $clientIp,
        ?Plans\PaymentPlan $plan = null,
        ?int $part = null,
    ): Payment {
        $admin->authorise(Permission::SchoolManage);
        $payment = $this->db->transaction(function () use ($tenant, $admin, $user, $course, $amount, $note, $plan, $part): Payment {
            $reference = sprintf('MAN-%d-%s', $tenant->id, strtoupper(bin2hex(random_bytes(6))));
            $id = $this->payments->create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'course_id' => $course->id,
                'reference' => $reference,
                'provider' => 'manual',
                'mode' => 'live',
                'amount_minor' => $amount,
                'currency' => $plan !== null ? $plan->currency : (string) $course->currency,
                'status' => Payment::PAID,
                'note' => $note,
                'recorded_by' => $admin->user->id,
                'plan_id' => $plan?->id,
                'plan_part' => $plan === null ? null : $part,
            ]);
            if ($plan !== null) {
                $this->plans?->sync($tenant->id, $plan->id);
            }
            $this->payments->recordEvent($tenant->id, $id, 'manual:' . $reference, 'admin', 'recorded', 'paid', ['by' => $admin->user->id]);
            $payment = $this->payments->find($tenant->id, $id) ?? throw new \RuntimeException('Payment not stored.');
            $this->enrolAfterPayment($tenant, $payment);

            return $payment;
        });
        $this->audit->record($tenant->id, $admin->user->id, 'payment.recorded', 'user', $user->id, ['reference' => $payment->reference, 'amount' => $payment->amountMinor], $clientIp);

        return $payment;
    }

    /** Saves the school's Paystack secret key after checking Paystack accepts it. */
    public function connect(Tenant $tenant, CurrentUser $admin, string $secret, string $currency, string $clientIp): string
    {
        $admin->authorise(Permission::SchoolManage);
        $secret = trim($secret);
        if (preg_match('/^sk_(test|live)_[A-Za-z0-9]{20,80}$/', $secret, $m) !== 1) {
            throw ValidationException::single('secret', 'Paste the secret key from Paystack: Settings → API Keys & Webhooks. It starts with sk_live_ or sk_test_.');
        }
        if (!Money::isSupported($currency)) {
            throw ValidationException::single('currency', 'Choose a currency.');
        }
        try {
            $accepted = $this->paystack->ping($secret);
        } catch (PaymentProviderError $e) {
            throw ValidationException::single('secret', 'Paystack could not be reached from the server to check the key. ' . $e->getMessage());
        }
        if (!$accepted) {
            throw ValidationException::single('secret', 'Paystack did not accept that key. Copy it again from your Paystack dashboard.');
        }
        $this->accounts->save($tenant->id, $secret, $m[1], $currency, $admin->user->id);
        $this->audit->record($tenant->id, $admin->user->id, 'payments.connected', null, null, ['mode' => $m[1], 'currency' => $currency], $clientIp);

        return $m[1];
    }

    public function disconnect(Tenant $tenant, CurrentUser $admin, string $clientIp): void
    {
        $admin->authorise(Permission::SchoolManage);
        $this->accounts->delete($tenant->id);
        $this->audit->record($tenant->id, $admin->user->id, 'payments.disconnected', null, null, [], $clientIp);
    }

    private function enrolAfterPayment(Tenant $tenant, Payment $payment): void
    {
        $course = $this->courses->findById($tenant->id, $payment->courseId);
        $user = $this->users->findById($payment->userId);
        $membership = $user === null ? null : $this->memberships->find($tenant->id, $user->id);
        if ($course === null || $user === null || $membership === null || !$course->isPublished()) {
            // The payment stays paid; the learner can enrol from the course page once it is open again.
            $this->logger->error('Paid but could not enrol', ['tenant' => $tenant->id, 'payment' => $payment->id]);

            return;
        }
        $this->learning->enrol($course, new CurrentUser($user, $membership));
        $plan = $payment->planId === null ? null : $this->plans?->find($tenant->id, $payment->planId);
        $later = $plan !== null && $payment->planPart !== null && $payment->planPart > 1;
        $next = $plan?->nextPart();
        $this->notifier?->notify(
            $tenant->id,
            $user->id,
            \App\Modules\Notifications\Notifier::PAYMENT_PAID,
            $plan !== null && $payment->planPart !== null
                ? sprintf('Part %d of %d received for %s', $payment->planPart, $plan->parts, $course->title)
                : 'Payment received for ' . $course->title,
            match (true) {
                $plan !== null && $next === null => $later ? 'That was the last part: the course is fully paid. Thank you.' : 'You are enrolled.',
                $plan !== null && $next !== null => ($later ? 'Thank you. ' : 'You are enrolled. Start whenever you are ready. ')
                    . sprintf('Part %d (%s) is due on %s.', $next, $plan->partLabel($next), $plan->dueAt($next)?->format('j M Y') ?? 'the date shown on the course'),
                default => 'You are enrolled. Start whenever you are ready.',
            },
            '/courses/' . $course->slug . ($plan !== null ? '/subscribe' : ''),
        );
    }
}
