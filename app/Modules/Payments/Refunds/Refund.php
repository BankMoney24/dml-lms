<?php

declare(strict_types=1);

namespace App\Modules\Payments\Refunds;

use App\Modules\Payments\Money;

/** Money paid back to a learner, in full or in part (ADR 0032). */
final class Refund
{
    public const PENDING = 'pending';
    public const PROCESSED = 'processed';
    public const FAILED = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $paymentId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $reason,
        public readonly string $status,
        public readonly ?string $providerRefundId,
        public readonly ?string $failure,
        public readonly bool $withdrewLearner,
        public readonly string $createdAt,
        public readonly ?string $processedAt,
        public readonly string $createdByName = '',
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['payment_id'],
            (int) $row['amount_minor'],
            (string) $row['currency'],
            (string) $row['reason'],
            (string) $row['status'],
            $row['provider_refund_id'] === null ? null : (string) $row['provider_refund_id'],
            $row['failure'] === null ? null : (string) $row['failure'],
            (bool) $row['withdrew_learner'],
            (string) $row['created_at'],
            $row['processed_at'] === null ? null : (string) $row['processed_at'],
            (string) ($row['created_by_name'] ?? ''),
        );
    }

    public function amount(): string
    {
        return Money::format($this->amountMinor, $this->currency);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PROCESSED => 'Paid back',
            self::FAILED => 'Failed',
            default => 'Being paid back',
        };
    }
}
