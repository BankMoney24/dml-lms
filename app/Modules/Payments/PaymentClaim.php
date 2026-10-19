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

    /** Less than the course price: the school should look twice before confirming. */
    public function isShort(): bool
    {
        return $this->coursePriceMinor !== null && $this->amountMinor < $this->coursePriceMinor;
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
