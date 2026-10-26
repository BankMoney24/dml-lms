<?php

declare(strict_types=1);

namespace App\Modules\Meetings;

/**
 * A video meeting in the school's online classroom that is not a class
 * (ADR 0030): a staff meeting, a parents' evening, a call with one family.
 * Only the host and the people invited can get in.
 */
final class Meeting
{
    public const JOIN_EARLY_MINUTES = 15;
    public const HOST_EARLY_MINUTES = 30;
    /** Lengths offered when planning or starting a meeting. */
    public const MINUTES = [15, 30, 45, 60, 90, 120, 180];

    public function __construct(
        public readonly int $id,
        public readonly int $tenantId,
        public readonly int $hostId,
        public readonly string $title,
        public readonly string $agenda,
        public readonly string $startsAt,
        public readonly string $endsAt,
        public readonly string $roomName,
        public readonly bool $staffModerate,
        public readonly string $status,
        public readonly ?string $cancelledReason,
        public readonly string $hostName = '',
        public readonly int $invited = 0,
    ) {
    }

    /** @param array<string, mixed> $row */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['tenant_id'],
            (int) $row['host_id'],
            (string) $row['title'],
            (string) ($row['agenda'] ?? ''),
            (string) $row['starts_at'],
            (string) $row['ends_at'],
            (string) $row['room_name'],
            (bool) $row['staff_moderate'],
            (string) $row['status'],
            isset($row['cancelled_reason']) ? (string) $row['cancelled_reason'] : null,
            (string) ($row['host_name'] ?? ''),
            (int) ($row['invited'] ?? 0),
        );
    }

    /** An unguessable room: knowing one meeting's room says nothing about another. */
    public static function newRoomName(): string
    {
        return 'dmlm-' . bin2hex(random_bytes(12));
    }

    public function isCancelled(): bool
    {
        return $this->status === 'cancelled';
    }

    /** before | open (joinable) | live | ended | cancelled */
    public function phase(int $now): string
    {
        if ($this->isCancelled()) {
            return 'cancelled';
        }
        if ($now >= $this->endTs()) {
            return 'ended';
        }
        if ($now >= $this->startTs()) {
            return 'live';
        }

        return $now >= $this->startTs() - self::JOIN_EARLY_MINUTES * 60 ? 'open' : 'before';
    }

    public function hasEnded(int $now): bool
    {
        return $now >= $this->endTs();
    }

    public function minutes(): int
    {
        return (int) round(($this->endTs() - $this->startTs()) / 60);
    }

    public function startTs(): int
    {
        return (int) strtotime($this->startsAt . ' UTC');
    }

    public function endTs(): int
    {
        return (int) strtotime($this->endsAt . ' UTC');
    }
}
