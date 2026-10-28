<?php

declare(strict_types=1);

namespace App\Modules\Payments;

/**
 * A learner's word that they paid the school directly (ADR 0026). It opens
 * nothing by itself: the school checks its bank and confirms or rejects it.
 */
final class PaymentClaim
{
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    public const METHODS = [
        'transfer' => 'Bank transfer',
        'deposit' => 'Bank deposit',
        'pos' => 'POS at the school',
        'cash' => 'Cash at the school office',
    ];

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $method,
        public readonly string $paidOn,
        public readonly string $payerReference,
        public readonly string $note,
        public readonly string $status,
        public readonly ?string $reason,
        public readonly ?int $paymentId,
        public readonly string $createdAt,
        public readonly ?string $decidedAt,
        public readonly string $courseTitle = '',
        public readonly string $courseSlug = '',
        public readonly string $learnerName = '',
        public readonly string $learnerEmail = '',
        public readonly ?int $coursePriceMinor = null,
        /** The plan part this pays, when paying in parts (ADR 0037). */
        public readonly ?int $planId = null,
        public readonly ?int $planPart = null,
        public readonly ?int $planParts = null,
        public readonly ?int $planTotalMinor = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            (int) $row['amount_minor'],
            (string) $row['currency'],
            (string) $row['method'],
            (string) $row['paid_on'],
            (string) $row['payer_reference'],
            (string) $row['note'],
            (string) $row['status'],
            isset($row['reason']) ? (string) $row['reason'] : null,
            isset($row['payment_id']) ? (int) $row['payment_id'] : null,
            (string) $row['created_at'],
            isset($row['decided_at']) ? (string) $row['decided_at'] : null,
            (string) ($row['course_title'] ?? ''),
            (string) ($row['course_slug'] ?? ''),
            (string) ($row['learner_name'] ?? ''),
            (string) ($row['learner_email'] ?? ''),
            isset($row['price_minor']) ? (int) $row['price_minor'] : null,
            isset($row['plan_id']) ? (int) $row['plan_id'] : null,
            isset($row['plan_part']) ? (int) $row['plan_part'] : null,
            isset($row['plan_parts']) ? (int) $row['plan_parts'] : null,
            isset($row['plan_total']) ? (int) $row['plan_total'] : null,
        );
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }

    public function amount(): string
    {
        return Money::format($this->amountMinor, $this->currency);
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? 'Paid to the school';
    }

    /** What the school expects: the course price, or the part's amount on a plan. */
    public function expectedMinor(): ?int
    {
        if ($this->planPart !== null && $this->planParts !== null && $this->planTotalMinor !== null) {
            return Plans\PaymentPlan::split($this->planTotalMinor, $this->planParts, $this->planPart);
        }

        return $this->coursePriceMinor;
    }

    /** "Part 2 of 3", or null when paying in full. */
    public function partLabel(): ?string
    {
        return $this->planPart === null || $this->planParts === null ? null : sprintf('Part %d of %d', $this->planPart, $this->planParts);
    }

    /** Less than expected: the school should look twice before confirming. */
    public function isShort(): bool
    {
        $expected = $this->expectedMinor();

        return $expected !== null && $this->amountMinor < $expected;
    }

    /** What goes on the recorded payment, so the school's list says where it came from. */
    public function paymentNote(): string
    {
        $note = $this->methodLabel() . ' on ' . $this->paidOn;
        if ($this->payerReference !== '') {
            $note .= ', ref ' . $this->payerReference;
        }

        return mb_substr($note . ' (confirmed from the learner\'s note)', 0, 200);
    }
}
