<?php

declare(strict_types=1);

namespace App\Modules\Payments\Plans;

use App\Modules\Payments\Money;
use DateTimeImmutable;
use DateTimeZone;

/**
 * A learner paying for a course in parts (ADR 0037). The price is split into
 * equal parts; any odd amount goes on part 1. Part 1 opens the course and part N
 * is due (N - 1) x 30 days after part 1 was paid. A part more than GRACE_DAYS
 * late pauses the course until it is paid.
 */
final class PaymentPlan
{
    public const PENDING = 'pending';
    public const ACTIVE = 'active';
    public const COMPLETED = 'completed';
    public const WRITTEN_OFF = 'written_off';

    public const MIN_PARTS = 2;
    public const MAX_PARTS = 4;
    public const PART_DAYS = 30;
    public const GRACE_DAYS = 7;
    /** Reminders go out this many days before a part is due. */
    public const SOON_DAYS = 3;

    /** @param list<int> $paidParts */
    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $userId,
        public readonly int $courseId,
        public readonly int $parts,
        public readonly int $totalMinor,
        public readonly string $currency,
        public readonly string $status,
        public readonly ?string $startedAt,
        public readonly ?string $reminded,
        public readonly array $paidParts,
        public readonly string $createdAt,
        public readonly string $learnerName = '',
        public readonly string $learnerEmail = '',
        public readonly string $courseTitle = '',
        public readonly string $courseSlug = '',
        public readonly ?string $endedNote = null,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int>            $paidParts
     */
    public static function fromRow(array $row, array $paidParts): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['user_id'],
            (int) $row['course_id'],
            max(self::MIN_PARTS, min(self::MAX_PARTS, (int) $row['parts'])),
            (int) $row['total_minor'],
            (string) $row['currency'],
            (string) $row['status'],
            $row['started_at'] === null ? null : (string) $row['started_at'],
            $row['reminded'] === null ? null : (string) $row['reminded'],
            $paidParts,
            (string) $row['created_at'],
            (string) ($row['learner_name'] ?? ''),
            (string) ($row['learner_email'] ?? ''),
            (string) ($row['course_title'] ?? ''),
            (string) ($row['course_slug'] ?? ''),
            isset($row['ended_note']) ? (string) $row['ended_note'] : null,
        );
    }

    /** What part $part costs, in minor units: equal parts, the odd amount on part 1. */
    public static function split(int $totalMinor, int $parts, int $part): int
    {
        $each = intdiv($totalMinor, $parts);

        return $part === 1 ? $totalMinor - $each * ($parts - 1) : $each;
    }

    public function partAmount(int $part): int
    {
        return self::split($this->totalMinor, $this->parts, $part);
    }

    public function partLabel(int $part): string
    {
        return Money::format($this->partAmount($part), $this->currency);
    }

    public function isPartPaid(int $part): bool
    {
        return in_array($part, $this->paidParts, true);
    }

    /** The first part not paid yet, while the plan still expects payments. */
    public function nextPart(): ?int
    {
        if ($this->status === self::COMPLETED || $this->status === self::WRITTEN_OFF) {
            return null;
        }
        for ($part = 1; $part <= $this->parts; $part++) {
            if (!$this->isPartPaid($part)) {
                return $part;
            }
        }

        return null;
    }

    /** Still to pay, in minor units (nothing once completed or written off). */
    public function balance(): int
    {
        if ($this->nextPart() === null) {
            return 0;
        }
        $owed = 0;
        for ($part = 1; $part <= $this->parts; $part++) {
            $owed += $this->isPartPaid($part) ? 0 : $this->partAmount($part);
        }

        return $owed;
    }

    public function paidMinor(): int
    {
        return array_sum(array_map(fn (int $p): int => $this->partAmount($p), array_values(array_unique($this->paidParts))));
    }

    /** When part $part is due (UTC), once part 1 is paid. */
    public function dueAt(int $part): ?DateTimeImmutable
    {
        if ($this->startedAt === null) {
            return null;
        }
        $start = new DateTimeImmutable($this->startedAt, new DateTimeZone('UTC'));

        return $part <= 1 ? $start : $start->modify('+' . (($part - 1) * self::PART_DAYS) . ' days');
    }

    /**
     * Where the plan stands:
     * pending (part 1 not paid) | on_time | due_soon | late (due, within grace)
     * | paused (beyond grace: the course is paused) | completed | written_off.
     */
    public function standing(DateTimeImmutable $now): string
    {
        if ($this->status === self::WRITTEN_OFF || $this->status === self::COMPLETED) {
            return $this->status;
        }
        $next = $this->nextPart();
        if ($next === null) {
            return self::COMPLETED;
        }
        $due = $this->dueAt($next);
        if ($next === 1 || $due === null) {
            return self::PENDING;
        }
        if ($now > $due->modify('+' . self::GRACE_DAYS . ' days')) {
            return 'paused';
        }
        if ($now > $due) {
            return 'late';
        }

        return $now > $due->modify('-' . self::SOON_DAYS . ' days') ? 'due_soon' : 'on_time';
    }

    public function isPaused(DateTimeImmutable $now): bool
    {
        return $this->standing($now) === 'paused';
    }

    /** The date the course pauses if the next part is still unpaid. */
    public function pausesAt(): ?DateTimeImmutable
    {
        $next = $this->nextPart();
        $due = $next === null || $next === 1 ? null : $this->dueAt($next);

        return $due?->modify('+' . self::GRACE_DAYS . ' days');
    }

    /** @return array{0: string, 1: string} label and badge tone, for lists */
    public static function standingBadge(string $standing): array
    {
        return match ($standing) {
            'pending' => ['First part not paid', 'neutral'],
            'on_time' => ['On time', 'done'],
            'due_soon' => ['Part due soon', 'neutral'],
            'late' => ['Late', 'draft'],
            'paused' => ['Paused: overdue', 'danger'],
            'completed' => ['Paid in full', 'done'],
            default => ['Rest written off', 'neutral'],
        };
    }
}
