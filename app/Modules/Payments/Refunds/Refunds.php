<?php

declare(strict_types=1);

namespace App\Modules\Payments\Refunds;

use App\Core\Database\Connection;
use App\Core\Http\HttpException;
use App\Core\Log\Logger;
use App\Core\Validation\ValidationException;
use App\Modules\Audit\AuditLog;
use App\Modules\Identity\CurrentUser;
use App\Modules\Identity\Permission;
use App\Modules\Learning\LearningService;
use App\Modules\Notifications\Notifier;
use App\Modules\Payments\Money;
use App\Modules\Payments\Payment;
use App\Modules\Payments\PaymentAccountRepository;
use App\Modules\Payments\PaymentProviderError;
use App\Modules\Payments\PaymentRepository;
use App\Modules\Payments\PaystackApi;
use App\Modules\Tenancy\Tenant;

/**
 * Paying money back (ADR 0032).
 *
 * A Paystack payment is refunded through Paystack: the refund starts as
 * "being paid back" and Paystack's refund webhook marks it paid back or failed.
 * A payment the school recorded by hand was paid outside the platform, so its
 * refund is too: the administrator records that the money was returned.
 *
 * The payment row is locked while refunds are added up, so two administrators
 * cannot pay back more than was paid. When the whole amount is paid back, the
 * payment counts as refunded and no longer opens the course; the learner can
 * also be taken off the course straight away (their progress is kept).
 */
final class Refunds
{
    public function __construct(
        private readonly Connection $db,
        private readonly PaymentRepository $payments,
        private readonly RefundRepository $refunds,
        private readonly PaymentAccountRepository $accounts,
        private readonly PaystackApi $paystack,
        private readonly LearningService $learning,
        private readonly AuditLog $audit,
        private readonly Logger $logger,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /** What can still be paid back on a payment. */
    public function refundable(Tenant $tenant, Payment $payment): int
    {
        if (!$payment->isPaid() || $payment->provider === 'coupon') {
            return 0;
        }

        return max(0, $payment->amountMinor - $this->refunds->activeTotal($tenant->id, $payment->id));
    }

    public function refund(Tenant $tenant, CurrentUser $admin, int $paymentId, string $amountText, string $reason, bool $withdraw, string $clientIp): Refund
    {
        $admin->authorise(Permission::SchoolManage);
        $refund = $this->db->transaction(function () use ($tenant, $admin, $paymentId, $amountText, $reason, $withdraw): Refund {
            $payment = $this->payments->findLocked($tenant->id, $paymentId) ?? throw HttpException::notFound();
            $left = $this->refundable($tenant, $payment);
            if ($left === 0) {
                throw ValidationException::single('refund_amount', $payment->provider === 'coupon'
                    ? 'Nothing was paid: this place came from a discount code.'
                    : 'There is nothing left to pay back on this payment.');
            }
            $errors = [];
            $amountText = trim($amountText);
            $amount = $amountText === '' ? $left : Money::parse($amountText);
            if ($amount === null || $amount < 1 || $amount > $left) {
                $errors['refund_amount'] = 'Enter an amount up to ' . Money::format($left, $payment->currency) . ', or leave it empty to pay back all of it.';
            }
            $reason = trim((string) preg_replace('/\s+/u', ' ', $reason));
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 200) {
                $errors['refund_reason'] = 'Say why, in a few words. The learner sees it, for example “Course cancelled” or “Paid twice”.';
            }
            if ($errors !== []) {
                throw new ValidationException($errors);
            }
            /** @var int $amount */

            $status = Refund::PROCESSED;
            $providerId = null;
            if ($payment->provider === 'paystack') {
                $secret = $this->accounts->secret($tenant->id);
                if ($secret === null) {
                    throw ValidationException::single('refund_amount', 'Online payments are not connected, so Paystack cannot be asked to pay this back. Connect the key again first.');
                }
                try {
                    $answer = $this->paystack->refund($secret, $payment->reference, $amount, $reason);
                } catch (PaymentProviderError $e) {
                    $this->logger->error('Paystack refund refused', ['tenant' => $tenant->id, 'payment' => $payment->id, 'error' => $e->getMessage()]);
                    throw ValidationException::single('refund_amount', 'Paystack did not start the refund. ' . $e->getMessage());
                }
                $status = $answer['status'] === 'processed' ? Refund::PROCESSED : Refund::PENDING;
                $providerId = $answer['id'];
            }
            $id = $this->refunds->create($tenant->id, $payment->id, $amount, $payment->currency, $reason, $status, $providerId, $withdraw, $admin->user->id);
            $this->payments->recordEvent($tenant->id, $payment->id, 'refund:' . $id, 'admin', 'refund', $status, ['amount' => $amount, 'by' => $admin->user->id]);
            if ($amount === $left) {
                $this->payments->markRefunded($tenant->id, $payment->id);
            }
            if ($withdraw) {
                $this->learning->withdraw($tenant->id, $payment->courseId, $payment->userId, $admin->user->id);
            }

            return $this->refunds->find($tenant->id, $id) ?? throw new \RuntimeException('Refund not stored.');
        });
        $payment = $this->payments->find($tenant->id, $refund->paymentId);
        $this->audit->record($tenant->id, $admin->user->id, 'payment.refunded', 'payment', $refund->paymentId, [
            'amount' => $refund->amountMinor, 'status' => $refund->status, 'withdrew' => $withdraw,
        ], $clientIp);
        if ($payment !== null) {
            $this->notifier?->notify(
                $tenant->id,
                $payment->userId,
                Notifier::PAYMENT_REFUNDED,
                'Refund of ' . $refund->amount(),
                ($refund->status === Refund::PROCESSED ? 'The school has paid it back. ' : 'Paystack is paying it back to the card or account you paid with; this usually takes a few working days. ')
                    . 'Reason: ' . $refund->reason,
                '/account/receipts/' . $payment->reference,
            );
        }

        return $refund;
    }

    /**
     * Paystack's refund webhooks (refund.processed, refund.failed, and the in-between
     * refund.pending / refund.processing, which change nothing). Matched by the payment
     * reference and amount to the oldest refund still waiting.
     *
     * @param array<string, mixed> $data the webhook's "data"
     */
    public function settleFromWebhook(Tenant $tenant, string $event, array $data): void
    {
        $outcome = match ($event) {
            'refund.processed' => Refund::PROCESSED,
            'refund.failed' => Refund::FAILED,
            default => null,
        };
        if ($outcome === null) {
            return;
        }
        $reference = $data['transaction_reference'] ?? (is_array($data['transaction'] ?? null) ? ($data['transaction']['reference'] ?? null) : null);
        if (!is_string($reference) || $reference === '') {
            return;
        }
        $amount = is_numeric($data['amount'] ?? null) ? (int) $data['amount'] : null;
        $this->db->transaction(function () use ($tenant, $reference, $amount, $outcome): void {
            $payment = $this->payments->findByReference($tenant->id, $reference, true);
            if ($payment === null) {
                return;
            }
            $refund = $this->refunds->oldestPending($tenant->id, $payment->id, $amount) ?? $this->refunds->oldestPending($tenant->id, $payment->id, null);
            if ($refund === null) {
                return;
            }
            $this->refunds->settle($tenant->id, $refund->id, $outcome, $outcome === Refund::FAILED ? 'Paystack could not pay it back' : null);
            $this->payments->recordEvent($tenant->id, $payment->id, 'refund-settled:' . $refund->id . ':' . $outcome, 'webhook', 'refund', $outcome, ['amount' => $refund->amountMinor]);
            if ($outcome === Refund::FAILED && $payment->status === Payment::REFUNDED) {
                // The money did not go back, so the payment counts as paid again.
                $this->db->execute("UPDATE payments SET status = 'paid' WHERE tenant_id = ? AND id = ? AND status = 'refunded'", [$tenant->id, $payment->id]);
            }
        });
    }
}
