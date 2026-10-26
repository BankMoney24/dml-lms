<?php

declare(strict_types=1);

namespace App\Modules\Payments\Coupons;

use App\Modules\Courses\Course;
use App\Modules\Payments\Money;

/** A school's discount code (ADR 0032): a percentage or a fixed amount off, for one paid course or all of them. */
final class Coupon
{
    public const ACTIVE = 'active';
    public const DISABLED = 'disabled';

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly string $code,
        public readonly ?int $courseId,
        public readonly ?int $percentOff,
        public readonly ?int $amountOffMinor,
        public readonly ?string $currency,
        public readonly ?int $maxUses,
        public readonly ?string $expiresOn,
        public readonly string $status,
        public readonly string $note,
        public readonly string $createdAt,
        /** Confirmed payments that used it (refunded ones give the use back). */
        public readonly int $uses = 0,
        public readonly ?string $courseTitle = null,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (string) $row['code'],
            $row['course_id'] === null ? null : (int) $row['course_id'],
            $row['percent_off'] === null ? null : (int) $row['percent_off'],
            $row['amount_off_minor'] === null ? null : (int) $row['amount_off_minor'],
            $row['currency'] === null ? null : (string) $row['currency'],
            $row['max_uses'] === null ? null : (int) $row['max_uses'],
            $row['expires_on'] === null ? null : (string) $row['expires_on'],
            (string) $row['status'],
            (string) ($row['note'] ?? ''),
            (string) $row['created_at'],
            (int) ($row['uses'] ?? 0),
            isset($row['course_title']) ? (string) $row['course_title'] : null,
        );
    }

    /** Codes are typed by people: compared without case and surrounding spaces. */
    public static function normalise(string $code): string
    {
        return strtoupper(trim($code));
    }

    public function isActive(): bool
    {
        return $this->status === self::ACTIVE;
    }

    public function label(): string
    {
        return $this->percentOff !== null
            ? $this->percentOff . '% off'
            : Money::format((int) $this->amountOffMinor, (string) $this->currency) . ' off';
    }

    public function hasExpired(string $today): bool
    {
        return $this->expiresOn !== null && $this->expiresOn < $today;
    }

    public function usedUp(): bool
    {
        return $this->maxUses !== null && $this->uses >= $this->maxUses;
    }

    /** Why the code cannot be used on this course today, or null when it can. */
    public function problemFor(Course $course, string $today): ?string
    {
        return match (true) {
            !$this->isActive() => 'That code has been switched off by the school.',
            $this->hasExpired($today) => 'That code expired on ' . date('j M Y', (int) strtotime($this->expiresOn . ' 00:00:00 UTC')) . '.',
            $this->usedUp() => 'That code has been used as many times as the school allows.',
            $this->courseId !== null && $this->courseId !== $course->id => 'That code is for a different course.',
            $this->amountOffMinor !== null && $this->currency !== $course->currency => 'That code is in a different currency from this course.',
            default => null,
        };
    }

    /** The amount off this course's price (never more than the price). */
    public function discountOn(Course $course): int
    {
        $price = (int) $course->priceMinor;
        if ($this->percentOff !== null) {
            // Rounded down to a whole unit of money, so the price stays a whole amount.
            $off = intdiv($price * $this->percentOff, 100);

            return $this->percentOff === 100 ? $price : min($price, $off - ($off % 100));
        }

        return min($price, (int) $this->amountOffMinor);
    }
}
