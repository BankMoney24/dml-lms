<?php

declare(strict_types=1);

namespace App\Modules\Payments;

final class Payment
{
    public const PENDING = 'pending';
    public const PAID = 'paid';
    public const FAILED = 'failed';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly string $reference,
        public readonly string $provider,
        public readonly string $mode,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $providerTransactionId,
        public readonly ?string $channel,
        public readonly ?string $note,
        public readonly string $createdAt,
        public readonly ?string $paidAt,
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
            (string) $row['reference'],
            (string) $row['provider'],
            (string) $row['mode'],
            (int) $row['amount_minor'],
            (string) $row['currency'],
            (string) $row['status'],
            $row['provider_transaction_id'] === null ? null : (string) $row['provider_transaction_id'],
            $row['channel'] === null ? null : (string) $row['channel'],
            $row['note'] === null ? null : (string) $row['note'],
            (string) $row['created_at'],
            $row['paid_at'] === null ? null : (string) $row['paid_at'],
        );
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }

    public function amount(): string
    {
        return Money::format($this->amountMinor, $this->currency);
    }

    /** Pending for more than a day: the learner left the checkout. */
    public function looksAbandoned(int $now): bool
    {
        return $this->status === self::PENDING && $now - (int) strtotime($this->createdAt . ' UTC') > 86400;
    }

    public function statusLabel(int $now): string
    {
        return match (true) {
            $this->status === self::PAID => 'Paid',
            $this->status === self::FAILED => 'Failed',
            $this->looksAbandoned($now) => 'Not completed',
            default => 'Waiting',
        };
    }

    public function methodLabel(): string
    {
        if ($this->provider === 'manual') {
            return 'Recorded by hand';
        }

        return 'Paystack' . ($this->channel !== null ? ' (' . str_replace('_', ' ', $this->channel) . ')' : '') . ($this->mode === 'test' ? ', test mode' : '');
    }
}
